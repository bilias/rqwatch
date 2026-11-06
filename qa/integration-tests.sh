#!/bin/bash
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Integration tests: runs a copy of this checkout against a throwaway MariaDB
# loaded with contrib/db-init.sql and tests/Integration/fixtures.sql, serves
# it on two ports (the UI, and the mail APIs as a second storing node) and
# runs tests/Integration: route protection, API
# access and the login throttle (Access/), a smoke pass over every route
# (Smoke/), the retention crons through bin/cli.php (Cron/), data scoping,
# map entries included (Scoping/), the add-entry form prefill (Maps/), and
# release (web and API), notifications and password-less links into Mailpit
# (Delivery/).
# Your .env, config.local.php and databases are never touched.
#
# The web server is $QA_WEB (qa/lib.sh): locally httpd and PHP-FPM in a
# container, as docs/INSTALL.md sets them up, with PHP the version of $PHP;
# in CI php -S, the UI through the router and the APIs from a second server
# without it.
#
# Redis is off (REDIS_ENABLE=false) unless INTEGRATION_REDIS=1 or
# INTEGRATION_REDIS_PORT is set: then sessions, the config and migration
# caches and the rest of the Redis paths are in use. Run it both ways.
#
# Locally it starts rootless Podman containers ($MARIADB_IMAGE, $MAILPIT_IMAGE,
# and $REDIS_IMAGE with INTEGRATION_REDIS=1). In CI set INTEGRATION_DB_EXEC (a
# mariadb client command), INTEGRATION_DB_HOST, INTEGRATION_DB_PORT and
# INTEGRATION_DB_PASS for an existing server, INTEGRATION_MAILPIT_SMTP_PORT and
# INTEGRATION_MAILPIT_HTTP_PORT for an existing Mailpit, and
# INTEGRATION_REDIS_PORT for an existing Redis, all on 127.0.0.1, instead.
# -v prints one line per test (local runs); other arguments go to phpunit.
# Run from the project root.
set -euo pipefail
. qa/lib.sh
phpunit_args "$@"

MARIADB_IMAGE="${MARIADB_IMAGE:-docker.io/library/mariadb:10.11}"
MAILPIT_IMAGE="${MAILPIT_IMAGE:-docker.io/axllent/mailpit:v1}"
REDIS_IMAGE="${REDIS_IMAGE:-docker.io/library/redis:7}"
make_workdir

# database
if [ -z "${INTEGRATION_DB_EXEC:-}" ]; then
	DB_HOST=127.0.0.1
	DB_PASS=integration-test
	start_mariadb "$MARIADB_IMAGE" "$DB_PASS"
else
	DB_HOST="$INTEGRATION_DB_HOST"
	DB_PORT="$INTEGRATION_DB_PORT"
	DB_PASS="$INTEGRATION_DB_PASS"
	DB_EXEC="$INTEGRATION_DB_EXEC"
fi

# mail capture
if [ -z "${INTEGRATION_MAILPIT_SMTP_PORT:-}" ]; then
	start_mailpit "$MAILPIT_IMAGE"
else
	MAILPIT_SMTP_PORT="$INTEGRATION_MAILPIT_SMTP_PORT"
	MAILPIT_HTTP_PORT="$INTEGRATION_MAILPIT_HTTP_PORT"
	wait_mailpit
fi

# Redis, when asked for
REDIS_ENABLE=false
if [ -n "${INTEGRATION_REDIS_PORT:-}" ]; then
	REDIS_PORT="$INTEGRATION_REDIS_PORT"
	wait_redis
	REDIS_ENABLE=true
elif [ "${INTEGRATION_REDIS:-0}" = 1 ]; then
	start_redis "$REDIS_IMAGE"
	REDIS_ENABLE=true
fi
echo "Redis: ${REDIS_ENABLE}${REDIS_PORT:+ (127.0.0.1:${REDIS_PORT})}"

copy_tree

