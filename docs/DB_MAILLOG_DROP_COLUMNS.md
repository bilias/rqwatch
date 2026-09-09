# Cleaning up the mail_logs schema

`mail_logs` is the table every search reads. These migrations remove data and
indexes Rqwatch no longer uses, and you must apply them for a performance win.

The schema change itself is instant, but it does not shrink anything - the old
data stays in the table's existing pages until the table is rebuilt, which is
a separate and much more expensive step. Apply the migrations whenever you
like; do the rebuild once, in a maintenance window, after all migrations are completed.

---

## Before you start

Take a database backup. These migrations destroy data.

Every `mail_logs` row must already have a `mail_log_data` row. This must
return **0**:

```sql
SELECT COUNT(*) AS missing
  FROM mail_logs ml
  LEFT JOIN mail_log_data md ON md.mail_log_id = ml.id
 WHERE md.mail_log_id IS NULL;
```

If it does not, run `./bin/cli.php db:migrate_mail_log_data -f` and check
again. `-f` fills gaps and never re-copies or truncates anything. On a large
installation the query is slow, so run it while the system is idle rather than
inside your maintenance window.

---

## Step 1 - apply the cleanup migrations

Run on **one** API server only. Schema changes replicate on Galera.\
If you run multiple API servers and each one with a seperate DB, then you need
to run it on all API servers.

```
./bin/cli.php db:migrate_drop_mail_log_columns
```

Drops `headers`, `symbols` and `fuzzy_hashes`, already copied to
`mail_log_data` by `db:migrate_mail_log_data`. Refuses to run unless that
migration is recorded as `completed`.

On older MariaDB (before 10.4) the instant path is refused and the migration rebuilds the
table instead, doing step 2's work at the same time. It logs which path it
took. A rebuild needs free disk space for a second copy of the table; if it is
interrupted, MariaDB rolls it back and you can run the command again.

## Step 2 - reclaim the space

Do this once, after all the migrations, in a maintenance window.

```
./bin/cli.php db:optimize_table -t mail_logs
```

The table is rebuilt, so it **needs free space** for a second copy.

**Writes are blocked for the whole rebuild**, cluster-wide on Galera. Mail is
still delivered by your MTA, but Rqwatch cannot record it in DB. Those inserts are
refused rather than left waiting, so they are spooled to Redis (if [enabled](#redis-settings))
and imported afterwards by [`cron:import_spool`](CONFIGURE.md#cron).

Stop cron on all API servers for the window anyway — `cron:notifications`
must not be interrupted between sending a notification and recording it as
sent:

- `systemctl stop crond` on all API servers
- Disable the rspamd action that posts metadata to Rqwatch, or stop accepting
  mail
- Run the rebuild
- Re-enable rspamd, then start cron again

The duration depends far more on your storage than on your row count. Measure
on a restored copy if you need to know it in advance.

---

## Applying the SQL by hand

Each migration's statement is in the matching `contrib/updates/` file. Run the
corresponding `db:migrate_*` command afterwards - it detects the change is
already applied and records it without touching the table, keeping the
`migrations` table accurate.
