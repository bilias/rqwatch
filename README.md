# Rqwatch

Rqwatch is quarantine monitoring/watch system for [Rspamd](https://rspamd.com), written in PHP.

A web framework is provided to monitor all incoming mails passing your mail gateway either as admin or as the recipient user.

An API also exists to insert data from Rspamd.
The API is being used by a plugin of rspamd ([Metadata exporter](https://docs.rspamd.com/modules/metadata_exporter)) in order to export raw e-mail and headers (both mail and rspamd headers) and store that metadata in a database.

Depending on the action taken by rspamd and the configuration, raw e-mails can be saved in local quarantine storage, in order to be examined and released by admins or recipient user if it is required.

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

## Web

### Authentication
- Basic Authentication
- Database Authentication
- LDAP Authentication

### Access Control
- Admin users can read/release all e-mail
- Normal users can read/release only their e-mails (+ aliases)
- DB Admin users / LDAP Admin users
- /admin web endpoint for admins

### Maps
The Web interface provides map management and url endpoints for rspamd
- Basic Maps with common fields of multimap module (mail_from, rcpt_to, mime_from,ip)
- Combined Maps with two fields for custom lua module (mail_from/rcpt_to, mime_from/mime_to)
- Generic Maps for other fields of multimap module (asn, url, domain etc). Easily extendable to support other types

### Redis Caching
- Redis Session support (+ sentinel)
- Redis Config Caching

## API for Rspamd - RSPAMD API (metadata_importer)
- Mail metadata and raw mail (depending on configuration) in injected to database and local storage, using the metadata_exporter plugin of Rspamd
- Dedicated Auth and IP RSPAMD API access list

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
Developement as well as production is done on Rocky Linux 9 (RHEL 9) using PHP 8.3 and MariaDB 10.5.\
Having said that, Rqwatch should run fine on any system with minor adjustments as there is no exotic component involved.

## Requirements

**PHP 8.3** with

- php-mysqlnd

- php-pdo

- php-pecl-mailparse

- php-mbstring

- php-ldap

- php-pecl-redis6

- php-gmp (if geoip is required)

**rspamd 3.12**

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

## [Third-Party Software](NOTICE.md)

## License

[GNU AGPL v3](LICENSE.md)
