# Cleaning up the mail_logs schema

`mail_logs` is the table every search reads. These migrations remove data and
indexes Rqwatch no longer uses. Apply them - the performance win is the point.

The schema change itself is instant, but it does not shrink anything - the old
data stays in the table's existing pages until the table is rebuilt, which is
a separate and much more expensive step. Apply the migrations whenever you
like; do the rebuild once, in a maintenance window, after all migrations are completed.

---

## Before you start

Take a database backup. These migrations destroy data.

Every `mail_logs` row must already have a `mail_log_data` row.\
The SQL below must return **0**:

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

## Step 1 - apply the data migration

Run on **one** API server only. Schema changes replicate on Galera.\
If you run multiple API servers and each one with a separate DB, then you must
run it on **all API servers**.

```
./bin/cli.php db:migrate_drop_mail_log_columns
```

Drops `headers`, `symbols` and `fuzzy_hashes`, already copied to
`mail_log_data` by `db:migrate_mail_log_data`.\
Refuses to run unless that migration is recorded as completed.

On older MariaDB (before 10.4) the instant path is refused and the migration rebuilds the
table instead, doing step 3's work at the same time. It logs which path it
took. A rebuild **needs free disk space** for a second copy of the table.

## Step 2 - drop deprecated indexes
```
./bin/cli.php db:migrate_drop_mail_log_indexes
```

This drops indexes from `mail_logs` table that are no longer needed.

## Step 3 - reclaim the space

Do this once, after all the migrations, in a maintenance window.\
The table is rebuilt, so it **needs free space** for a second copy,
although much less than before since the large columns were dropped (step 1).

**Writes are blocked for the whole rebuild**, cluster-wide on Galera.\
Mails are still delivered by your MTA, but Rqwatch cannot record them in DB.\
Those inserts are refused rather than left waiting.

With [Redis enabled](CONFIGURE.md#redis-settings) the metadata is spooled and
imported afterwards by [`cron:import_spool`](CONFIGURE.md#cron), **so nothing is lost**.\
Without Redis those mails are not recorded in Rqwatch at all.

Stop cron on all API servers for the window anyway - `cron:notifications`
must not be interrupted between sending a notification and recording it as
sent:

- `systemctl stop crond` on all API servers
- Disable the rspamd action that posts metadata to Rqwatch, or stop accepting
  mail (not needed if you enabled Redis)
- Run the rebuild:
  ```
  ./bin/cli.php db:optimize_table -t mail_logs
  ```
- Re-enable rspamd, then start cron again

The duration depends far more on your storage than on your row count. Measure
on a restored copy if you need to know it in advance.

After that run the migrations cli to verify that all migrations are applied:
```
./bin/cli.php db:migrate
```

---

## Applying the SQL by hand

If you prefer to apply the changes by hand rather than through the CLI, the
statements are in:\
`contrib/updates/09-db-update-2026-09-08`\
`contrib/updates/10-db-update-2026-09-11`\
`contrib/updates/11-db-update-2026-09-11`

```sql
ALTER TABLE `mail_logs`
  DROP COLUMN `symbols`,
  DROP COLUMN `fuzzy_hashes`,
  DROP COLUMN `headers`;
```

```sql
ALTER TABLE `mail_logs`
  DROP INDEX `id_action_index`,
  DROP INDEX `created_at_index`,
  DROP INDEX `rcpt_to_index`;
```
```sql
OPTIMIZE TABLE `mail_logs`;
```

Add `, ALGORITHM=INSTANT` to make it metadata-only.

Doing it manually leaves the `migrations` table without a record of it.

The next run of `db:migrate_drop_mail_log_columns` detects the columns are
already gone and records the migration as completed without touching the
table, so run it afterwards to keep the status accurate.

After that run the migrations cli to verify that all migrations are applied:
```
./bin/cli.php db:migrate
```
