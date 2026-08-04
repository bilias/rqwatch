# DB Migration Guide

Database Migrations should be done when the system is as idle as possible.\
You should avoid busy system hours.

- Stop cron jobs **on all API servers**:\
`systemctl stop crond`

- Update code from git **on all API servers**\
`git pull`

- Update dependencies\
`composer install`

- `composer dump-autoload` will be needed if you have run it in the past

- Start the migration **on ONE API server ONLY**\
`./bin/cli.php db:migrate`

- Start cron jobs **on all API servers**:\
`systemctl start crond`

- Update code from git **on all WEB servers**\
`git pull`

- Update dependencies\
`composer install`

- `composer dump-autoload` will be needed if you have run it in the past
