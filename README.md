# Rqwatch

Rqwatch is a quarantine monitoring and watch system for [Rspamd](https://rspamd.com), written in PHP. 

It provides a web framework to monitor all incoming emails passing through your mail gateway, accessible by both administrators and recipient users.

An API is available to insert data from Rspamd. This API is used by the Rspamd [Metadata Exporter plugin](https://docs.rspamd.com/modules/metadata_exporter) to export headers (both mail and Rspamd headers) and store that metadata in a database.

Depending on the action taken by Rspamd and the configuration, raw emails can also be saved in a local quarantine storage to be examined and released by administrators or recipient users, if desired.

Apart from quarantine monitoring, this system can also be used as a map management/provider for Rspamd, independently of other functionalities.

[[_TOC_]]

# Features

## Architecture
- Custom MVC framework (+more than MVC)
- Router
- Auth and Access control Middlewares per route
- Pretty Urls
- Can run either in Local mode (Web/API on same server), or Distributed setup (Web servers/API servers) on different hosts
- Supports /subfolder in base Url
- Default config file + local override file

## Components
- Symfony components (http-foundation, router, form, mailer, console etc.)
- Eloquent ORM
- Twig Views
- Monolog Logging
- .env file for sensitive parameters (+ common config options)
- Maxmind GeoIP reader
- composer installation

[Third-Party Software NOTICE](NOTICE.md)

## Web

### Authentication
- Basic Authentication
- Database Authentication
- LDAP Authentication

### Access Control
- Admin users can read/release all emails
- Normal users can read/release only their emails (+ aliases)
- DB Admin users / LDAP Admin users
- /admin web endpoint for admins

### Maps
The Web interface provides map management and url endpoints for rspamd
- Basic Maps with common fields of multimap module (mail_from, rcpt_to, mime_from, ip)
- Combined Maps with two fields for custom lua module (mail_from/rcpt_to, mime_from/mime_to)
- Generic Maps for other fields of multimap module (asn, url, domain etc).
- Custom Maps with a custom field

The map subsystem can be easily extended to support additional types.

### Redis Caching
- Redis Session support (+ sentinel)
- Redis Config caching
- Redis Rspamd stats caching

## API for Rspamd - RSPAMD API (metadata_importer)
- Mail metadata and raw mail (depending on configuration) is inserted to database and local storage, using the metadata_exporter plugin of Rspamd
- Dedicated Auth and IP RSPAMD_API access list

## WEB API [Distributed mode]
- Release Mail via remote API
- Get Mail via remote API
- Web client validates remote API SSL/TLS
- Web client is authenticated to remote API (BasicAuth)
- Dedicated Auth and IP WEB_API access list

## CLI
- Update map files if needed (cron)
- Mail Notifications to users (cron)
- Quarantine cleanup (cron)

# Setup
Development and production environments are primarily tested on Rocky Linux 9 (RHEL 9), using PHP 8.3 and MariaDB 10.5.\
However, Rqwatch should run on any system with minor adjustments, as no exotic components are required.

## Requirements

**PHP 8.3** with

- php-mysqlnd

- php-pdo

- php-pecl-mailparse

- php-mbstring

- php-xml

- php-ldap

- php-pecl-redis6

- php-gmp (required only if GeoIP support is enabled)

**Rspamd 3.12**

## Installation

```
composer check-platform-reqs
composer install
```
`edit .env`\
...

## Configuration

# INFO

## Similar software
Rqwatch was initially inspired by [MailWatch](https://mailwatch.org) which is used for [MailScanner](https://www.mailscanner.info).

[mailcow](https://mailcow.email/) has a quarantine system. Ideas taken from there too ([metadata exporter](https://github.com/mailcow/mailcow-dockerized/blob/master/data/conf/rspamd/local.d/metadata_exporter.conf)).  
[rspamd-quarantine](https://github.com/sys4/rspamd-quarantine) (python)  
[rspamd-quarantine](https://github.com/fedmik/rspamd-quarantine) (PHP)

## [Changelog](CHANGELOG.md)

## [Roadmap](ROADMAP.md)

## [License: Mozilla Public License Version 2.0](LICENSE)
