# Installation

<!--ts-->
* [Installation](#installation)
   * [Setup](#setup)
   * [Requirements](#requirements)
   * [Installation](#installation-1)
      * [PHP](#php)
      * [Users and groups](#users-and-groups)
      * [Download and setup directories](#download-and-setup-directories)
      * [Rqwatch Installation](#rqwatch-installation)
      * [PHP-FPM](#php-fpm)
      * [Web Server](#web-server)
      * [Database Server](#database-server)
      * [Database creation](#database-creation)
* [<a href="CONFIGURE.md">Configuration</a>](CONFIGURE.md)
<!--te-->

## Setup
Development and production environments are primarily tested on Rocky Linux 9 (RHEL 9), using PHP 8.3, MariaDB 10.5 (or later) and Valkey 8 (Redis should be fine too).\
However, Rqwatch should run on any system with minor adjustments, as it requires no exotic components.

## Requirements

**PHP 8.3** with:

- php-fpm

- php-cli

- php-mysqlnd

- php-pdo

- php-pecl-mailparse

- php-mbstring

- php-xml

- php-ldap

- php-pecl-redis6

- php-gmp (required only when GeoIP support is enabled)

- composer

- git

**Rspamd 3.12**

## Installation
All commands below are run as root/sudo unless told otherwise.

### PHP
Enable EPEL and CodeReady Linux Builder repositories:
```
dnf install epel-release
dnf config-manager --set-enabled crb
```

EL9 PHP8.3 has a problem with `php-pecl-mailparse`, which is required for mail parsing.
For this reason, we use PHP from [Remi's RPM Repository](https://blog.remirepo.net/pages/Config-en).

```
dnf install https://rpms.remirepo.net/enterprise/remi-release-9.rpm
dnf makecache
dnf module enable php:remi-8.3

dnf install php-fpm php-cli php-mysqlnd php-pdo \
php-pecl-mailparse php-mbstring php-xml \
php-ldap php-pecl-redis6 php-gmp composer git
```

### Users and groups
Create a group and a user for Rqwatch:
```
groupadd -r rqwatch
useradd -r -m -s /sbin/nologin -c "Rqwatch" -g rqwatch rqwatch
```

Create a group shared by Rqwatch and the web server:
```
groupadd -r rqwatch_web
usermod -G rqwatch_web rqwatch
usermod -G rqwatch_web apache
```

### Download and setup directories
```
cd /var/www/html
git clone https://github.com/bilias/rqwatch/

chmod 750 /var/www/html/rqwatch
chown -R root:root /var/www/html/rqwatch
chown rqwatch:rqwatch_web /var/www/html/rqwatch /var/www/html/rqwatch/web

cd rqwatch/

chown -R rqwatch:rqwatch logs web/maps

cp .env-example .env
```

I prefer root user as the owner for (sensitive) config files.\
rqwatch user can only read there and not make modifications.
```
chown root:rqwatch .env config/config.*
chmod 640 .env config/config.*
```

### Rqwatch Installation
```
su - rqwatch -s /bin/bash
[rqwatch]$ cd /var/www/html/rqwatch/

[rqwatch]$ git config --global --add safe.directory /var/www/html/rqwatch

[rqwatch]$ composer install

[rqwatch]$ composer check-platform-reqs

[rqwatch]$ composer dump-autoload -o # optional
```

### PHP-FPM
```
cd /etc/php-fpm.d

cat <<EOF > rqwatch.conf
[rqwatch]
user = rqwatch
group = rqwatch
listen = /run/php-fpm/rqwatch.sock
listen.acl_users = apache,nginx
listen.allowed_clients = 127.0.0.1
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
slowlog = /var/www/html/rqwatch/logs/php-slow.log
request_slowlog_timeout = 3s
php_admin_value[error_log] = /var/www/html/rqwatch/logs/php-error.log
php_admin_flag[log_errors] = on
php_value[session.save_handler] = files
php_value[session.save_path]    = /var/www/html/rqwatch/fpm/session
php_admin_flag[expose_php] = off
; adjust this depending on the maximum allowed mail size
; see postfix mailbox_size_limit/message_size_limit
php_value[post_max_size] = 64M
php_value[upload_max_filesize] = 64M
php_value[date.timezone]  = Europe/Athens
EOF
```
Modify `date.timezone` to match your local [timezone](https://www.php.net/manual/en/timezones.php).

```
mkdir -p /var/www/html/rqwatch/fpm/session
chown -R rqwatch:rqwatch /var/www/html/rqwatch/fpm
chmod 700 /var/www/html/rqwatch/fpm

systemctl enable --now php-fpm
```

### Web Server
```
dnf install httpd mod_ssl

cp /var/www/html/rqwatch/contrib/apache-rqwatch.conf /etc/httpd/conf.d/rqwatch.conf
```
Edit `/etc/httpd/conf.d/rqwatch.conf` and define allowed IPs for `/api`.\
If using Local mode (single-host) setup, then only `127.0.0.1` should be able to connect.\
If in Distributed mode, both `127.0.0.1` and Rqwatch Web Servers must be able to connect to the servers running the API (Rspamd servers).

```
systemctl enable --now httpd
```

### Database Server
For Distributed mode, Rqwatch connects to a remote MySQL/MariaDB server accessible by all Rqwatch servers. Galera Cluster is also supported.\
Only MariaDB client is needed:
```
dnf install mariadb
```

For Local mode (single-host) setup:
```
dnf install mariadb-server
systemctl enable --now mariadb
mariadb-secure-installation
```
If you are running everything on a single host, you probably need to define `bind-address=127.0.0.1` in `[mysqld]` for MySQL/MariaDB.

### Database creation
```
mysql -p -u root

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `rqwatch` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;

GRANT ALL on rqwatch.* to rqwatch@localhost identified by '1vkUSBLfQlQcJFCmPw5TpkDOpmNpm5LqdziZSMQB8sNJPXKgEcIgvLZ15IZSb7fB';
```
Don't use this dummy password, and don't forget to **update DB_ details in .env**

Initialize the database (this will also drop all tables if they were previously created):
```
mysql -p < contrib/db-init.sql
```

# [Configuration](CONFIGURE.md)
