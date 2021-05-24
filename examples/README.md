# Example Application

[MyApp](MyApp), This is an example application developed using WinterBoot framework to demonstrate it's power.

It can be deployed in two ways

- With **Swoole** Extension (best and recommended)
- or, Apache or Nginx


### Code samples

- [Controllers](MyApp/src/controller)
- [Services](MyApp/src/service)
- [StereoTypes](MyApp/src/stereotype)
- [Config YML's](MyApp/config)


### 1. Deploying with Swoole

Install swoole extension

```shell
pecl install swoole
```

add **extension=swoole.so** to you **php.ini**



Run below command to start the application.

```shell
php ./MyApp/bin/server.php
```


Now, Test application using below curl commands

```

curl  http://127.0.0.1:8080/acme/health

curl  http://127.0.0.1:8080/acme/info

curl  http://127.0.0.1:8080/acme/mappings

curl  http://127.0.0.1:8080/calc/add -d "a=10&b=90"

```

Swoole server configuration is available under **application.yml** under **server** section (port, address, max workers etc ...).

for more info:

https://www.swoole.co.uk/docs/modules/swoole-server/configuration



### 2. Deployment with Apache

point  **"examples/MyApp/web"** directory as **DocumentRoot** in your httpd.conf

And test application using curl as mentioned above.

Running with Apache is slow, because every request reloads PHP scripts and also application configurations.


