# Rqwatch

**Rqwatch** is a quarantine and map management system for [Rspamd](https://rspamd.com), written in PHP. 

It provides a web interface to monitor all incoming emails passing through your mail gateway,
accessible to both administrators and recipient users.

An API is available to insert data from Rspamd. This API is used by the Rspamd
[Metadata Exporter](https://docs.rspamd.com/modules/metadata_exporter) plugin to
export raw emails and Rspamd headers and store that metadata in a database.

Depending on the action taken by Rspamd and the configuration, raw emails can also be saved
in local quarantine storage to be examined and released by administrators or recipient users,
if desired.

Apart from quarantine monitoring, this system can also be used as a complete map management
and distribution point for Rspamd, independent of other functionalities.

Rqwatch is able to operate in two modes:
- Local mode (single-host), where the Web and API components run on the same host
as Rspamd.
- [Distributed](docs/DISTRIBUTED.md) mode, where the API servers run on the same
hosts as Rspamd, while the Web servers (public or private) run on separate hosts.\
  In this mode, the Web servers communicate with the corresponding API servers
  to retrieve mail data and release messages as needed.

In both modes, Rspamd communicates with Rqwatch API running on the same host,
introducing only minimal overhead.

<!-- [[_TOC_]] -->

<!--ts-->
* [Rqwatch](#rqwatch)
* [Features](#features)
   * [Architecture](#architecture)
   * [Components](#components)
   * [Web](#web)
      * [Mail Logs and Quarantine](#mail-logs-and-quarantine)
      * [Authentication](#authentication)
      * [Access Control](#access-control)
      * [Maps](#maps)
      * [Redis Caching](#redis-caching)
   * [API for Rspamd - RSPAMD API (metadata_importer)](#api-for-rspamd---rspamd-api-metadata_importer)
   * [Mail API](#mail-api)
   * [CLI](#cli)
* [<a href="docs/INSTALL.md">Installation</a>](docs/INSTALL.md)
* [<a href="docs/CONFIGURE.md">Configuration</a>](docs/CONFIGURE.md)
* [<a href="docs/SCREENSHOTS.md">Screenshots</a>](docs/SCREENSHOTS.md)
* [INFO](#info)
   * [Similar software](#similar-software)
   * [<a href="CHANGELOG.md">Changelog</a>](CHANGELOG.md)
   * [<a href="ROADMAP.md">Roadmap</a>](ROADMAP.md)
   * [<a href="NOTICE.md">Third-Party Software Notice</a>](NOTICE.md)
   * [<a href="LICENSE">License: Mozilla Public License Version 2.0</a>](LICENSE)
<!--te-->

# Features

## Architecture
- Custom MVC framework (+more than MVC)
- Router
- Authentication and Access Control middlewares per route
- Pretty URLs
- Can run either in Local mode (Web/API on same server), or [Distributed](docs/DISTRIBUTED.md)
mode (Web/API on different hosts).\
  Dedicated Mode, where Rqwatch runs separately than Rspamd could also work in theory,
however this setup has not been tested.
- Supports /subfolder in base URL
- Default config file + local override file

## Components
- Symfony components (http-foundation, router, form, mailer, console etc)
- Eloquent ORM/Query Builder for MySQL/MariaDB connection
- Twig Views
- Monolog Logging
- .env file for sensitive parameters (+ config options that are not cached)
- MaxMind GeoIP reader
- composer installation

## Web

### Mail Logs and Quarantine
All mail metadata received by Rqwatch metadata_importer API are stored in the database.

Depending on configuration, raw emails can also be stored in the local Quarantine
(on each API server).\
Web servers contact the corresponding API servers to retrieve
mail data and release messages as needed.

The following functionalities are supported:
- Search mails by filters
- Top reports
- Show mail metadata
- Show mail headers
- Show rspamd headers
- Show virus report
- Quick links for white/black lists

If mail is stored in Quarantine:
- Show raw mail (text/html)
- Show/save mail attachments
- Release mail (to original and/or alternate recipients)
- Show Quarantined mails per day

Admin users have full access. Users have limited access and only to their data.

### Authentication
- Basic authentication
- Database authentication
- LDAP authentication

### Access Control
- Admin users can read/release all emails and edit all maps
- Normal users can read/release only their emails (+ aliases) and edit their maps
- DB Admin users / LDAP Admin users
- /admin web endpoint for admins

### Maps
The Web interface provides map management and URL endpoints for rspamd
- Basic Maps with common fields of Rspamd multimap module (mail_from, rcpt_to, mime_from, ip)
- Combined Maps with two fields combination for custom lua plugins provided by Rqwatch
(mail_from/rcpt_to, mime_from/rcpt_to etc)
- Personal User Maps (Combined only) based on user rcpt_to address
- Custom Maps with a custom field. Can hold any type of field that Rspamd supports (asn, url, email, ip, text etc)
- Multi entry inserts
- Enable/Disable/Delete per entry per map

A GetMapApi was also initially implemented, however it's been disabled by default.\
Rspamd downloads map files locally from the web server and this does not put additional
stress on the database.

### Redis Caching
- Redis Session support (+ sentinel)
- Redis Config caching
- Redis Rspamd stats caching

## API for Rspamd - RSPAMD API (metadata_importer)
- Mail metadata and raw email (depending on configuration) is inserted to database and local storage, using the metadata_exporter plugin of Rspamd
- Dedicated Authentication and IP RSPAMD_API access list

## Mail API
The Mail API is used in [Distributed](docs/DISTRIBUTED.md) mode, where Web components connect to it.
- Release Mail via remote API
- Get Mail via remote API
- Web client validates remote API SSL/TLS
- Web client is authenticated to remote API
- Dedicated Authentication and IP access control list

## CLI
- Update map files if needed (cron)
- Mail notifications to users (cron)
- Quarantine cleanup (cron)
- User add

# [Installation](docs/INSTALL.md)

# [Configuration](docs/CONFIGURE.md)

# [Screenshots](docs/SCREENSHOTS.md)

# INFO

## Similar software
**Rqwatch** was initially inspired by
<a href="https://mailwatch.org" target="_blank">MailWatch</a>, which is used with
<a href="https://www.mailscanner.info" target="_blank">MailScanner</a>.\
Although we wanted a similar look and functionality
(since we have used it for many years),
it's a completely different project - a rewrite without any code from there.

<a href="https://mailcow.email/" target="_blank">mailcow</a> has a quarantine system; ideas taken from there too 
(<a href="https://github.com/mailcow/mailcow-dockerized/blob/master/data/conf/rspamd/local.d/metadata_exporter.conf" target="_blank">metadata_exporter</a>)\
<a href="https://github.com/sys4/rspamd-quarantine" target="_blank">rspamd-quarantine</a> (python - never used it)\
<a href="https://github.com/fedmik/rspamd-quarantine" target="_blank">rspamd-quarantine</a> (PHP - never used it)

## [Changelog](CHANGELOG.md)

## [Roadmap](ROADMAP.md)

## [Third-Party Software Notice](NOTICE.md)

## [License: Mozilla Public License Version 2.0](LICENSE)
