# WARNING
Rqwatch 2.x+ contains updates to the database schema.

**Upgrade is only supported from 1.8.4 and 2.x**.

# Upgrade

## From 2.x
- Follow the [Rqwatch Upgrade Guide](https://github.com/bilias/rqwatch/blob/master/docs/UPGRADE.md) to get the latest code
- Run the database migrations:
  ```
  ./bin/cli.php db:migrate
  ```
  This applies two new migrations:
  - `Map Hits`: a column on `custom_map_config` and two new tables. It is instant.
    It also links the virus notification skip map (`$notification_skip_virus_map`) to the
    symbol `RQWATCH_VIRUS_NO_NOTIFY`.
  - `Mail Log Viruses`: a new table `mail_log_viruses`, filled from the stored symbols of
    past virus mails in batches (about 10-15 seconds for ~32k virus mails). `mail_logs`
    is not altered. Re-running it fills only the mails that are missing.
- Link your custom maps to the Rspamd symbols that read them, in the new **Rspamd Symbols**
  field of each custom map definition. List every rule that reads the map file, including
  composites built on its symbol. Built-in maps use `$map_symbols`; the defaults match `contrib/`.
- Record the hits of past mail:
  ```
  ./bin/cli.php db:map_hits_backfill -r -v      # the last 31 days
  ./bin/cli.php db:map_hits_backfill -r -a -v   # the whole mail log
  ```
  About 5 ms per mail, so a full log of ~860k mails takes 70-90 minutes. Run it in `tmux`
  or `screen`; it is safe next to live imports and can be re-run. Run it again after
  changing map symbols.
- Apache: replace the `<Location /api>` and `<Location /maps>` blocks with the `<Directory>`
  blocks from [contrib/apache-rqwatch.conf](https://github.com/bilias/rqwatch/blob/master/contrib/apache-rqwatch.conf).
  They match the directories rather than the URL, so they also apply under a subdirectory (`WEB_BASE`).
- multimap.conf: drop `prefilter` (ignored since rspamd 3.3)

## From 1.8.x
Follow **From 1.8.x** in the
[2.1.2 release notes](https://github.com/bilias/rqwatch/blob/master/docs/releases/RELEASE_NOTES-v2.1.2.md):
the same `db:migrate` run also applies this release's migration.
Then follow the steps of **From 2.x** above after `db:migrate`.

# New Features
- **Map hits**: Rqwatch records which maps and map entries matched each mail, from its Rspamd symbols.
  See [Map Hits](https://github.com/bilias/rqwatch/blob/master/docs/CONFIGURE.md#map-hits).
  - Map hits report (Maps > Map hits, admin): mails per map today, in 7 and 31 days, last hit,
    idle maps, and the recorded symbols no map is linked to. Each count opens its mails.
  - Map outcome (admin): a map's mails per action, released and notified, with a 31-day chart
    of hits per day. Linked from the report and from each map page.
  - Hits and Last hit per entry on map, search and all-entries pages, an idle entries filter,
    and the mails of each entry. Users see these for their personal maps.
  - The mail detail page links each symbol to its map and map entry.
  - `db:map_hits_backfill` records the hits of past mail from its stored symbols.
- **Top Viruses** report under Search (admin): the virus names of the search's mails today,
  yesterday, in the last 7 and the last 31 days, each count linked to its mails, with
  antivirus scan failures (`*_FAIL` symbols) listed apart. It applies the search filters
  except Date, which it shows as "(ignored)". Rqwatch now records each mail's antivirus
  symbols and virus names at import, in `mail_log_viruses`.
- **Top Rspamd Symbols** and **Top Local and Mapped Symbols** reports under Search:
  symbols of the newest `$max_items` mails of the search, per action, with links to the mails.
  Without filters they read the last 7 days.
- Custom maps have a **Rspamd Symbols** field; built-in maps use `$map_symbols`.
- `$local_symbol_patterns`, `$whitelist_symbol_patterns` and `$blacklist_symbol_patterns`
  set which symbols are shown as `[map]`, whitelisted and blacklisted.

# Behaviour changes
- Login throttle: a successful login no longer resets the failure counter.\
  Busy shared NAT addresses may need `LOGIN_IPS_WHITELIST`
- Whitelist and blacklist symbols must now end in `_WL`/`_WHITELIST` or `_BL`/`_BLACKLIST`
  (patterns `RQWATCH_*_WL` etc.). Before, the suffix could be followed by more text
  (`RQWATCH_X_BLOCK` counted as blacklisted). This also applies to the notification
  blacklist checks (`-b`/`-B`).
- Symbols linked to a map are shown as `[map]` even without the `RQWATCH_`/`LOCAL_` prefix.
- A search stopped by `DB_WEB_MAX_STATEMENT_TIME` returns to the search page with a message
  instead of a "Query error" page.
- The Top Actions report links each action to its mails.
- A stored mail is read (mail page, `get_mail` API, fuzzy learn) only when its path is a file
  under `QUARANTINE_DIR`, as release and quarantine cleanup already required. If you moved the
  quarantine, see [QUARANTINE_DIR](https://github.com/bilias/rqwatch/blob/master/docs/CONFIGURE.md#quarantine-settings).

# Fixes
- Upgrades from <=2.0 to 2.1.2 whose `db:migrate_drop_mail_log_columns` failed with error 1846 can now re-run it
- Long values without spaces (e.g. a regexp) wrap inside Active Filters
- SQL update files: `18-db-update-2026-10-12` now creates the virus notification skip map
  (`rqwatch_virus_no_notify`), which only `db:migrate` created in 2.1.2

**Full Changelog**: https://github.com/bilias/rqwatch/compare/v2.1.2...v2.1.3
