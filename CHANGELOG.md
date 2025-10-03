# CHANGELOG

## Master Dev Branch

### 2025-10-03
- Increase subject size
- Show search form if no entry found
- Move Login as user in user info

### 2025-10-02
- Login as user
- Implemented unsetSessionVars() and moved clearSession in Controller
- Fix bug in map delete redirect
- Fix bug in search 'does not contain'

### 2025-10-01
- Delete all entries from map
- Don't add map entry if already exists
- Handle multi line entries in add custom map entry
- Add missing updated_at from custom map search

## v1.6.5 - Released: 2025-10-01

### 2025-10-01
- Implement setSessionVars() and call it upon login
- Add Totals in rspamd stats
- Add Message-ID in mail_logs

### 2025-09-30
- Fix a bug where getIsAdmin() is not enough on initUrls() and make default login page to be day and not all
- Fix mail release when rcpt_to contains more than one mail
- Fix notifications and release when rcpt_to contains more than one mail

### 2025-09-29
- Add link for map file in map

### 2025-09-25
- Disable notifications for high scored spam

### 2025-09-24
- Hide/show rspamd stats
- Enable/Disable rspamd stats
- Add sn/givenName fallback for LDAP
- Mention personal white/black list in notification mail
- Add warning about links in html
- Show both textBody and htmlBody when viewing mail

### 2025-09-24
- Increase rcpt_to/mime_to size in mail_logs db
- Add more logging in MetadataImporterApi
- Catch exception in MetadataImporterApi (failsafe)

## v1.6.4 - Released: 2025-09-24

### 2025-09-24
- Updated illuminate to v12.31.1
- Updated symfony to v7.3.3
- Use initMapUrls instead of initUrls and local generates
- Move initMapUrls in MapController

### 2025-09-23
- Add search map entry
- Misc changes. mostly view formatting

### 2025-09-22
- Add map link in manage custom maps
- Change color for mails matching rqwatch map
- Allow insert of disabled entries, detect #comments
- Enable/Disable map entries
- Allow dash and parentheses in labels in custom maps
- Case insensitive match for whitelists/blacklists

### 2025-09-19
- Moved some predifined maps to MapCustom
- Deprecated MapGeneric. Moved to MapCustom
- Support insert of multiple entries in custom maps
- Fix bug in map entry delete

## v1.6.3 - Released: 2025-09-18

### 2025-09-18
- Custom Maps

## v1.6.2 - Released: 2025-09-17

### 2025-09-11
- Log remote user reading mail
- Log remote user releasing mail
- Javascript on release form
- Add Virus name in mail notifications

### 2025-09-05
- Minor fixes

### 2025-09-04
- Moved config loading internals out of bootstrap
- Refresh config in redis in app version changes
- Added logo image

### 2025-08-04
- Added Body E-mail links blacklist
- Added Body Host URL links blacklist
- Added Body Full URL links blacklist

## v1.6.1 - Released: 2025-08-01

### 2025-08-01
- Added user option to disable notifications for quarantined mails

### 2025-07-31
- Added Mail/MIME From Blacklist/Whitelist
- Redis cache for Rspamd stats

### 2025-07-30
- Limited show all Paginator
- Added Rspamd statistics for all servers

## v1.6.0 - Released: 2025-07-29

### 2025-07-29
- Added CLI tool to update Map Files if needed
- Added MIME From/Rcpt To Whitelist/Blacklist links in detail view
- Added SMTP From/Rcpt To Whitelist/Blacklist links in detail view
- Added MIME From Whitelist/Blacklist links in detail view
- Added SMTP From Whitelist/Blacklist links in detail view
- Added IP Whitelist/Blacklist links in detail view

## v1.5.0 - Released: 2025-07-18

### 2025-07-18
- Added Generic Maps with custom field (asn, domain, url etc)

### 2025-07-17
- Don't send notifications for blacklisted mails by default
- Added colorcoded for whitelist/blacklist in maillogs

## v1.4.0 - Released: 2025-07-16

### 2025-07-16
- Control if user is allowed to show/del user map entries created by admin

### 2025-07-15
- User Map Forms

### 2025-07-14
- WEB_ENABLE/API_ENABLE .env control
- showAllMaps method
- Move API_ACLs to .env
- Rename API credentials
- Rename WEB_API_PATHS in config

## v1.3.0 - Released: 2025-07-14

### 2025-07-14
- Deprecated GetMap API, moved to apache static file download
- Maps updateMapActivityLog in Service
- Maps updateMapFile in Service
- Maps add/del entry in Service
- Maps pagination

### 2025-07-11
- Added more maps
- Created Generic Two Field/One Field form map
- Created showMap/addMap/delMap
- Created SmtpFromRcptToWhitelist Map
- Created Map Selector

### 2025-07-10
- Added Maps

## v1.2.0 - Released: 2025-07-09

### 2025-07-09
- Clear Quarantine CLI tool
- Logging in Config
- Logging in RedisFactory
- Logging in SessionManager
- Config caching in Redis
- Created RedisFactory
- Moved SessionManager to Core
- Redis Sentinel session support

### 2025-07-08
- Fixed search remove filters
- Disable GeoIP for localhost
- Moved LOG_ from env to config
- Renamed CronCommand to CronNotifications
- Added cli option to show pending notifications (cli cron:notifications)
- Added cli option for notifications for local server only (cli cron:notifications)

## v1.0.0 - Released: 2025-07-08

### 2025-07-08
- Version v1.0.0 tag created
- Removed refresh from Users and Alias pages
- Aliases show/add/del
- Get First/Last Name from LDAP and update on login
- Added Mail Aliases

### 2025-07-07
- Implemented GetMailApi
- Move releaseMail to a seperate method

### 2025-07-04
- API server TLS verification
- Implemented release via remote API server 
- Created ApiClient class
- metadata_importer API refactoring to use MetadataImporterApi class
- mail_release API refactoring to use MailReleaseApi class
- RqwatchAPI base class for API calls

### 2025-07-03
- Initial Mail Release API
- metadata_importer IP ACL
- Auto create LDAP users upon first login
- Added user auth_provider in db
- Added DB user last login timestamp
- Added CLI cron locking
- Paginated Quarantine page
- Add runtime prints in CLI
- Add GeoIP for mail relays, show country
- Add mail relays in detail

### 2025-07-02
- Fixed withPath in paginator
- Unified Logging
- Config refactoring

### 2025-07-01
- Moved config defines to .env and refactored code to use those
- Logging Interface based on Monolog

### 2025-06-30
- Support /subfolder for rqwatch
- User notifications for stored mails via cron command

### 2025-06-28
- Initial cron console command
- Mail Release update

### 2025-06-27
- Added twig template support from mail release
- Initial Mail release from Quarantine/Storage

### 2025-06-26
- Profile page
- Implemented LDAP Authentication
- Implemented Authentication Manager
- Normal users see only their emails. Limit by rcpt_to db field

### 2025-06-25
- Added CHANGELOG and ROADMAP/TODO
- Admin pages
- Added default Middleware Classes for all routes
- Added Authorization Middleware

### 2025-06-24
- Implemented show stored mail
- Implemented open/save attachmentd for stored mail

### 2025-06-23
- Session expiration

### 2025-06-22
- Implemented DB Authentication
- Use http foundation in API instead of custom basic auth

### 2025-06-21
- Added flash messages

### 2025-06-20
- Added Users Model
- Added password hashing
- Added Authentication Middleware

### 2025-06-18
- New Paginator

### 2025-06-17
- Added statistics in search

### 2025-06-16
- Search form

### 2025-06-14
- Code refactoring to MVC
