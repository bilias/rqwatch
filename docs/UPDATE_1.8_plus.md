# Rqwatch 1.8+ Update instructions

## WARNING
Rqwatch 1.8+ has been tested with Rspamd 4.x.x+ and contains important **changes in the DB schema**.

DB Migrations are required:
- [MAIL_RECIPIENTS Migration](MAIL_RECIPIENTS_UPDATE.md) is also included incase it was missed

- CREATED_DAY Migration

- MAIL_LOG_DATA Migration

## Update instructions

### Local mode (single-host)

If you run on one server only, the update can be done live without service disruption.
The system detects the migration status and stores data appropriately.

- Follow the [UPGRADE GUIDE](UPGRADE.md) to get the latest code

- Follow the [DB MIGRATION GUIDE](DB_MIGRATION.md) to perform the database migrations

Please make sure you have available space in MySQL data path, at least the size of Rqwatch database.

### Distributed mode

If you run with multiple API servers, then:

- Follow the [UPGRADE GUIDE](UPGRADE.md) to get the latest code **on all API servers/WEB servers**

After code has been updated on all servers:

- Follow the [DB MIGRATION GUIDE](DB_MIGRATION.md) to perform the database migrations **on one API server only**

The Migration subsystem detects the migration status (pending/finished/completed) and performs writes to the DB appropriately. 
