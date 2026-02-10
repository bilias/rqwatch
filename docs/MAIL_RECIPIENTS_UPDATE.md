# Mail Log Recipients update --- IMPORTANT NOTICE

Version 1.7+ of Rqwatch containes important update to DB schema for better performance
when mail logs are more than 250K.

Here is the procedure to apply this update:

- edit `.env` file and add `MAIL_RECIPIENTS_TABLE` entry:
  ```
  MAILLOGS_TABLE=mail_logs
  MAIL_RECIPIENTS_TABLE=mail_log_recipients
  ```

- Run the following SQL code:
  ```
  USE rqwatch;
  
  CREATE TABLE mail_log_recipients (
    mail_log_id INT(10) UNSIGNED NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    PRIMARY KEY (mail_log_id, recipient_email),
    KEY recipient_email_idx (recipient_email),
    CONSTRAINT fk_mail
      FOREIGN KEY (mail_log_id)
      REFERENCES mail_logs(id)
      ON DELETE CASCADE
  ) ENGINE=InnoDB;
  ```
  This script also exists in `contrib/updates/02-db-update-2026-01-09`
  but you don't have it yet.

- Stop cron jobs:\
`systemctl stop crond`

- Update code from git\
`git pull`

- Migrate mail recipient entries. This will take rcpt_to entries from mail_logs and insert them in `mail_log_recipients` table:
  ```
  ./bin/cli.php mail:migrate_mail_recipients
  ```

- Start cron jobs:\
`systemctl start crond`

