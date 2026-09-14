# DB Migration Guide

Database Migrations should be done when the system is as idle as possible.\
You should avoid busy system hours.

## API Servers
- Stop cron jobs **on all API servers**:\
  `systemctl stop crond`

- Update code from git **on all API servers**
  ```
  git fetch --tags origin

  git describe --tags --abbrev=0

  git checkout v2.1.0
  ```

- Update dependencies\
  `composer install`

- `composer dump-autoload` will be needed if you have run it in the past

- Start the migration. Run on **one** API server only. Schema changes replicate on Galera.\
  If you run multiple API servers and each one with a separate DB, then you must
  run it on **all API servers**.
  `./bin/cli.php db:migrate`

- Start cron jobs **on all API servers**:\
  `systemctl start crond`

## Web Servers
- Update code from git **on all WEB servers**
  ```
  git fetch --tags origin

  git describe --tags --abbrev=0

  git checkout v2.1.0
  ```

- Update dependencies\
  `composer install`

- `composer dump-autoload` will be needed if you have run it in the past