WEB_PORT=$(free_port)
API_PORT=$(free_port)
RSPAMD_API_PASS="scoping-rspamd-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
MAIL_API_PASS="scoping-mail-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
write_env -e "s#^WEB_HOST=.*#WEB_HOST=127.0.0.1:${WEB_PORT}#" \
	-e "s#^WEB_HOST_NOTIFICATIONS=.*#WEB_HOST_NOTIFICATIONS=127.0.0.1:${WEB_PORT}#" \
	-e "s#^WEB_SCHEME=.*#WEB_SCHEME=http#" \
	-e "s#^MY_API_SERVER_ALIAS=.*#MY_API_SERVER_ALIAS=mx1#" \
	-e "s#^FAILED_LOGIN_TIMEOUT=.*#FAILED_LOGIN_TIMEOUT=0#" \
	-e "s/^#LOGIN_IPS_WHITELIST=$/LOGIN_IPS_WHITELIST=/" \
	-e "s#^DB_HOST=.*#DB_HOST=${DB_HOST}#" \
	-e "s#^DB_PORT=.*#DB_PORT=${DB_PORT}#" \
	-e "s#^DB_NAME=.*#DB_NAME=rqwatch#" \
	-e "s#^DB_USER=.*#DB_USER=root#" \
	-e "s#^DB_PASS=.*#DB_PASS=${DB_PASS}#" \
	-e "s#^REDIS_ENABLE=.*#REDIS_ENABLE=${REDIS_ENABLE}#" \
	-e "s#^REDIS_DSN=.*#REDIS_DSN=\"redis://127.0.0.1:${REDIS_PORT:-6379}\"#" \
	-e "s#^API_ENABLE=.*#API_ENABLE=true#" \
	-e "s#^RSPAMD_API_PASS=.*#RSPAMD_API_PASS=${RSPAMD_API_PASS}#" \
	-e "s#^RSPAMD_API_ACL=.*#RSPAMD_API_ACL=\"127.0.0.1\"#" \
	-e "s#^MAIL_API_PASS=.*#MAIL_API_PASS=${MAIL_API_PASS}#" \
	-e "s#^MAIL_API_ACL=.*#MAIL_API_ACL=\"127.0.0.1\"#" \
	-e "s#^MAILER_DSN=.*#MAILER_DSN=\"smtp://127.0.0.1:${MAILPIT_SMTP_PORT}\"#" \
	-e "s#^MAILER_FROM=.*#MAILER_FROM=rqwatch@example.org#"

# mx2 is a second storing node: its API is this instance's own API server, so
# a web release of an mx2 mail goes through release_mail.php like a remote one;
# no rspamd runs, so its stat_url fails fast, as the smoke test expects. Admin
# notifications go to ADMIN_RCPT, for rows a test enrolls (admin_notified = 0)
ADMIN_RCPT=admin-notify@example.org
# no rspamd here: no statistics fetching, which would log an ERROR per admin page;
# fuzzy learning on but no fuzzy_url, so fuzzy_mail.php refuses before rspamd
cat > config/config.local.php <<PHP
<?php
\$rspamd_stat_disable = true;
\$fuzzy_learn_enable = true;
\$admin_notification_rcpt = '${ADMIN_RCPT}';
\$API_SERVERS = ['mx2' => [
	'url' => 'http://127.0.0.1:${API_PORT}',
	'stat_url' => 'http://127.0.0.1:9/stat',
	'options' => [],
]];
PHP

# schema, users, test data, quarantined mails
seed_test_data

# web server; WEB_LOG is where PHP's warnings and errors go
if [ "$QA_WEB" = container ]; then
	start_web_container /login "$WEB_PORT" "$API_PORT"
	WEB_LOG="${WORK}/logs/php-error.log"
else
	# the UI through the router
	start_web "$WEB_PORT" /login qa/php-router.php
	# the API scripts are files under web/api/: served without the router
	start_web "$API_PORT" /css/style.css
	WEB_LOG="${WORK}/logs/php-server-${WEB_PORT}.log"
fi

status=0
INTEGRATION_BASE_URL="http://127.0.0.1:${WEB_PORT}" \
INTEGRATION_API_URL="http://127.0.0.1:${API_PORT}" \
INTEGRATION_MAILPIT_URL="http://127.0.0.1:${MAILPIT_HTTP_PORT}" \
INTEGRATION_ROOT="$WORK" \
INTEGRATION_REDIS_ENABLED="$REDIS_ENABLE" \
INTEGRATION_ADMIN_RCPT="$ADMIN_RCPT" \
INTEGRATION_REDIS_PORT="${REDIS_PORT:-}" \
INTEGRATION_WEB_LOG="$WEB_LOG" \
INTEGRATION_RSPAMD_API_USER="$(sed -n 's/^RSPAMD_API_USER=//p' .env)" \
INTEGRATION_RSPAMD_API_PASS="$RSPAMD_API_PASS" \
INTEGRATION_MAIL_API_USER="$(sed -n 's/^MAIL_API_USER=//p' .env)" \
INTEGRATION_MAIL_API_PASS="$MAIL_API_PASS" \
INTEGRATION_DB_DSN="mysql:host=${DB_HOST};port=${DB_PORT};dbname=rqwatch" \
INTEGRATION_DB_USER=root \
INTEGRATION_DB_PASS="$DB_PASS" \
	"$PHP" vendor/bin/phpunit -c qa/phpunit-integration.xml "${PHPUNIT_ARGS[@]}" || status=$?

if [ "$status" != 0 ] && [ -s logs/rqwatch.log ]; then
	echo "--- logs/rqwatch.log ---" >&2
	tail -50 logs/rqwatch.log >&2
fi
exit "$status"
