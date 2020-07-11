<?php
define('SERVER_IP', '0.0.0.0');
define('SERVER_PORT', 35602);
define('CONFIG_FILE', 'config.json');
define('API_URL', 'ws://{host}/v2/');

if (!is_file('.token')) file_put_contents('.token', md5(uniqid(microtime(true), true)));
$token = file_get_contents('.token');
define('TOKEN', $token);

use Swoole\Process;

class  Server
{

    /**
     * @var \Swoole\Table
     */
    protected $table;

    /**
     * @var \Swoole\Table
     */
    protected $users;

    protected $tmp; //存放单个服务器的状态结果
    protected $cache;//存放IP解析经纬度的结果
    protected $web;// web目录

    protected $filterIP = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
        '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32'
    ];

    public function __construct()
    {
        $this->tmp = __DIR__ . DIRECTORY_SEPARATOR . 'tmp';
        $this->cache = __DIR__ . DIRECTORY_SEPARATOR . 'cache';
        if (!is_dir($this->tmp)) {
            @mkdir($this->tmp);
        }
        if (!is_dir($this->cache)) {
            @mkdir($this->cache);
        }
        $this->web = __DIR__ . DIRECTORY_SEPARATOR . 'web';
    }


    protected function createTable()
    {
        $table = new Swoole\Table(1024); // key作fd
        $table->column('fd', Swoole\Table::TYPE_INT, 4);       //1,2,4,8
        $table->column('state', Swoole\Table::TYPE_INT, 4); // 存储当前链接认证状态
        $table->column('username', Swoole\Table::TYPE_STRING, 64); // 存储当前链接对应的服务器username
        $table->create();
        $this->table = $table;

        $users = new Swoole\Table(1024);// key作服务器username(ID)
        $users->column('net_id', Swoole\Table::TYPE_INT, 4); // 存储当前服务器对应的链接ID
        $users->column('username', Swoole\Table::TYPE_STRING, 64);// c
        $users->create();
        $this->users = $users;
    }

    protected function debug($from, $text)
    {
        echo sprintf("[$from]: $text\n");
    }

    public function start($host, $port)
    {
        $this->createTable();

        $server = new Swoole\WebSocket\Server($host, $port);
//        $server->set(array('task_worker_num' => 4));
        $this->debug("server", sprintf("Bound to %s:%d", $host, $port));

        $server->on('open', function (Swoole\WebSocket\Server $server, $request) {
            $this->debug('server', "handshake success with fd{$request->fd}");
            $this->debug('server', 'send --> Authentication required:');
            $server->push($request->fd, 'Authentication required:');
            $this->table->set($request->fd, ['fd' => $request->fd, 'state' => 0]);
        });

        $server->on('message', function (Swoole\WebSocket\Server $server, $frame) {
            $configRaw = file_get_contents(CONFIG_FILE);
            $clients = json_decode($configRaw, true);
            if ($clients == null) {
                $this->debug('server', 'send --> Server Config valid!.');
                $server->push($frame->fd, "Server Config valid!.");
                $server->disconnect($frame->fd, 1000, 'Fuck off.');
                return;
            }
            $clients = $clients['servers'];
            $frame->data = rtrim($frame->data, "\n");
            $this->debug('server', "receive <-- {$frame->data}");

            if (false === ($client = $this->table->get($frame->fd))) {
                $this->debug('server', 'send --> Fuck off.');
                $server->disconnect($frame->fd, 1000, 'Fuck off.');
                return;
            }
            if ($client['state'] === 0) {
                if (false === strpos($frame->data, ':')) {
                    $this->debug('server', 'send --> You\'re an idiot, go away.');
                    $server->push($frame->fd, "You're an idiot, go away.");
                    $server->disconnect($frame->fd, 1000, 'Fuck off.');
                    return;
                }
                $buffer = explode(':', $frame->data);
                $username = $buffer[0];
                $password = $buffer[1];
                if (empty($username) || empty($password)) {
                    $this->debug('server', 'send --> You\'re an idiot, go away.');
                    $server->push($frame->fd, "You're an idiot, go away.");
                    $server->disconnect($frame->fd, 1000, "Username and password must not be blank.");
                    return;
                }

                $serverId = null;
                foreach ($clients as $key => $_client) {
//                    echo "{$_client['username']} == $username && {$_client['password']} == $password\n";
                    if ($_client['username'] == $username && $_client['password'] == $password) {
                        $serverId = $_client['username'];
                    }
                }
                $netId = -1;
                if ($serverId !== null) {
                    $user = $this->users->get($serverId);
                    $netId = $user ? ($user['net_id'] ?: -1) : -1;
                }

                if ($serverId === null) {
                    $this->debug('server', 'send --> Wrong username and/or password.');
                    $server->push($frame->fd, "Wrong username and/or password.");
                    $server->disconnect($frame->fd, 1000, "Wrong username and/or password.");
                } else if ($netId != -1) {
                    $this->debug('server', 'send --> Only one connection per user allowed.');
                    $server->disconnect($frame->fd, 1000, "Only one connection per user allowed.");
                } else {
                    $this->debug('server', 'send --> Authentication successful. Access granted.');

                    $client['state'] = 1;
                    $client['username'] = $serverId;
                    $this->table->set($client['fd'], $client);
                    $user = $this->users->get($serverId);
                    $user['net_id'] = $client['fd'];
                    $this->users->set($serverId, $user);
                    $server->push($frame->fd, "Authentication successful. Access granted.");

                    //不区分ipv4/ipv6
                    $server->push($frame->fd, "You are connecting via: IPv4");
                }
            } else if ($client['state'] == 1) {
                if (strpos($frame->data, 'logout') !== false) {
                    $this->debug('server', 'send --> Logout. Bye Bye ~');
                    $server->disconnect($frame->fd, 1000, "Logout. Bye Bye ~");
                } else {
                    // main loop
                    if (strpos($frame->data, 'update') === 0) {
                        $jsonText = substr($frame->data, strlen('update') + 1);
                        $jsonArr = json_decode($jsonText, true);
                        $writeFile = $this->tmp . DIRECTORY_SEPARATOR . $client['username'] . '.json';
                        if (is_file($writeFile)) {
                            $oldJson = json_decode(file_get_contents($writeFile), true);
                            if ($oldJson) {
                                $jsonArr = array_merge($oldJson, $jsonArr);
                            }
                        }
                        $jsonArr['online4'] = true;
                        !isset($jsonArr['online6']) && $jsonArr['online6'] = false;
                        file_put_contents($writeFile, json_encode($jsonArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
//                        $task_id = $server->task($client['username']);
                    }
                }
            }
        });

        $server->on('request', function (\Swoole\Http\Request $request, \Swoole\Http\Response $response) {
            $response->header('Content-type', 'text/plain; charset=utf-8');
            $html = "No Access";
            if ($request->server['request_uri'] == '/api/client-linux.py') {
                if (trim($request->get['token']) === trim(TOKEN)) {
                    $api = str_replace('{host}', $request->header['host'], API_URL);;

                    $clientIp = $request->server['remote_addr'];
                    $clientIp = isset($request->header['x-real-ip']) ? $request->header['x-real-ip'] : $clientIp;
                    if ($server = $this->findServer('host', $clientIp)) {
                        $username = $server['username'];
                        $password = $server['password'];
                    } else {
                        $username = "AS" . strval($this->serverCount() + 1);
                        $password = md5(uniqid(microtime(true), true));
                    }

                    try {
                        $this->saveServer(array_merge([
                            'host'     => $clientIp,
                            'username' => $username,
                            'password' => $password
                        ], $this->array_only($request->get, ['host', 'username', 'password', 'type', 'name', 'location'])));
                    } catch (Exception $exception) {
                        return $response->end("#-*- coding: UTF-8 -*-\n" . 'print ("' . $exception->getMessage() . '")');
                    }

                    $text = file_get_contents('./client-linux.py');
                    $text = preg_replace('/(SERVER)\s*=\s*"([^"]+)"/is', '$1 = "' . $api . '"', $text);
                    $text = preg_replace('/(USER)\s*=\s*"([^"]+)"/is', '$1 = "' . $username . '"', $text);
                    $text = preg_replace('/(PASSWORD)\s*=\s*"([^"]+)"/is', '$1 = "' . $password . '"', $text);
                    $html = $text;
                }
            }
            $response->end($html);
        });


        $server->on('close', function ($ser, $fd) {
            $connect = $ser->connection_info($fd);
            if ($connect['websocket_status'] > 0) {
                $this->debug('server', "fd: $fd closed");
                $session = $this->table->get($fd);
                $serverId = $session['username'];
                $this->table->del($fd);
                $this->users->del($serverId);
            }
        });

        $server->set([
            'document_root'         => $this->web,
            'enable_static_handler' => true,
        ]);

        for ($n = 1; $n <= 2; $n++) {
            $process = new Process(function () use ($n, $server) {
                if ($n == 1) {
                    $server->start();
                } else {
                    while (1) {
                        $this->flushJson();
                        sleep(1);
                    }
                }
            });
            $process->start();
        }
        for ($n = 3; $n--;) {
            $status = Process::wait(true);
            echo "Recycled #{$status['pid']}, code={$status['code']}, signal={$status['signal']}" . PHP_EOL;
        }

    }

    /**
     * 获取数组中需要的元素
     *
     * @param array $arr
     * @param array $only
     * @return array
     */
    protected function array_only(array $arr, array $only)
    {
        $ret = [];
        foreach ($only as $name) {
            if (isset($arr[$name])) {
                $ret[$name] = $arr[$name];
            }
        }
        return $ret;
    }

    /**
     * 根据配置文件中某个值判断服务器是否存在
     *
     * @param $name
     * @param $value
     * @return bool
     */
    protected function findServer($name, $value)
    {
        $configs = file_get_contents(CONFIG_FILE);
        $users = json_decode($configs, true);
        unset($configs);
        if ($users != null) {
            foreach ($users['servers'] as $server) {
                if (isset($server[$name]) && $server[$name] == $value) {
                    return $server;
                }
            }
        }
        return false;
    }

    /**
     * 获取服务器总数
     *
     * @return int
     */
    protected function serverCount()
    {
        $configs = file_get_contents(CONFIG_FILE);
        $users = json_decode($configs, true);
        unset($configs);
        if ($users != null) {
            return count($users['servers']);
        }
        return 0;
    }


    /**
     * 保存/新增服务器到配置文件
     *
     * @param array $config
     * @return array
     * @throws Exception
     */
    protected function saveServer(array $config)
    {
        $default = [
            'username' => '',
            'name'     => '',
            'type'     => 'kvm',
            'host'     => '',
            'location' => '',
            'password' => '',
        ];
        $config = array_merge($default, $config);
        if (empty($config['host'])) throw new Exception(CONFIG_FILE . "参数 host 不能为空.");
        $api = sprintf("http://ip-api.com/json/%s?lang=zh-CN", trim($config['host']));
        $ipInfo = file_get_contents($api);
        $ipInfo = json_decode($ipInfo, true);
        if (!isset($config['location']) && $ipInfo && isset($ipInfo['country'])) {
            $config['location'] = $ipInfo['country'];
        }
        if (!isset($config['name']) && $ipInfo && isset($ipInfo['country'])) {
            $config['name'] = $ipInfo['country'] . ' ' . $ipInfo['city'];
        }

        $configs = file_get_contents(CONFIG_FILE);
        $users = json_decode($configs, true);
        unset($configs);
        if ($users == null) throw new Exception(CONFIG_FILE . "配置文件无法解析出 JSON.");
        $isMatch = false;
        $usernameDuplicate = false;
        foreach ($users['servers'] as &$server) {
            if (isset($server['username']) && $server['username'] == $config['username']) {
                $usernameDuplicate = true;
            }
            if (isset($server['host']) && $server['host'] == $config['host']) {
                $server = array_merge($server, $config);
                $isMatch = true;
                break;
            }
        }
        // 如果是新增用户而且用户名相同则拒绝
        if (!$isMatch && $usernameDuplicate) throw new Exception("用户名已经存在, 请修改!");
        if ($isMatch === false) {
            $users['servers'][] = $config;
        }
        file_put_contents(CONFIG_FILE, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $config;
    }

    protected function format(array &$json)
    {
        $days = intval($json['uptime']) / 86400;
        if ($days > 0) {
            $json['uptime'] = sprintf("%d 天", $days);;
        } else {
            $json['uptime'] = sprintf("%02d:%02d:%02d", intval($json['uptime']) / 60 / 60, (intval($json['uptime']) / 60) % 60, intval($json['uptime']) % 60);
        }

        if (!$user = $this->users->get($json['username'])) {
            $json['online4'] = $json['online4'] = $json['ip_status'] = false;
        }
    }

    protected function flushJson()
    {
        $configRaw = file_get_contents(CONFIG_FILE);
        $clients = json_decode($configRaw, true);
        if ($clients == null) {
            return;
        }
        $clients = $clients['servers'];
        $files = scandir($this->tmp . DIRECTORY_SEPARATOR);
        $output = ['servers' => []];
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            $username = basename($file, '.json');
            $jsonText = file_get_contents($this->tmp . DIRECTORY_SEPARATOR . $file);
            $json = json_decode($jsonText, true);

            if ($json !== null) {
                foreach ($clients as $client) {
                    if ($client['username'] == $username) {
                        $json = array_merge($json, $client);
                        break;
                    }
                }
                $json['lat'] = $json['lon'] = null;
                $json['connected_xy'] = [];
                if (isset($json['connected_ip']) && is_array($json['connected_ip'])) {
                    foreach ($json['connected_ip'] as $_ip) {
                        $ipInfo = $this->getIpInfo($_ip);

                        if (is_array($ipInfo) && isset($ipInfo['lat'])) {
                            $xy = 'xy_'.$ipInfo['lat'].'_'.ipInfo['lon'];
                            $json['connected_xy'][$xy] = [
                                'lat' => $ipInfo['lat'],
                                'lon' => $ipInfo['lon'],
                            ];
                        }
                    }
                }
                ksort($json['connected_xy']);
                $ip = $json['host'];
                $ipInfo = $this->getIpInfo($ip);
                if (!empty($ipInfo['lat']) && !empty($ipInfo['lon'])) {
                    $json['lat'] = $ipInfo['lat'];
                    $json['lon'] = $ipInfo['lon'];
                }
                $this->format($json);
                unset($json['connected_ip']);
                unset($json['password']);
                unset($json['host']);
                unset($json['username']);
                $output['servers'][] = $json;
            }
        }
        file_put_contents($this->web . '/json/stats.json', json_encode($output, JSON_UNESCAPED_UNICODE));

        //返回任务执行的结果
    }

    /**
     * 判断是否是过滤IP
     *
     * @param $ip
     * @return bool|int
     */
    protected function isFilterIP($ip)
    {
        $ipInt = ip2long($ip);
        foreach ($this->filterIP as $ipSegment) {
            list($ipBegin, $type) = explode('/', $ipSegment);
            $ipBegin = ip2long($ipBegin);
            $mask = 0xFFFFFFFF << (32 - intval($type));
            if (intval($ipInt & $mask) == intval($ipBegin & $mask)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取IP信息
     *
     * @param $ip
     * @return bool|false|mixed|string
     */
    protected function getIpInfo($ip)
    {
        if ($this->isFilterIP($ip)) {
            return false;
        }
        $ipCachePath = $this->cache . DIRECTORY_SEPARATOR . $ip;
        if (is_file($ipCachePath)) {
            $ipInfo = file_get_contents($ipCachePath);
            $ipInfo = json_decode($ipInfo, true);
        } else {
            $api = sprintf("http://ip-api.com/json/%s?lang=zh-CN", trim($ip));
            $opts = array(
                'http' => array(
                    'method'  => "GET",
                    'timeout' => 3,
                )
            );
            $ipInfo = file_get_contents($api, false, stream_context_create($opts));
            $ipInfo = json_decode($ipInfo, true);
            if ($ipInfo && isset($ipInfo['lon']) && isset($ipInfo['lat'])) {
                file_put_contents($ipCachePath, json_encode($ipInfo));
            }
        }
        return $ipInfo ? $ipInfo : false;
    }

}

(new Server())->start(SERVER_IP, SERVER_PORT);