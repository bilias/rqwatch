# WARNING
Rqwatch 2.x+ contains updates to the database schema.

**Upgrade is only supported from 1.8.4 and 2.x**.

# Upgrade

## From 2.x
- **Before deploying**, check `.env` for placeholder API passwords.
  The importer and mail APIs now refuse them, so mail import and remote get/release fail until they are changed:
  ```
  grep -nE '^(RSPAMD|MAIL)_API_PASS=CHANGE_ME_' .env
  ```
  Change `RSPAMD_API_PASS` in `.env` and `metadata_exporter.conf` together.
- Follow the [Rqwatch Upgrade Guide](https://github.com/bilias/rqwatch/blob/master/docs/UPGRADE.md) to get the latest code
- Run the database migrations:
  ```
  ./bin/cli.php db:migrate
  ```
  This applies five new migrations:
  `Mail Log Fuzzy`, `Mail Log Fuzzy hit and updated_at columns`, `Mail Log ever_stored column and index`,
  `Users session_version column` and `Virus notification skip map`.
  All are quick except the `ever_stored` index build, which scales with `mail_logs` (about 2 s on a ~860k-row table)
  and blocks writes cluster-wide under Galera for that time. Run it in a quiet window.
  With `$import_spool` (default) imports that fail meanwhile are spooled and replayed by `cron:import_spool`.
  If a custom map named `rqwatch_virus_no_notify` already exists, the `Virus notification skip map` migration fails:
  set `$notification_skip_virus_map` in `config.local.php` to another name and re-run.
- Apache: add the `<Location /maps>` block from [contrib/apache-rqwatch.conf](https://github.com/bilias/rqwatch/blob/master/contrib/apache-rqwatch.conf)
  and allow only the Rspamd servers that fetch maps over HTTP.
- Rspamd: add [force_actions.conf](https://github.com/bilias/rqwatch/blob/master/contrib/rspamd/local.d/force_actions.conf)
  (adjust the expression to your antivirus symbols).
- Fuzzy learning is optional and off by default.
  See [Rspamd Fuzzy Learning](https://github.com/bilias/rqwatch/blob/master/docs/CONFIGURE.md#rspamd-fuzzy-learning).

## From 1.8.x

1. **Upgrade to the latest 1.8.x version first**
   - Follow the [Rqwatch 1.8.4 Upgrade Guide](https://github.com/bilias/rqwatch/blob/master/docs/UPGRADE_1.8_plus.md) to get the latest 1.8.x code
   - Follow the [1.8.x DB MIGRATION GUIDE](https://github.com/bilias/rqwatch/blob/master/docs/DB_MIGRATION_1.8.md) to perform the database migrations of 1.8.x

   **Perform the 1.8.x database migrations** in order to be able to update to 2.x version.

2. **Upgrade to the latest 2.x version**
   - Follow the [Rqwatch 2.x Upgrade Guide](https://github.com/bilias/rqwatch/blob/master/docs/UPDATE_2_plus.md) to get the latest code
   - Follow [2.x DB UPDATE Guide](https://github.com/bilias/rqwatch/blob/master/docs/DB_UPDATE_2_plus.md)

   The 2.1.1 and 2.1.2 migrations are applied by the same `db:migrate` run.\
   Then follow the `.env`, Apache and Rspamd steps of **From 2.x** above.

# Security
- Map entry edit, delete and toggle looked the entry up by id alone, so a non-admin could open, delete
  or toggle any user's entry by changing the id in the URL.
  Entries are now scoped to the map and to the user's own entries.
- Non-admins could see, and with `USER_CAN_DEL_ADMIN_MAP_ENTRIES` delete/toggle, other users' map entries
  for a shared address. Users now see only their own entries and, with `USER_CAN_SEE_ADMIN_MAP_ENTRIES`,
  entries created by an admin.
- Non-admins could read other envelope recipients (BCC addresses). They now see only their own.
- Sessions kept a user's aliases and admin rights from login time. A user's sessions now end when an alias
  of theirs is deleted, when an admin changes their username, email, password, admin flag or auth provider,
  and when the user is deleted.
- Map files under `/maps` (whitelists, personal map entries) were readable by anyone with the shipped Apache config.
  See **Upgrade**.
- Login takes the same time for unknown, local and externally provisioned (LDAP/OIDC) users,
  so usernames cannot be enumerated by timing.
- CR/LF in logged values is neutralised so log lines cannot be forged; the importer accepts only alphanumeric QIDs.
- Notification links carry the token in the URL fragment, so it never reaches web or proxy logs.
  The emailed token is traded for a session-bound handle, links are one-time by default and expire when idle.
  Link pages send `Cache-Control: no-store` and `Referrer-Policy: no-referrer`.
- Changing a password requires the current one and ends the user's other sessions.
- The logout link requires a CSRF token.
- The importer and mail APIs refuse `CHANGE_ME_` placeholder passwords.
- Non-admins never see the absolute quarantine path.
- `force_actions.conf` discards viruses even from whitelisted senders.

# New Features
- Rspamd Fuzzy Learning (admin only):
  learn or unlearn a quarantined mail as Spam / Not spam from the detail page,
  into configurable lists (`$fuzzy_learn_flags`) with an editable weight per learn.
  Add weight to a learned mail without unlearning it.
  Fuzzy page with list, symbol, flag, weight, hits and last hit,
  searchable by QID, learned by, server or hash prefix.
  Learned mails sharing a hash are shown and duplicate learns warned about.
  Mails with a local fuzzy hit are marked in listings and show which learned mail they matched.
  `cron:fuzzy_cleanup` deletes learn records whose hashes expired in Rspamd (`$fuzzy_learn_expire_days`, default 90).
- Quarantine per month page. Chart bars in the Quarantine page are clickable
- Release form on the mail page, shared with the detail page
- Virus notification skip map: mails whose antivirus symbol or virus name matches the "Antivirus No Notify" map
  get no user or admin notification. Entries are case-insensitive globs (e.g. `SECURITE_SPAM`, `SecuriteInfo.com.Spam-*`).
  The map is created empty by `db:migrate`.
- IPv6 networks in IP maps
- `cron:notifications -B` sends blacklisted mails at any score

# Changes

- Searches (fuzzy, aliases, users, maps) redirect to a plain GET results page,
  so Back and refresh work and paging keeps the search.
  Alias and user search allow partial addresses (e.g. `@domain`).
- Web DB statements are capped (`DB_WEB_MAX_STATEMENT_TIME`, default 30s) so a runaway search fails fast
- Mail Relays: only real `Received:` headers are parsed; Postfix, Exim, IPv6 and folded IPs are extracted;
  DNS lookups per details page are capped
- DNS timeouts and SERVFAIL are cached for 60s (`$dns_resolv_redis_fail_cache_ttl`) instead of the negative 3600s TTL
- Login throttle counts IPv6 clients on their /64
- No login delay on unmatched routes, blocked IPs or empty credentials
- Password-less release is refused with a message while `MAILER_FROM` is empty.
  `MAILER_DSN` and `MAILER_FROM` are checked on the node that sends the release
- GetMail and Release API clients refuse redirects and accept only the API's reply
- IP map entries are stored in canonical form
- Importer repairs malformed Rspamd symbols (logged) instead of failing
- Whitelisted mail is green only when the whitelist won, not on a forced reject/discard
- Main menu switches to a hamburger at 1400px

# Behaviour changes

- `CHANGE_ME_` values in `RSPAMD_API_PASS` or `MAIL_API_PASS` stop mail import and remote get/release.
  See **Upgrade**.
- Notification links sent before this release no longer work: users log in instead.
  Opening a link needs JavaScript.
- Notification links are one-time by default: the first "View message details" uses them up
  and the message stays reachable only in that browser session, until `$notification_links_idle_minutes` (10) idle.\
  Set `$notification_links_one_time = false` to let a link be reopened until the message is released.
- Users must enter their current password to change it.
- Personal map entries are capped at 1000 per user per map.
  Set `$USER_MAP_MAX_ENTRIES = 0` to keep unlimited.
- Every release is logged (file and syslog) on the web node with user, client IP and sending node.
  The release log line format changed; adjust any log parsing.

# Fixes
- Fix release dropping alternate recipients and failing for multi-recipient mail via API
- Fix password-less release in distributed mode: the mail's own server now sends it
- Main search: Refresh or Back after adding a filter no longer adds it again
- Release refuses when the stored original is missing instead of sending the notice without it
- Fix a sender-controlled attachment filename making attachment-open requests fail
- Fix detail page and listings failing on mails without IP or subject
- Fix PTR and A lookups when the DNS answer starts with a CNAME (RFC 2317)
- OIDC: don't fail the login page when `end_session_endpoint` is unavailable
- Fix Map search pagination
- Authorization: redirect home instead of a blank page without a session

v2.1.2 should be considered as a stable release

**Full Changelog**: https://github.com/bilias/rqwatch/compare/v2.1.1...v2.1.2
