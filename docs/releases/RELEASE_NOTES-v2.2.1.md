# WARNING
Rqwatch 2.x+ contains updates to the database schema.

**Upgrade is only supported from 1.8.4 and 2.x**.

# Upgrade

## From 2.x
- **Before deploying**, check `.env` and Rspamd's `metadata_exporter.conf` for published
  secrets. Rqwatch now refuses to start while a key it reads holds a `CHANGE_ME_` placeholder
  or one of the example values shipped before v1.8.4, and names the key:
  ```
  grep -nE 'CHANGE_ME_|1vkUSBLf|fnPsTu6p|1k5X73kl|jPaEFfhP|BBfXqlWk|wLwVOWBw|v47o7uz9|itzWMXZa' \
    .env /etc/rspamd/local.d/metadata_exporter.conf
  ```
  Change `RSPAMD_API_PASS` in `.env` and `metadata_exporter.conf` together, and a database or
  Redis password on the server too. A key Rqwatch does not read is not checked
  (see [.env Configuration file](https://github.com/bilias/rqwatch/blob/master/docs/CONFIGURE.md#env-configuration-file)).
- **Before deploying**, if `OPENIDC_TRUSTED_HOSTS` is set in `.env`, rewrite each host name
  as an origin, `http(s)://host` or `http(s)://host:port`. The web interface refuses to start
  on a bare host name and names the entries to fix:
  ```
  # before
  OPENIDC_TRUSTED_HOSTS="rqwatch,rqwatch.internal.example.com"
  # after
  OPENIDC_TRUSTED_HOSTS="https://rqwatch,https://rqwatch.internal.example.com"
  ```
  The default port (443 for https, 80 for http) need not be written: `https://rqwatch` and
  `https://rqwatch:443` are the same. If users browse Rqwatch on another port, list that
  origin too, including `WEB_HOST`'s own (e.g. `https://rqwatch.example.com:8443`).
- Follow the [Rqwatch Upgrade Guide](https://github.com/bilias/rqwatch/blob/master/docs/UPGRADE.md) to get the latest code

# Security
- Secrets that were published in `.env-example` or `contrib/` (the `CHANGE_ME_` placeholders,
  and the example values shipped before v1.8.4, still in the git history) are refused at boot
  where Rqwatch reads them. Before, only the importer and mail APIs refused `CHANGE_ME_`, and
  an install that kept a pre-1.8.4 example password still accepted it.
- OIDC: the port of a trusted host name came unchecked from the request's `Host` header into
  the callback and logout URLs sent to the IdP (which still had to accept them). Origins must
  now match in scheme, host and port, and the URLs are built on the `.env` origin, not on the request's.
- Attachments: a download carries the mail's Content-Type only when it is a plain type/subtype, else `application/octet-stream`.

# Behaviour changes
- Rqwatch does not start while a secret it reads is a published one. See **Upgrade**.
- `OPENIDC_TRUSTED_HOSTS` takes origins instead of host names. See **Upgrade** and
  [OPENIDC_TRUSTED_HOSTS](https://github.com/bilias/rqwatch/blob/master/docs/CONFIGURE.md#openid-connect-settings).
- `WEB_HOST` and `WEB_HOST_NOTIFICATIONS` are trusted with `WEB_SCHEME` and its default port only.
- LDAP: .env LDAP_TIMEOUT (5 seconds by default)
- `cron:updatemapfiles` exits with failure when a map file could not be written, `cron:cleanupdb` when it refuses an unsafe `DATABASE_DAYS`.

# Bug fixes
- OpenID Connect logout did not sign out at the provider (broken in v2.2.0)
- With Redis sessions, the request after a login could arrive before the session was stored
- Passwords set in user edit and profile are trimmed, as the login does
- A map change whose map file could not be written was reported as failed, though it was saved
- Users whose stored email had capitals could not add or edit map entries for their own address

**Full Changelog**: https://github.com/bilias/rqwatch/compare/v2.2.0...v2.2.1
