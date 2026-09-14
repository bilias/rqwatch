# Rqwatch 2.x+ Update instructions

# WARNING

Rqwatch 2.x **drops deprecated columns** and indexes from `mail_logs`, adds
constraints to `mail_aliases` and `maps_combined`, and **removes the
non-multipart metadata importer endpoint**.

Read the upgrade path below before pulling the code.

**Upgrading from 1.8.x without completing the 1.8.x database migrations first is not supported**.

**Upgrading from 1.7.x or earlier is also not supported**.

# Upgrade

## 0. Back up the database

The 2.x migrations remove data.

## 1. Upgrade to the latest 1.8.x first

2.x cannot be installed directly from 1.7.x or earlier.

Follow the [Rqwatch 1.8+ Update instructions](/docs/UPDATE_1.8_plus.md).

**Then, while still on v1.8.4, run**:

```
./bin/cli.php db:migrate
```

This must complete. Two of these are data backfills that copy existing rows and
can take a long time on a large installation.

- **Mail Log Recipients** (`20260111_mail_recipients`)\
  populates `mail_log_recipients`, which 2.x uses as the only source of recipients.
- **Mail Log Data** (`20260731_mail_log_data`)\
  copies `headers`, `symbols` and `fuzzy_hashes` out of `mail_logs` into `mail_log_data`.

**Mail Log Data matters most**. 2.x drops those three columns from `mail_logs`,
and it only drops what 1.8.x already copied out. Any row the migration did not
reach loses its headers, symbols and fuzzy hashes permanently.

Let both finish before going any further, and confirm:

```sql
SELECT migration, status FROM migrations
 WHERE migration IN ('20260111_mail_recipients','20260731_mail_log_data');
```

Both must read `completed`. If a run was interrupted, `-f` fills the gaps and
never re-copies or truncates anything:
```
./bin/cli.php db:migrate_mail_recipients -f
./bin/cli.php db:migrate_mail_log_data -f
```

## 2. Point rspamd at the multipart endpoint

**Do this while still on 1.8.4, and verify it works**.

`api/metadata_importer.php` is **removed** in 2.x. If rspamd still posts to it
after the upgrade it receives a 404, and `metadata_exporter` posts once per
scan with no retransmit, so every mail's metadata is silently lost, with
nothing in rspamd's log but a failed HTTP callback.

In Rspamd's `local.d/metadata_exporter.conf`, on **every** API scanning node:

```
url = "http://127.0.0.1/api/metadata_importer_multipart.php?server=mx1";
```
Notice the `metadata_importer_multipart.php` here instead of the legacy
`metadata_importer.php`.

Reload rspamd and confirm new mail still appears in Rqwatch. The multipart
endpoint already exists in 1.8.4, so this can be done and verified ahead of
the code change.

---

## 3. Get the 2.x code

Follow the general [UPGRADE GUIDE](/docs/UPGRADE.md).

## 4. Add the new cron job and check Redis

A new cron entry replays mail metadata that the database refused at the time
of delivery (required REDIS). Without it nothing is ever replayed.\
See `contrib/cron`:

```
# import mail metadata spooled by the API when database refused a write
# (local only, so the raw file can be verified) every 5 min
3-58/5 * * * * root /var/www/html/rqwatch/bin/cli.php cron:import_spool -i -l
```

The spool also needs Redis `maxmemory-policy noeviction` (the default). Under
`allkeys-lru` Redis may discard spooled metadata *after* the API has already
accepted the mail, which is the one way this feature loses a mail it promised
to keep.

## 5. Apply the 2.x database updates

Follow [DB_UPDATE_2_plus.md](/docs/DB_UPDATE_2_plus.md).

It covers the pending migrations, the manual-only column drop, and the table
rebuild that reclaims the space.\
The rebuild is the only part that needs a maintenance window.

## 6. Verify

```
./bin/cli.php db:migrate
```

Everything should report as already applied.
