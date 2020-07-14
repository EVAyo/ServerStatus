# ServerStatus 中文修改版

ServerStatus 中文修改版是一个酷炫高逼格的云探针、云监控、服务器云监控、多服务器探针~

## 演示效果

[打开演示站](https://eller.tech/tanzhen/index.html)

## 预览图

![U1y9Tx.png](https://s1.ax1x.com/2020/07/12/U1y9Tx.png)



# 文件介绍：


* server.php              服务端文件
* client-linux.py        客户端文件
* web                         网站文件  




# 版本说明

中文 fork 版，根据自己的需求，梳理了下服务端的逻辑，发现 C++ 不会写。遂改成了 PHP 版本，不过兼顾客户端部署方便，还是原来的 python.

将协议有 socket 更改为 websocket，可以将服务端隐藏在 CDN ( Cloudflare ) 后方，即使客户端被爆破也不会泄露服务端的 IP，毕竟服务端都是很脆弱的机器🙄.



## 服务端区别

- 协议更改为 websocket
- 动态读取 `config.json` 文件进行验证客户端权限，这样不用像以往修改完配置再重启服务端才生效。
- 一个URL同时生成服务端配置和客户端配置，不用登录服务端服务器也能新增节点。
- 新增世界地图显示服务器大致位置
- 移除 ipv6 判断，统一 ipv4，ipv6 的服务器可以通过 CDN 中转连接。

## 客户端区别

- 协议更改为 websocket
- 新增一堆 bug

## 配置文件说明

配置文件中以 `username` 作为唯一值认证，所以你需要保证你的用户名是唯一的。

使用一键客户端指令运行时，服务端也会根据客户端 IP 校验配置是否唯一。

# 安装

监控脚本分为服务端和客户端，服务端用来统计及展示各个客户端信息的，一般只能有一个。而客户端是你要被监控的服务器，可以有多个，每一台就是一个客户端。

## 服务端

服务端采用 PHP 语言，所以还需要 swoole 扩展的环境，如果没有，可以下载我的绿色包，解压即可能用。

[PHP + SWOOLE 绿色解压版](https://github.com/ellermister/ServerStatus/releases/download/7.4/php7.4-static7.tar.gz)

参考下面指令，只要将压缩包解压并设置软链接到系统环境变量所在目录即可。

```bash
wget https://github.com/ellermister/ServerStatus/releases/download/7.4/php7.4-static10.tar.gz
tar -xzf php7.4-static10.tar.gz
mkdir /usr/local/php && mv php7.4 /usr/local/php
ln /usr/local/php/php7.4/bin/php /usr/bin/php
```

（可能部分系统还有问题，如果不成功建议通过 docker 安装 swoole)

下载源码并解压：

```bash
wget https://github.com/ellermister/ServerStatus/archive/master.zip
unzip master.zip
```

运行：

```bash
php server.php
```

后台运行：

```bash
nohup php server.php > /dev/null 2>&1 &
```

默认服务端监听 35601 端口以进行处理请求，建议通过 nginx 反向代理至服务端。

### Nginx

如若需要通过 nginx 反向代理，这是配置选段参考。

此配置适用于百度云 CDN 以及 CloudFlare ，主要是将真实IP设置至 `X-Real-IP` 字段即可。

```config
location / {
        proxy_redirect off;
        proxy_pass http://127.0.0.1:35602/;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $http_host;

        proxy_set_header X-Real-IP $http_x_forwarded_for;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}
```

### Token

用于接口交互的 `token`存储于服务端 `server.php` 所处的目录的 `.token` 中。

```bash
$ cat .token
2e311fc799c47bdd1578a41111397b2a
```

## 客户端

### 依赖

由于将原本的 `socket` 传输修改为 `websocket`，这里比以前还需要多安装一个 `websocket` 客户端。

```
pip install websocket_client
```

如若为纯净版系统，还需安装 pip (python 的包管理) 否则会提示 pip 命令不存在。

#### CentOS/Redhat

```bash
yum install -y python-pip
```

#### Debian/Ubuntu

```bash
apt install -y python-pip
```

### 运行

你可以通过编辑 `client-linux.py` 文件，并修改其中的 `SERVER(接口地址)`、`USER(用户名)`、`PASSWORD(验证密码)` 以保证客户端能和服务端正常连接。

前台调试运行：

```
python client-linux.py
```

后台运行：

```
 nohup python client-linux.py >/dev/null 2>&1 &
```

我们在此次改版中增加了一键部署客户端以方便快速部署。无需登录服务端再修改配置文件，也无需再重启服务。

请将其中的 `{1.1.1.1}`修改为你的域名, 将 `{token}` 修改为你的 token 以保证管理端不被滥用，请避免 token 泄露。

```bash
wget 'https://{1.1.1.1}/api/client-linux.py?token={token}'
```

上述命令比较精简，如果想自行指定服务器的一些信息给到服务端，可以这样运行。

```bash
wget 'http://{1.1.1.1}/api/client-linux.py?token={token}&type=kvm&name=HKVPS&location=HongKong' -O 'lient-linux.py'
```

完整示例：

```bash
wget 'http://192.168.75.132:35601/api/client-linux.py?token=db17150af7885e987d8bcdb791d7a824&type=kvm&name=HKVPS&location=HongKong' -O 'client-linux.py'
```

这样即可以得到一个自动填上配置信息的客户端文件，而且服务端也已经保存了对应信息，接下来只需要直接运行客户端脚本即可。

指令：

```
python client-linux.py
```

如果没有报错，你就可以按照前面的办法，将脚本放到后台进程中运行。

### 清理历史

清除命令历史，避免被人从历史中发现 `token` 的踪迹。

```
history -c
```

# 最后

🙄 最后祝各位买机愉快~~

🐛BUG反馈：<https://t.me/EllerCN>
