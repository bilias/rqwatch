# Mail Log Recipients update --- IMPORTANT NOTICE

Versions 1.7.0 - 1.7.4 of Rqwatch containes important update to DB schema for better performance.

If you are on version 1.7.5+ you can follow the [DB_MIGRATION_GUIDE](DB_MIGRATION.md).

Here is the procedure to apply this update:

- Stop cron jobs on all API servers:\
`systemctl stop crond`

- Update code from git on all API servers\
`git pull`

- Update dependencies\
`composer install`

- Migrate mail recipient entries. This will take rcpt_to entries from mail_logs and insert them in `mail_log_recipients` table:
  ```
  ./bin/cli.php db:migrate_mail_recipients
  ```

- Start cron jobs on all API servers:\
`systemctl start crond`

- Update code from git on all WEB servers\
`git pull`

- Update dependencies\
`composer install`

- `composer dump-autoload` will be needed if you have run it in the past

