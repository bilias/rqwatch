# Configuration
Rqwatch uses the following files for its configuration:
- `.env` for sensitive data, various connection details and generally configuration options that are not cached.
- `config.php` and `config.local.php` inside `config/` directory for the rest of configuration.\
`config.php` is tracked by git and **you should NOT make any changes there as they will be lost on update**.\
`config.local.php` is not tracked by git and you can use it to override any defaults found in `config.php`.

All passwords provided are dummy entries and **should not be used**, as this is public information.
<!--ts-->
* [Configuration](#configuration)
   * [Rspamd](#rspamd)
   * [Users personal Whitelists/Blacklists (Combined Maps)](#users-personal-whitelistsblacklists-combined-maps)
   * [.env](#env)
      * [Database Connection details](#database-connection-details)
      * [Rspamd](#rspamd-1)
      * [Quarantine Settings](#quarantine-settings)
      * [API Settings](#api-settings)
      * [Web Settings](#web-settings)
      * [Mail Notifications/Release from Quarantine](#mail-notificationsrelease-from-quarantine)
      * [Redis Settings](#redis-settings)
      * [LDAP Settings](#ldap-settings)
   * [config.local.php (and config.php)](#configlocalphp-and-configphp)
      * [API Settings](#api-settings-1)
      * [Rspamd Statistics](#rspamd-statistics)
      * [Map Settings](#map-settings)
      * [Logging Settings](#logging-settings)
      * [Quarantine and Notification Settings](#quarantine-and-notification-settings)
      * [Application Settings](#application-settings)
      * [GeoIP](#geoip)
* [CLI](#cli)
* [Cron jobs](#cron-jobs)
* [<a href="INSTALL.md">Installation</a>](INSTALL.md)
<!--te-->

## Rspamd
The Rspamd [metadata_exporter](https://docs.rspamd.com/modules/metadata_exporter)
module connects to our Rqwatch metadata_importer API.\
The connection can be authenticated and also be restricted by IP.

In `contrib/rspamd/local.d/metadata_exporter.conf` you can find a template for this that should be copied in `/etc/rspamd/local.d/metadata_exporter.conf`.

Edit `user` and `password` to match `RSPAMD_API_USER` and `RSPAMD_API_PASS` entries in `.env`.

Edit `url` and append `?server=server_name`.\
**`server_name` must match `MY_API_SERVER_ALIAS` entry in `.env`**.\
This is needed in order for the (API) server to identify if an email is stored in quarantine locally or remotely on another API server.

After placing `metadata_exporter.conf` and editing `.env` and `config.local.php` a reload in needed for Rspamd.

## Users personal Whitelists/Blacklists (Combined Maps)
In Rqwatch Combined Maps have been implemented, where two fields are used instead of one that Rspamd normally uses.
These type of maps do work as users personal whitelists/blacklists.

4 types of such lists are supported:
- smtp_from|rcpt_to whitelist
- smtp_from|rcpt_to blacklist
- mime_from|rcpt_to whitelist
- mime_from|rcpt_to blacklist

The fields entries in those maps are seperated by `|` for example:
```
sender@example1.com|recipient@example.com
```
Users have access to those maps depending on their email address as well as aliases created for them.
If one of those addreses matches `rcpt_to` address then access is given.

Admin users can also add entries in those maps.
User access to those entries, created by admin, is controlled by `$USER_CAN_SEE_ADMIN_MAP_ENTRIES` and
`$USER_CAN_DEL_ADMIN_MAP_ENTRIES` in [Map Settings](#map-settings).

In order for these maps to work, custom lua scripts for Rspamd have been implemented and to activate them:
```
cp contrib/rspamd/lua.local.d/* /etc/rspamd/lua.local.d/
```
After placing those lua scripts and editing `.env` and `config.local.php` a reload in needed for Rspamd.

## .env
You should create a copy of the provided file:
```
cp .env-example .env
```
### Database Connection details
- `DB_HOST` - Database server IP
- `DB_NAME` - Database name
- `DB_USER` - Database user
- `DB_PASS` - Database password
- `DB_PORT` - Database port
- `MAILLOGS_TABLE` - The main table where email metadata is stored.\
  Default is `mail_logs`.

### Rspamd
- `RSPAMD_API_USER` - Same as `user` in `metadata_exporter.conf`
- `RSPAMD_API_PASS` - Same as `password` in `metadata_exporter.conf`
- `RSPAMD_API_ACL` - Rspamd IPs (comma-separated) that are allowed to connect to our metadata_importer API
- `RSPAMD_CONTROLLER_PASS` - Rspamd [Controller worker](https://docs.rspamd.com/workers/controller/) password.\
  We need this to get stats from Rspamd.
  Must be the same as the `password` in `/etc/rspamd/local.d/worker-controller.inc`.\
  (Also see `$rspamd_stat_disable` in config.php/config.local.php files).

### Quarantine Settings
- `QUARANTINE_DIR` - Local Quarantine directory
- `QUARANTINE_DAYS` - Number of days to keep emails in quarantine.\
  Cleanup is performed by `cron:quarantine`

### API Settings
If the server runs the API (MetadataImporter, GetMail, ReleaseMail) the following settings apply:
- `API_ENABLE` - Set to `true` to enable API
- `MY_API_SERVER_ALIAS` - If the server runs the API, specify its alias (server name).\
  This must match `?server=` setting of `url` in `metadata_exporter.conf` in order
  for the server to identify if an email is stored in quarantine locally or remotely
  on another API server.

  If this host is only a Web Server (that does not run the API) then **put a name here
  that does not match** `$API_SERVERS` entries in `config.php/config.local.php`

- `WEB_API_USER` - ReleaseMail/GetMail WEB API username for web clients
- `WEB_API_PASS` - ReleaseMail/GetMail WEB API password for web clients
- `WEB_API_ACL` - Comma-separated IPs that are allowed to connect to our local ReleaseMail/GetMail API

### Web Settings
If the server run the Web service then the following settings are relevant:
- `WEB_ENABLE` - Set to `false` to disable web interface
- `WEB_API_USER` - Username the web client uses to connect to remote API servers
- `WEB_API_PASS` - Password the web client uses to connect to remote API servers
- `WEB_HOST` - Full name of our server\
  Used for routing and constructing URLs for maps
- `WEB_SCHEME` - Web scheme to use (https/http)\
  Default is https
- `WEB_BASE` - Default is empty if the web server runs on `/`\
  If under `/subfolder`, also update RewriteBase in `web/.htaccess`
- `FAILED_LOGIN_TIMEOUT` - How many seconds to sleep after a failed login\
  A better brute force detection method might be implemented in the future,
  but for now, this is what we have.
- `IDLE_TIMEOUT` - Login Session timeout.\
  Default 4 hours. Set to `0` to disable it.


### Mail Notifications/Release from Quarantine
If an email is quarantined and a notification must be sent (according to settings) or released from quarantine:
- `WEB_HOST_NOTIFICATIONS` - Used for URL contruction for links in mail notifications to
  users regarding quarantined emails\
  It should point to the hostname of the public web server, where users can login to Rqwatch.
- `MAILER_DSN` - Connection details for the mailer in order to sent mail notifications\
  See [Symfony Mailer](https://symfony.com/doc/current/mailer.html) documentation
  for details.
- `MAILER_FROM` - From address used for notifications and mail release mails, sent by Rqwatch

### Redis Settings
Rqwatch supports Redis for saving login sessions, caching configuration (`config.php/config.local.php`) and caching of Rspamd statistics.\
Sentinel is also supported.
- `REDIS_ENABLE` - Set to `true` to enable Redis support
- `REDIS_DSN` - Redis connection details\
  See [Redis Cache Adapter](https://symfony.com/doc/current/components/cache/adapters/redis_adapter.html#configure-the-connection) documentation for details.
- `REDIS_CONFIG_KEY` - Key to use for storing/caching server configuration\
  Should be different on each server if running in distributed mode.\
  Default is `rqwatch_config_${WEB_HOST}` in order to append a unique value to it.
- `REDIS_CONFIG_CACHE_TTL` - How many seconds to keep config in the Redis cache,
  before refresh\
  Default is to refresh config every 5 minutes (300sec)

### LDAP Settings
System supports LDAP Authentication.
- `LDAP_AUTH_ENABLED` - Set to `true` to enable LDAP Authentication
- `LDAP_URI` - The LDAP URI for connection
- `LDAP_BASE` - The LDAP Base for search
- `LDAP_BIND_DN` - LDAP Bind DN
- `LDAP_BIND_PASS` - LDAP Bind Password
- `LDAP_LOGIN_ATTR` - The LDAP attribute we use to search for the user\
  It's the user's username for login.
- `LDAP_MAIL_ATTR` - LDAP mail attribute
- `LDAP_SN_ATTR` - LDAP attribute for getting user's surname
- `LDAP_SN_ATTR_FALLBAK` - Fallback LDAP attribute for getting user's surname
- `LDAP_GIVENNAME_ATTR` - LDAP attribute for getting user's first name
- `LDAP_GIVENNAME_ATTR_FALLBACK` - Fallback LDAP attribute for getting user's first name
- `LDAP_TLS_CACERTDIR` - (**unused**; use `/etc/openldap/ldap.conf` for now)\
  Specifies the path of the directory containing CA certificates for LDAP server TLS verification
- `LDAP_TLS_REQCERT` - (**unused**; use `/etc/openldap/ldap.conf` for now)\
  Specifies the certificate checking strategy. This must be one of: `never, demand, allow, try`
- `LDAP_ADMINS` - Define LDAP admin usernames, comma-separated

## config.local.php (and config.php)
`config.local.php` can override all default configuration options found in `config.php`

Use `config.local.php` for all your configuration changes instead of modifying `config.php`.\
This is a PHP file, so you should follow PHP syntax rules when editing
and making changes.\
Examine the `config.php` for details and default settings.

You should create a copy of the provided file:
```
cd config/
cp config.local-example.php config.local.php
```

### API Settings
These settings are used by the web servers in order to connect to API servers:
- `$API_SERVERS` - Array of API servers (url, statistics url, and TLS options)
- `$SYS_CA_PATH` - Default CA path dir ([capath](https://symfony.com/doc/current/reference/configuration/framework.html#capath)) to verify remote API servers' TLS
- `$RM_WEB_API_PATH` - Path to use for remote API mail release
- `$GM_WEB_API_PATH` - Path to use for remote API get mail

### Rspamd Statistics
- `$rspamd_stat_disable` - Set to `true` to disable fetching statistics from Rspamd servers
- `$rspamd_stat_api_timeout` - Timeout for Rspamd statistics (float, seconds)
- `$rspamd_stat_redis_key` - Redis key for caching Rspamd statistics
- `$rspamd_stat_redis_cache_ttl` - How many seconds to cache Rspamd statistics in Redis

### Map Settings
- `$MAP_DIR` - Directory for storing and serving maps
- `$MAP_FILE_PERM` - Permissions for map files
- `$USER_CAN_SEE_ADMIN_MAP_ENTRIES` - Control if user can see personal map entries
  created by admin\
  Set to `false` to only show entries created by user.
- `$USER_CAN_DEL_ADMIN_MAP_ENTRIES` - Control if user can delete personal map entries
  created by admin\
  If you set this to `true`, `$USER_CAN_SEE_ADMIN_MAP_ENTRIES`,
  must also be set to `true` to allow delete.\
  This also applies to Enable/Disable of entry.

### Logging Settings
- `$LOG_FILE` - Log file for Rqwatch
- `$LOG_FILE_LEVEL` - Logging level for file logging
- `$LOG_SYSLOG_LEVEL` - Logging level for syslog (minimal data is logged in mail log)
- `$LOG_SYSLOG_PREFIX` - Prefix to use in syslog
- `$log_to_files` - Set to `true` to store details into files\
  Every new email overrides previous files there. This is used for debugging Rspamd connection to our metadata_importer API.
- `$log_to_files_dir` - Directory to store these debug files

### Quarantine and Notification Settings
- `$store_settings` - Array defining which mails (raw) to store in Quarantine based on action taken by Rspamd
- `$release_mail_subject` - Default subject in release mail
- `$notify_mail_subject` - Default subject in notification mail
- `$notification_score` - Mails with score more than this don't get notifications
- `$mail_signature` - Default mail signature

### Application Settings
- `$APP_NAME` - Name to use on HTML pages
- `$FOOTER` - Footer on HTML pages
- `$refresh_rate` - Auto-refresh rate for maillogs web pages
- `$refresh` - Auto-refresh is disabled by default on all web pages\
and explicitly enabled on maillogs pages.\
  Set to `true` to enable globally.
- `$items_per_page` - Number of pager items to show per page\
  Used in maillogs, users, aliases etc.
- `$q_items_per_page` - Pager quarantine days to show
- `$max_items` - Certain pages have an SQL restriction on upper items returned
- `$top_reports` - How many items to show in reports
- `$subject_privacy` - Hide subject in the web interface
- `$show_mail_stats` - Calculate statistics on the mail search page
- `$password_hash` - Default password hash for local users\
  See PHP's [password_hash](https://www.php.net/manual/en/function.password-hash.php)
  documentation for details.

### GeoIP
- `$geoip_enable` - Set to `true` to enable GeoIP for relay IPs
- `$geoip_country_db` - GeoIP country database (requires MaxMind account)

# CLI
Rqwatch uses [symfony/console](https://symfony.com/doc/current/components/console.html) for creating command line tools. You can list all available tools by running:\
`./bin/cli.php list` inside Rqwatch main directory.

Use command option `-v` for verbose output.\
Use command option `-h` for help.

The following tools are available:
- **user:add**\
  Create a user.
    ```
    Usage:
      user:add [options] [--] <username> <mail>

    Arguments:
      username                   Username for the user
      mail                       Email for the user

    Options:
      -f, --first=FIRST          First name
      -s, --surname=SURNAME      Surname
      -a, --admin                Create user with admin privileges
      -l, --ldap                 Create an LDAP user
      -d, --no-notifications     Disable notifications
      -p, --password[=PASSWORD]  Specify user password
    ```
For instance, in order to create an admin user after Installation and Configuration is done:
```
./bin/cli.php user:add -a admin admin@example.com
```

- **cron:notifications**\
  This command scans the Rqwatch database for new undelivered stored mails
  and then sends notification mails to recipients, depending on the configuration.

  By default, notifications for blacklisted emails are not sent unless `-b` option 
  is specified.\
  Blacklisted emails are tracked based on Rspamd headers.
  If a header starts with `RQWATCH_` and ends with either `_BL` or `_BLACKLIST` then
  that email is marked as blacklisted.

  Users are also able to disable notifications by visiting their Profile page and
  opting out of notifications. This also applies for their aliases.

  Finally, config option `$notification_score` also disables notifications
  for emails having a score higher that this.\
  Default score is `50.1`.

  It is suggested that your run this command via cron in order for users to
  receive notifications for quarantined emails as soon as possible.
    ```
    ./bin/cli.php cron:notifications -h

    Options:
      -l, --local           Notifications for local server only
      -m, --mail            Send notification mails
      -s, --show            Show pending notifications
      -b, --blacklisted     Send notifications for blacklisted mails
    ```

- **cron:quarantine**\
  This command scans the Rqwatch database and cleans the Quarantine.

  Depending on the value set in .env `QUARANTINE_DAYS` (default 180),
  Rqwatch databases in being search for email stored before that date.
  If `-d` command option is used then those emails are deleted from Quarantine
  directory (.env `QUARANTINE_DIR`) permanently. However entry remains in the database.
    ```
    ./bin/cli.php cron:quarantine -h

    Options:
      -d, --delete          Delete entries from quarantine
      -l, --local           Clean quarantine for local server only
      -s, --show            Show entries in quaranting pending to be deleted
    ```

- **cron:updatemapfiles**\
  This command scans the Rqwatch database and updates map files if needed.

  It is suggested that your run this via cron on the API servers if you run Rqwatch
  in Distributed mode.
  A map might be created/updated on one server (WEB or API) but could be requested
  from Rspamd running on another server.
    ```
    ./bin/cli.php cron:updatemapfiles -h

    Options:
      -m, --map[=MAP]       Specify map, default all maps
    ```

# Cron jobs
Some of the CLI tools provides by Rqwatch should be run via cron.\
In `contrib/cron` you can find a template find that you can put in `/etc/cron.d/rqwatch`.

It is strongly recommended to enable the following cron jobs on the API servers,
if you run Rqwatch in Distributed mode.

The default cron template suggests:
```
# send mail notifications for blocked/stored mails (local only)
*/5 * * * * root /var/www/html/rqwatch/bin/cli.php cron:notifications -m -l

# update local map files if needed
*/5 * * * * root /var/www/html/rqwatch/bin/cli.php cron:updatemapfiles

# clean quarantine (local only)
01 00 * * * root /var/www/html/rqwatch/bin/cli.php cron:quarantine -d -l
```
- Sends notification every 5 minutes for mails stored locally (API servers)
- Update Map files every 5 minutes (API servers)
- Cleans Quarantine once every day for mails stored locally (API servers) 

# [Installation](INSTALL.md)
