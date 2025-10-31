# Configuration
Rqwatch uses the following files for its configuration:
- `.env` for sensitive data, various connection details and generally configuration options that are not cached

- `config.php` and `config.local.php` inside `config/` directory for the rest of configuration\
`config.php` is tracked by git and **you should NOT make any changes there as they will be lost on update**.\
`config.local.php` is not tracked by git and you can use it to override any defaults found in `config.php`.

All passwords provided are dummy entries and **should not be used**, as this is public information.
<!--ts-->
* [Configuration](#configuration)
   * [Rspamd (metadata_export)](#rspamd-metadata_export)
   * [Rspamd and Rqwatch Maps](#rspamd-and-rqwatch-maps)
      * [Users personal Whitelists/Blacklists (Combined Maps)](#users-personal-whitelistsblacklists-combined-maps)
      * [Basic Maps](#basic-maps)
      * [Custom Maps](#custom-maps)
   * [.env Configuration file](#env-configuration-file)
      * [Database Connection details](#database-connection-details)
      * [Quarantine Settings](#quarantine-settings)
      * [API Settings](#api-settings)
         * [Rspamd API settings (MetadataImporter)](#rspamd-api-settings-metadataimporter)
         * [Mail API settings (GetMail/ReleaseMail)](#mail-api-settings-getmailreleasemail)
      * [Web Settings](#web-settings)
      * [Mail Notifications/Release from Quarantine](#mail-notificationsrelease-from-quarantine)
      * [Redis Settings](#redis-settings)
      * [LDAP Settings](#ldap-settings)
   * [config.local.php (and config.php) Configuration file](#configlocalphp-and-configphp-configuration-file)
      * [Web API Settings](#web-api-settings)
      * [Rspamd Statistics](#rspamd-statistics)
      * [Map Settings](#map-settings)
      * [Logging Settings](#logging-settings)
      * [Quarantine and Notification Settings](#quarantine-and-notification-settings)
      * [Application Settings](#application-settings)
      * [GeoIP](#geoip)
* [CLI](#cli)
   * [User](#user)
   * [Cron](#cron)
* [Cron jobs](#cron-jobs)
* [<a href="INSTALL.md">Installation</a>](INSTALL.md)
<!--te-->

## Rspamd (metadata_export)
The Rspamd [metadata_exporter](https://docs.rspamd.com/modules/metadata_exporter)
module connects to our Rqwatch metadata_importer/MetadataImporter API to export raw emails and Rspamd
headers and symbols and store that metadata in the database. Depending on the action taken by Rspamd and the
configuration, raw emails can also be saved in local quarantine storage to be examined
and released by administrators or recipient users, if desired.

The connection can be authenticated and also be restricted by IP.

To enable the connection between Rspamd and Rqwatch, copy [metadata_exporter.conf](/contrib/rspamd/local.d/metadata_exporter.conf) from `contrib/` to Rspamd `local.d/metadata_exporter.conf`.
```
cp contrib/rspamd/local.d/metadata_exporter.conf /etc/rspamd/local.d/metadata_exporter.conf
```
Edit `/etc/rspamd/local.d/metadata_exporter.conf`:
- `user` must match `RSPAMD_API_USER` entry in `.env`
- `password` must match `RSPAMD_API_PASS` entry in `.env`
- in `url` you should append `?server=server_name`\
  **`server_name` must match `MY_API_SERVER_ALIAS` entry in `.env`**
  This is needed in order for the (API) server to identify if an email is stored in
  quarantine locally or remotely on another API server.

After configuring `metadata_exporter.conf` and editing `.env` and `config.local.php` a reload in needed for Rspamd.

Also see [API Settings](#api-settings) for remote side API authentication and ACL settings for Rspamd MetadataImporter.

## Rspamd and Rqwatch Maps
It is suggested that Rspamd/Rqwatch API servers also run Web (with restricted access)
just forlocal map downloading, instead of connecting to a remote server (better availability).

### Users personal Whitelists/Blacklists (Combined Maps)
In Rqwatch *Combined Maps* have been implemented, where two fields are used instead of one that Rspamd normally uses.\
These type of maps work as users personal whitelists/blacklists.

Rqwatch support the following types of Combined Maps:

| Map description | Map fields | Rspamd map name |
| --------------- | --------- | --------------- |
| Mail From/RCPT_TO Whitelist | mail from\|rcpt to | RQWATCH_SMTP_FROM_RCPT_TO_WHITELIST |
| Mail From/RCPT_TO Blacklist | mail from\|rcpt to | RQWATCH_SMTP_FROM_RCPT_TO_BLACKLIST |
| MIME From/RCPT_TO Whitelist | mime from\|rcpt to | RQWATCH_MIME_FROM_RCPT_TO_WHITELIST |
| MIME From/RCPT_TO Blacklist | mime from\|rcpt to | RQWATCH_MIME_FROM_RCPT_TO_BLACKLIST |

The fields entries in those maps are seperated by `|` for example:
```
sender@example1.com|recipient@example2.com
```
Users have access to those maps depending on their email address as well as aliases
created for them.\
If one of those addreses matches `rcpt_to` address then access to that user is granted.
(Same process is happening for showing mails to the user)

Admin users can also add entries in those personal user maps.
User access to those entries, created by admin, is controlled by `$USER_CAN_SEE_ADMIN_MAP_ENTRIES` and
`$USER_CAN_DEL_ADMIN_MAP_ENTRIES` in [Map Settings](#map-settings).

**For Combined Maps to work**, custom lua scripts for Rspamd have been implemented and these need to be placed
inside Rspamd's custom lua scripts location:
```
cp contrib/rspamd/lua.local.d/rqwatch_*.lua /etc/rspamd/lua.local.d/
```
Those scripts register the appropriate Rspamd symbols and enable map file download
via http from Rqwatch API running locally on same host as Rspamd.

**In order to define scores for Combined Maps**, you need to append
[groups.conf](/contrib/rspamd/local.d/groups.conf) from `contrib/` to Rspamd's
`local.d/group.conf` file:
```
cat contrib/rspamd/local.d/groups.conf >> /etc/rspamd/local.d/groups.conf
```

Whitelist maps define a score of `-1000`, while Blacklist maps define a score of `1000`.
This can be changed in `groups.conf`.

After installing Rqwatch's lua scripts, edit `groups.conf`, `.env` and `config.local.php` a reload is needed for Rspamd.

### Basic Maps
Rqwarch comes predefined with some *Basic Maps* with common one type field:

| Map description | Map field | Rspamd map name |
| --------------- | --------- | --------------- |
| Mail From Whitelist | smtp from | RQWATCH_SMTP_FROM_WHITELIST |
| Mail From Blacklist | smtp from | RQWATCH_SMTP_FROM_BLACKLIST |
| MIME From Whitelist | mime from | RQWATCH_MIME_FROM_WHITELIST |
| MIME From Blacklist | mime from | RQWATCH_MIME_FROM_WHITELIST |
| IP Whitelist | ip | RQWATCH_IP_WHITELIST |
| IP Blacklist | ip | RQWATCH_IP_BLACKLIST |

**In order to activate Basic Maps**, you need to append [multimap.conf](/contrib/rspamd/local.d/multimap.conf) from `contrib/` to Rspamd's `local.d/multimap.conf`
```
cat contrib/rspamd/local.d/multimap.conf >> /etc/rspamd/local.d/multimap.conf
```
`multimap.conf` defines type of map, score and enable map file download
via http from Rqwatch API running locally on same host as Rspamd.

Whitelist maps define a score of `-1000`, while Blacklist maps define a score of `1000`.

Using predefined Basic Maps or choosing to use Custom Maps (see bellow) is up to you.

### Custom Maps
Rqwatch also supports *Custom Maps* where definition and configuration is performed
on the Web interface and is not hardcoded inside Rqwatch code.

You still need to define those maps in Rspamd's in order to define the map type, score and
enable download from Rqwatch API.

Let's take for example a custom map about Spam text found inside Body of emails:
```
Custom Map definition in Rqwatch:

Map Name: bad_words # this will create maps/bad_words.txt URL endpoint for map
Map Description: Bad Body Words
Field Name: text
Field Label: Text (regexp)

multimap.conf:

RQWATCH_BAD_WORDS {
  type = "content";
  filter = "text";
  map = "http://127.0.0.1/maps/bad_words.txt"
  regexp = true;
  score = 1;
  dynamic_symbols = true;
}

example map entry:
/common spam text/i LOCAL_spam_text_1:10
```
- Adding `RQWATCH_` (or `LOCAL_`) as a prefix of Map Name in `multimap.conf`, tells Rqwatch to match it as a local map on the web interface.
- Adding `_WL` or `_WHITELIST` as a suffix of Map Name, tells Rqwatch to match it
as a whitelisted entry.
- Adding `_BL` or `_BLACKLIST` as a suffix of Map Name, tells Rqwatch to match it
as a blacklisted entry.

Custom maps can be used with any plugin of Rspamd that reads maps of one field such as:
multimap, whitelist, spf, dmarc, reputation, antivirus etc.

For example:

| Map description | Map field | Rspamd reference configuration (not complete) |
| --------------- | --------- | --------------------------------------------- |
| White Domains | domain | `multimap.conf`: `type = "from" filter = "email:domain";` |
| Bad SMTP From | email | `multimap.conf`: `type = "from"; extract_from = "smtp";` |
| White Dmarc | domain | `whitelist.conf`: `domains = [ "http://127.0.0.1/maps/whitelist_dmarc.txt" ];` |
| White Virus | signature | `antivirus.conf`: `whitelist = "http://127.0.0.1/maps/antivirus_whitelist.txt";`|

Check [Rspamd documentation](https://docs.rspamd.com/) for details.

In our local setup we've implemented multiple different kinds of maps which are
shared between our Rspamd/Rqwatch installations:\
Good/Bad maps for Domains, Emails (smtp/mime), SPF, DKIM, DMARC, IPs,
Body URLs (full/host), Body emails, Headers, ASN, Subject etc.

An administrator can define maps for whatever field Rspamd supports and make
Rqwatch a central repository for distributing those maps, instead of editing
files locally on multiple servers.

In advance, an administrator can choose to not use Basic Maps at all and implement
Custom Maps with different scores.

## .env Configuration file
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

- `MAILLOGS_TABLE` - The main table where email metadata is stored\
  Default is `mail_logs`

### Quarantine Settings
- `QUARANTINE_DIR` - Local Quarantine directory\
  rqwatch user must have read/write access in this directory.

- `QUARANTINE_DAYS` - Number of days to keep emails in quarantine\
  Cleanup is performed by `cron:quarantine`

### API Settings
If the server runs the API (MetadataImporter, GetMail, ReleaseMail) the following settings apply:
- `API_ENABLE` - Set to `false` to disable the APIs

- `MY_API_SERVER_ALIAS` - If the server runs the API, specify its alias (server name).\
  This must match `?server=` setting of `url` in `metadata_exporter.conf` in order
  for the server to identify if an email is stored in quarantine locally or remotely
  on another API server.

  If this host is only a Web Server (that does not run the API) then **put a name here
  that does not match** `$API_SERVERS` entries in `config.php/config.local.php`

#### Rspamd API settings (MetadataImporter)
- `RSPAMD_API_USER` - Authentication username for our metadata_importer API\
  Same as `user` in `metadata_exporter.conf`

- `RSPAMD_API_PASS` - Authentication password for our metadata_importer API\
  Same as `password` in `metadata_exporter.conf`

- `RSPAMD_API_ACL` - Rspamd IPs (comma-separated) that are allowed to connect to our
metadata_importer API\
  Use `127.0.0.1` if Rspamd and Rqwatch API run on the same host.

#### Mail API settings (GetMail/ReleaseMail)
- `MAIL_API_USER` - ReleaseMail/GetMail Mail API username\
  Used by both Mail API (server) and Web (client)

- `MAIL_API_PASS` - ReleaseMail/GetMail Mail API password\
  Used by both Mail API (server) and Web (client)

- `MAIL_API_ACL` - Comma-separated IPs that are allowed to connect to our
local ReleaseMail/GetMail API\
  Use `127.0.0.1` if API and Web run on the same host.

### Web Settings
If the server runs the Web service then the following settings are relevant:
- `WEB_ENABLE` - Set to `false` to disable web interface

- `WEB_HOST` - Full name (FQDN) of our server\
  Used for routing and constructing URLs for maps

- `WEB_SCHEME` - Web scheme to use (https/http)\
  Default is https

- `WEB_BASE` - Default is empty if the web server runs on `/`\
  If under `/subfolder`, also update RewriteBase in `web/.htaccess`

- `FAILED_LOGIN_TIMEOUT` - How many seconds to sleep after a failed login\
  A better brute force prevention method might be implemented in the future,
  but for now, this is what we have.\
  Nevertheless failed logins, including IPs, are logged in Rqwatch's log file
  (`$LOG_FILE`) and Fail2Ban can be applied.

- `IDLE_TIMEOUT` - Login Session timeout.\
  Default 4 hours. Set to `0` to disable it.

- `RSPAMD_CONTROLLER_PASS` - Rspamd [Controller worker](https://docs.rspamd.com/workers/controller/) password.\
  This is needed to get stats from Rspamd.
  Must be the same as the `password` in `/etc/rspamd/local.d/worker-controller.inc`.\
  (Also see `stat_url` for `$API_SERVERS` and `$rspamd_stat_disable`
  in `config.local.php/config.php` files).

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
Rqwatch supports Redis for saving login sessions, caching configuration (`config.php` and `config.local.php`) and caching of Rspamd statistics.\
Sentinel is also supported.
- `REDIS_ENABLE` - Set to `true` to enable Redis support

- `REDIS_DSN` - Redis connection details\
  See [Redis Cache Adapter](https://symfony.com/doc/current/components/cache/adapters/redis_adapter.html#configure-the-connection) documentation for details.

- `REDIS_CONFIG_KEY` - Redis key to use for storing/caching server configuration\
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

- `LDAP_UPDATE_NAME_ON_LOGIN` - Update user's first/last name on every login

- `LDAP_TLS_CACERTDIR` - (**unused**; use `/etc/openldap/ldap.conf` for now)\
  Specifies the path of the directory containing CA certificates for LDAP server TLS verification

- `LDAP_TLS_REQCERT` - (**unused**; use `/etc/openldap/ldap.conf` for now)\
  Specifies the certificate checking strategy. This must be one of: `never, demand, allow, try`

- `LDAP_ADMINS` - Define LDAP admin usernames, comma-separated

## config.local.php (and config.php) Configuration file
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

### Web API Settings
These settings are used by the web servers in order to connect to API servers (as a web client):
- `$API_SERVERS` - Array of API server aliases (url, statistics url and TLS options)\
  See `config.php` for all available options

- `$SYS_CA_PATH` - Default CA path dir ([capath](https://symfony.com/doc/current/reference/configuration/framework.html#capath)) to verify remote API servers' TLS

- `$GET_MAIL_API_PATH` - Path to use for remote API get mail

- `$RELEASE_MAIL_API_PATH` - Path to use for remote API mail release

`MAIL_API_USER` and `MAIL_API_PASS` from `.env` are also used from web client in order to be authenticated to remote Mail API servers.

If the Web server also runs the API, and `MY_API_SERVER_ALIAS` matches an entry in
`$API_SERVERS` and mail is stored locally with a `server` entry matching its local alias name,
then GetMail and ReleaseMail are handled locally.\
In all other cases, a call to the remote API server is made.

### Rspamd Statistics
- `$rspamd_stat_disable` - Set to `true` to disable fetching statistics from Rspamd servers

- `$rspamd_stat_api_timeout` - Timeout for Rspamd statistics (float, seconds)

- `$rspamd_stat_redis_key` - Redis key for caching Rspamd statistics

- `$rspamd_stat_redis_cache_ttl` - How many seconds to cache Rspamd statistics in Redis

### Map Settings
- `$MAP_DIR` - Directory for storing and serving map files\
  rqwatch user must have read/write access in this directory.

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
- `$LOG_FILE` - Log file for Rqwatch\
  rqwatch user must have read/write access in the parent directory.\
  Default is `rqwatch.log` inside `logs/` directory in Rqwatch main path.

- `$LOG_FILE_LEVEL` - Logging level for file logging\
  See [LoggerFactory.php](/app/Core/Logging/LoggerFactory.php) for all available levels.

- `$LOG_SYSLOG_LEVEL` - Logging level for syslog\
  Minimal data is logged in mail log.

- `$LOG_SYSLOG_PREFIX` - Prefix to use in syslog

- `$log_to_files` - Set to `true` to store details into files\
  Every new email overrides previous files there. This is used for debugging Rspamd
  connection to our metadata_importer API.

- `$log_to_files_dir` - Directory to store these debug files

### Quarantine and Notification Settings
- `$store_settings` - Array defining which mails (raw) to store in Quarantine
based on action taken by Rspamd\
  Default is to store mails with `add header`, `rewrite subject`, `discard`, and `reject`
  action.\
  Mails with virus symbols are stored independently of the action.

- `$release_mail_subject` - Default subject in release mail

- `$notify_mail_subject` - Default subject in notification mail

- `$notification_score` - Mails with score more than this don't get notifications

- `$mail_signature` - Default mail signature

### Application Settings
- `$APP_NAME` - Name to use on HTML pages

- `$APP_LOGO` - Image to use as logo

- `$APP_LOGO_ALT` - Text to show on mouse hover over logo

- `$FOOTER` - Footer on HTML pages

- `$refresh_rate` - Auto-refresh rate for maillogs web pages

- `$refresh` - Auto-refresh is disabled by default on all web pages and explicitly enabled
on maillogs pages.\
  Set to `true` to enable globally.

- `$items_per_page` - Number of pager items to show per page\
  Used in maillogs, users, aliases etc

- `$q_items_per_page` - Pager quarantine days to show

- `$max_items` - Certain pages have an SQL restriction on upper items returned

- `$top_reports` - How many items to show in Top reports

- `$subject_privacy` - Hide the email subject on the web interface

- `$show_mail_stats` - Calculate statistics on the mail search page

- `$password_hash` - Default password hash for local users\
  See PHP's [password_hash](https://www.php.net/manual/en/function.password-hash.php)
  documentation for details.

### GeoIP
GeoIP is used to show country location for each for mail relays in detailed mail log.

- `$geoip_enable` - Set to `true` to enable GeoIP for relay IPs (country)

- `$geoip_country_db` - GeoIP country database (requires MaxMind account)

# CLI
Rqwatch uses [symfony/console](https://symfony.com/doc/current/components/console.html) for creating command line tools. You can list all Rqwatch available tools by running:\
`./bin/cli.php list` inside Rqwatch main directory.

Alternatively you can create a symlink for this and use `rqwatch` instead of
`./bin/cli.php` inside Rqwatch main folder:
```
ln -s /var/www/html/rqwatch/bin/cli.php /usr/local/bin/rqwatch
```

Use command option `-v` for verbose output.\
Use command option `-h` for help.

The following tools are available:

## User
```
./bin/cli.php list user

Available commands for the "user" namespace:
  user:add  Create a user
```

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
For instance, in order to create an admin user after Installation and Configuration use:
```
./bin/cli.php user:add -a admin admin@example.com
```

## Cron
```
./bin/cli.php list cron

Available commands for the "cron" namespace:
  cron:notifications   Notifications for stored mails
  cron:quarantine      Clean Quarantine
  cron:updatemapfiles  Update Map Files
```

- **cron:notifications**\
  This command scans the Rqwatch database for new undelivered stored mails
  and then sends notification mails to recipients, depending on the configuration.

  By default, notifications for blacklisted emails are not sent unless `-b` option 
  is specified.\
  Blacklisted emails are tracked based on Rspamd symbols.
  If a symbol starts with `RQWATCH_` and ends with either `_BL` or `_BLACKLIST` then
  that email is marked as blacklisted.

  Users are also able to disable notifications by visiting their Profile page and
  opting out of notifications. This also applies for their aliases.

  Finally, config option `$notification_score` also disables notifications
  for emails having a score higher that this.\
  Default score is `50.1`.

  It is suggested that your run this command via [cron](#cron-jobs) in order for users to
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
  Rqwatch database in being searched for emails stored before that date.
  If `-d` command option is used then those emails are deleted from Quarantine
  directory (.env `QUARANTINE_DIR`) permanently. However entries remain in the database.
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
Use `./bin/cli.php list cron` to see all available cron tools.

In `contrib/cron` you will find a template that you can put in `/etc/cron.d/rqwatch`.

It is recommended to enable the following cron jobs on all API servers,
no matter if you run Rqwatch in Distributed or Local mode.

The default cron template suggests:
```
# send mail notifications for blocked/stored mails (local only) every 5 min
*/5 * * * * root /var/www/html/rqwatch/bin/cli.php cron:notifications -m -l

# update local map files if needed every 5 min
*/5 * * * * root /var/www/html/rqwatch/bin/cli.php cron:updatemapfiles

# clean quarantine (local only) daily
01 00 * * * root /var/www/html/rqwatch/bin/cli.php cron:quarantine -d -l
```
- Sends notification every 5 minutes for mails stored locally (API servers)
- Update Map files every 5 minutes (API servers)
- Cleans Quarantine once every day for mails stored locally (API servers) 

Frequency can be adjusted to suite your setup.

# [Installation](INSTALL.md)
