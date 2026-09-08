# Dropping the migrated mail_logs columns

`mail_logs.headers`, `mail_logs.symbols` and `mail_logs.fuzzy_hashes` were
moved to the `mail_log_data` table by the `db:migrate_mail_log_data`
migration. That migration **copied** the values and left the originals in
place, so the data exists twice.

Rqwatch no longer reads the `mail_logs` copies. The model accessors read
`mail_log_data` only, and the importer writes there only. The columns are
dead weight and this guide removes them.

**The drop is not reversible.** Verify the data first.

---

## Two separate steps

Dropping a column and reclaiming the disk space are **not** the same
operation, and they have very different costs.

| Step | Command | Cost |
| --- | --- | --- |
| 1. Drop the columns | `db:migrate_drop_mail_log_columns` | Instant on MariaDB 10.4+ |
| 2. Reclaim the space | `db:optimize_table` | Full table rebuild |

Step 1 changes the schema. On MariaDB 10.4 and later it is a metadata-only
change (`ALGORITHM=INSTANT`) that finishes in milliseconds. The old values
stay physically present in the existing pages, so **nothing shrinks and the
scans do not get faster yet**.

Step 2 rebuilds the table, which is what actually discards the old data,
shrinks the `.ibd` and delivers the performance win.

You can run step 1 immediately and defer step 2 to a convenient window.
Rqwatch will run either way.

If the server does not support instant drops, step 1 falls back to a
rebuild and does both jobs at once --- see
[If the instant drop is refused](#if-the-instant-drop-is-refused).

---

## Before you start

- Take a backup. This is a migration that destroys data.
- Confirm `db:migrate_mail_log_data` is recorded as `completed`. The
  migration refuses to run otherwise.\
  Check `migrations` table for `20260731_mail_log_data` with a status of `completed`.
- Run the data verification below.

### Verify the data was migrated

**These queries are slow.** On a large installation expect many minutes,
possibly much longer. Run them once, when the system is idle, and do not
run them inside a maintenance window you are trying to keep short.

Every `mail_logs` row must have a `mail_log_data` row. The migration
inserts one for every row, even when all three values are NULL, so this
must return **0**:

```sql
SELECT COUNT(*) AS missing
  FROM mail_logs ml
  LEFT JOIN mail_log_data md ON md.mail_log_id = ml.id
 WHERE md.mail_log_id IS NULL;
```

A non-zero result means the backfill never finished. Fill the gaps and
re-check before going further:

```
./bin/cli.php db:migrate_mail_log_data -f
```

`-f` continues and fills gaps. It never re-copies or truncates anything.

Optionally, confirm the copies agree wherever the original is still
populated. This is the slowest of the two, because it reads both copies:

```sql
SELECT COUNT(*) AS mismatched
  FROM mail_logs ml
  JOIN mail_log_data md ON md.mail_log_id = ml.id
 WHERE (ml.headers      IS NOT NULL AND NOT CAST(ml.headers      AS BINARY) <=> CAST(md.headers      AS BINARY))
    OR (ml.symbols      IS NOT NULL AND NOT CAST(ml.symbols      AS BINARY) <=> CAST(md.symbols      AS BINARY))
    OR (ml.fuzzy_hashes IS NOT NULL AND NOT CAST(ml.fuzzy_hashes AS BINARY) <=> CAST(md.fuzzy_hashes AS BINARY));
```

This must also return **0**. Rows written after the migration completed
have NULL in `mail_logs` and the real value in `mail_log_data`, which is
why the predicate only compares where the original is not NULL.

If the table is too large to check exhaustively, bound it by id and sample
a few ranges:

```sql
... AND ml.id BETWEEN 1 AND 100000
```

---

## Step 1 --- drop the columns

Run on **one** API server only. The schema change replicates if run on Galera.

```
./bin/cli.php db:migrate_drop_mail_log_columns
```

The command is deliberately **not** part of `db:migrate`, and the migration
is **not** in the required set. `db:migrate` skips it and says so. The
application boots and runs whether or not you have done this.

On success it reports either an instant drop or a rebuild:

```
Columns dropped instantly, no rebuild
```

A fresh installation created from `contrib/db-init.sql` never had these
columns. The migration notices, records itself as completed and changes
nothing.

### If the instant drop is refused

`ALGORITHM=INSTANT` needs MariaDB 10.4 or later (MDEV-15562) and a row
format other than `COMPRESSED`. If the server refuses it, the migration
logs the reason and retries with `ALGORITHM=INPLACE, LOCK=NONE`, which
rebuilds the table.

```
ALGORITHM=INSTANT was refused by the server
Rebuilding mail_logs. On a Galera cluster this blocks writes on every node
until it finishes.
```

That rebuild **needs free disk space for a second copy of the table**, and it
does the work of step 2 as well --- you do not need `db:optimize_table`
afterwards.

If the rebuild is interrupted, MariaDB rolls it back and the columns are
still there. The migration status stays at `running`; simply run the
command again.

---

## Step 2 --- reclaim the space

Only needed if step 1 reported an instant drop.

```
./bin/cli.php db:optimize_table -t mail_logs
```

The `-t` option defaults to `mail_logs`. `mail_log_data`,
`mail_log_recipients` and `mail_log_tokens` are also accepted --- useful
after a large `cron:cleanupdb` run.

On InnoDB, `OPTIMIZE TABLE` is mapped to `ALTER TABLE ... FORCE`: it
rebuilds the table and needs free space for a second copy. The command
prints sizes before and after.

Note that `information_schema` reports free space *inside* the tablespace
file, so after an instant drop it will not show how much dead column data
is still in there. To see the real effect, compare the size of the
`mail_logs.ibd` file on disk before and after.

---

## During a rebuild, mail is delivered but not recorded in Rqwatch

While writes to `mail_logs` are blocked, the API cannot record incoming
mail. Rspamd's request to Rqwatch fails but the mail is still delivered,
but no row appears in Rqwatch for it.

Rather than letting those requests fail, stop feeding them for the
duration:

- Stop cron jobs on all API servers: `systemctl stop crond`
- Disable the rspamd action that posts metadata to Rqwatch, or stop
  accepting mail, for the length of the window
- Run the rebuild
- Re-enable rspamd, then start cron jobs again

Measure the rebuild on a restored copy first so you know what window you
actually need. Dropping columns that account for most of an 8 GB table
means reading 8 GB and writing perhaps 1-2 GB, which is usually minutes
rather than hours.

---

## Manual SQL

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
