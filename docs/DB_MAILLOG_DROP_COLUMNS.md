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

Every `mail_logs` row must already have a `mail_log_data` row.\
The SQL bellow must return **0**:

```sql
SELECT COUNT(*) AS missing
  FROM mail_logs ml
  LEFT JOIN mail_log_data md ON md.mail_log_id = ml.id
 WHERE md.mail_log_id IS NULL;
```

If it does not, run `./bin/cli.php db:migrate_mail_log_data -f` and check again.\
`-f` fills gaps and never re-copies or truncates anything.\
On a large installation the query is slow, so run it while the system is idle rather than
inside your maintenance window.

---

## Step 1 - apply the cleanup migrations

Run on **one** API server only. Schema changes replicate on Galera.\
If you run multiple API servers and each one with a seperate DB, then you must
run it on **all API servers**.

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

**Writes are blocked for the whole rebuild**, cluster-wide on Galera.\
Mail is still delivered by your MTA, but Rqwatch cannot record it in DB.
Those inserts are refused rather than left waiting.
If you have [Redis enabled](CONFIGURE.md#redis-settings))
those mails can be spooled in Redis and imported afterwards by
[`cron:import_spool`](CONFIGURE.md#cron) in order to not loose anything while in maintenance.

Stop cron on all API servers for the window anyway — `cron:notifications`
must not be interrupted between sending a notification and recording it as
sent:

- `systemctl stop crond` on all API servers
- Disable the rspamd action that posts metadata to Rqwatch, or stop accepting
  mail (not needed if you enabled Redis)
- Run the rebuild
- Re-enable rspamd, then start cron again

The duration depends far more on your storage than on your row count. Measure
on a restored copy if you need to know it in advance.

---

## Applying the SQL by hand

If you prefer to apply the change by hand rather than through the CLI, the
statement is in `contrib/updates/09-db-update-2026-09-08`:

```sql
ALTER TABLE `mail_logs`
  DROP COLUMN `symbols`,
  DROP COLUMN `fuzzy_hashes`,
  DROP COLUMN `headers`;
```

Add `, ALGORITHM=INSTANT` to make it metadata-only.

Doing it this way leaves the `migrations` table without a record of it. The
next run of `db:migrate_drop_mail_log_columns` detects the columns are
already gone and records the migration as completed without touching the
table, so run it afterwards to keep the status accurate.

`OPTIMIZE TABLE `mail_logs` is also needed afterwards to reclaim the space.
