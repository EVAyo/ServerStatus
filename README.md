# ServerStatus中文修改版：   

* ServerStatus中文修改版是一个酷炫高逼格的云探针、云监控、服务器云监控、多服务器探针~




![UMqhPH.png](https://s1.ax1x.com/2020/07/11/UMqhPH.png)

# 文件介绍：


* server.php              服务端文件
* client-linux.py        客户端文件
* web                         网站文件  




# 版本说明

fork 中文版，根据自己的需求，梳理了下服务端的逻辑，发现 C++ 不会写。遂改成了 PHP 版本，不过兼顾客户端部署方便，还是原来的 python.

将协议有 socket 更改为 websocket，可以将服务端隐藏在 CDN ( Cloudflare ) 后方，即使客户端被爆破也不会泄露服务端的 IP，毕竟服务端都是很脆弱的机器🙄.



## 服务端区别

- 协议更改为 websocket
- 动态读取 `config.json` 文件进行验证客户端权限，这样不用像以往修改完配置再重启服务端才生效。
- 一个URL同时生成服务端配置和客户端配置，不用登录服务端服务器也能新增节点。
- 新增世界地图显示服务器大致位置
- 移除 ipv6 判断，统一 ipv4，ipv6 的服务器可以通过 CDN 中转连接。



## 客户端区别

- 协议更改为 websocket
- 新增一堆bug



## 配置文件说明

配置文件中以 `username` 作为唯一值认证，所以你需要保证你的用户名是唯一的。

使用一键客户端指令运行时，服务端也会根据客户端 IP 校验配置是否唯一。



# 安装

这个监控脚本分为服务端和客户端，服务端就是用来统计及展示各个客户端信息的，一般只能有一个。而客户端是你要被监控的服务器，可以有多个，每一台就是一个客户端。



## 服务端

服务端采用 PHP 语言，所以还需要 swoole 扩展的环境，如果没有你可以下载我的绿色包，解压就能用。

[PHP + SWOOLE 绿色解压版 (待更新)](#)

运行：

```bash
nohup php server.php
```

后台运行：

```bash
nohup php server.php > /dev/null 2>&1 &
```

默认服务端会监听一个 35601 端口进行处理请求，建议你通过 nginx 反向代理到服务端。

### Nginx

如果你通过nginx反向代理，这是给予的配置选段参考。

这里配置适用于百度云 CDN，当然 CloudFlare 也应该是可以的，主要将真实IP设置到 `X-Real-IP` 字段即可。

```bash
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

用于接口交互的 `token`，在服务端 `server.php` 文件所处的目录下，存储在 `.token` 里面。

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

如果你是纯净版系统，可能还需要安装 pip (python 的包管理) 否则会提示 pip 命令不存在。

#### CentOS/Redhat

```bash
yum install -y python-pip
```

#### Debian/Ubuntu

```bash
apt install -y python-pip
```



### 运行

你可以通过编辑 `client-linux.py` 文件，并修改其中的`SERVER(接口地址)`、`USER(用户名)`、`PASSWORD(验证密码)`来保证客户端能和服务端正常连接。

前台调试运行：

```
python client-linux.py
```

后台运行：

```
 nohup python client-linux.py >/dev/null 2>&1 &
```

为了快速部署，我们在此次改版中增加了一键部署客户端，无需再登录服务端修改配置文件，也无需再重启服务。

记得将其中的`{1.1.1.1}`修改为你自己的域名, 将`{token}`修改为自己的 token，为保证你的管理端不被滥用，请不要随意泄露 token.

```bash
wget 'https://{1.1.1.1}/api/client-linux.py?token={token}'
```

上面命令比较精简，如果你想自己指定服务器的一些信息给到服务端，你可以这样运行。

```bash
wget 'http://{1.1.1.1}/api/client-linux.py?token={token}&type=kvm&name=HKVPS&location=HongKong' -O 'lient-linux.py'
```

完整示例：

```bash
wget 'http://192.168.75.132:35601/api/client-linux.py?token=db17150af7885e987d8bcdb791d7a824&type=kvm&name=HKVPS&location=HongKong' -O 'client-linux.py'
```

这样你就可以得到一个自动填上配置信息的客户端文件了，而且服务端也已经保存了对应信息，接下来只需要直接运行客户端脚本即可。

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

🐛BUG反馈：https://t.me/EllerCN