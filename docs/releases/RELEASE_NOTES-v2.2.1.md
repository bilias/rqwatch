# WARNING
Rqwatch 2.x+ contains updates to the database schema.

**Upgrade is only supported from 1.8.4 and 2.x**.

# Upgrade

## From 2.x
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
- OIDC: the port of a trusted host name came unchecked from the request's `Host` header into
  the callback and logout URLs sent to the IdP (which still had to accept them). Origins must
  now match in scheme, host and port, and the URLs are built on the `.env` origin, not on the request's.

# Behaviour changes
- `OPENIDC_TRUSTED_HOSTS` takes origins instead of host names. See **Upgrade** and
  [OPENIDC_TRUSTED_HOSTS](https://github.com/bilias/rqwatch/blob/master/docs/CONFIGURE.md#openid-connect-settings).
- `WEB_HOST` and `WEB_HOST_NOTIFICATIONS` are trusted with `WEB_SCHEME` and its default port only.
- The log line for an untrusted request reads `OPENIDC: untrusted origin '<origin>'`
  (was `untrusted host '<host>'`).

**Full Changelog**: https://github.com/bilias/rqwatch/compare/v2.2.0...v2.2.1
