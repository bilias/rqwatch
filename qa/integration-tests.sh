#!/bin/bash
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Integration tests: runs a copy of this checkout against a throwaway MariaDB
# loaded with contrib/db-init.sql and tests/Integration/fixtures.sql, serves
# it with php -S (the UI through the router, the mail APIs from a second
# server without it) and runs tests/Integration: route protection and API
# access (Access/), a smoke pass over every route (Smoke/), the retention
# crons through bin/cli.php (Cron/), data scoping (Scoping/) and web release
# into Mailpit (Delivery/). Your .env, config.local.php and databases are
# never touched.
#
# Locally it starts rootless Podman containers ($MARIADB_IMAGE, $MAILPIT_IMAGE).
# In CI set INTEGRATION_DB_EXEC (a mariadb client command), INTEGRATION_DB_HOST,
# INTEGRATION_DB_PORT and INTEGRATION_DB_PASS for an existing server, and
# INTEGRATION_MAILPIT_SMTP_PORT and INTEGRATION_MAILPIT_HTTP_PORT for an
# existing Mailpit on 127.0.0.1, instead.
# -v prints one line per test (local runs); other arguments go to phpunit.
# Run from the project root.
set -euo pipefail
. qa/lib.sh
phpunit_args "$@"

MARIADB_IMAGE="${MARIADB_IMAGE:-docker.io/library/mariadb:10.11}"
MAILPIT_IMAGE="${MAILPIT_IMAGE:-docker.io/axllent/mailpit:v1}"
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
	-e "s#^DB_HOST=.*#DB_HOST=${DB_HOST}#" \
	-e "s#^DB_PORT=.*#DB_PORT=${DB_PORT}#" \
	-e "s#^DB_NAME=.*#DB_NAME=rqwatch#" \
	-e "s#^DB_USER=.*#DB_USER=root#" \
	-e "s#^DB_PASS=.*#DB_PASS=${DB_PASS}#" \
	-e "s#^REDIS_ENABLE=.*#REDIS_ENABLE=false#" \
	-e "s#^API_ENABLE=.*#API_ENABLE=true#" \
	-e "s#^RSPAMD_API_PASS=.*#RSPAMD_API_PASS=${RSPAMD_API_PASS}#" \
	-e "s#^RSPAMD_API_ACL=.*#RSPAMD_API_ACL=\"127.0.0.1\"#" \
	-e "s#^MAIL_API_PASS=.*#MAIL_API_PASS=${MAIL_API_PASS}#" \
	-e "s#^MAIL_API_ACL=.*#MAIL_API_ACL=\"127.0.0.1\"#" \
	-e "s#^MAILER_DSN=.*#MAILER_DSN=\"smtp://127.0.0.1:${MAILPIT_SMTP_PORT}\"#" \
	-e "s#^MAILER_FROM=.*#MAILER_FROM=rqwatch@example.org#"

# mx2 is a second storing node: its API is this instance's own API server, so
# a web release of an mx2 mail goes through release_mail.php like a remote one;
# no rspamd runs, so its stat_url fails fast, as the smoke test expects
cat > config/config.local.php <<PHP
<?php
\$API_SERVERS = ['mx2' => [
	'url' => 'http://127.0.0.1:${API_PORT}',
	'stat_url' => 'http://127.0.0.1:9/stat',
	'options' => [],
]];
PHP

# schema, users, test data, quarantined mails
$DB_EXEC < contrib/db-init.sql
php bin/cli.php user:add -a -p pw-admin admin admin@example.org >/dev/null
php bin/cli.php user:add -p pw-alice alice alice@example.org >/dev/null
php bin/cli.php user:add -p pw-bob bob bob@example.org >/dev/null
sed "s#@Q@#${WORK}/q#g" tests/Integration/fixtures.sql | $DB_EXEC rqwatch

eml() { # id subject recipient
	cat > "q/$1.eml" <<-MAIL
	From: sender@example.net
	To: $3
	Subject: $2
	Message-ID: <$2@example.net>
	MIME-Version: 1.0
	Content-Type: multipart/mixed; boundary="BOUNDARY"

	--BOUNDARY
	Content-Type: text/plain; charset=utf-8

	Body of $2
	--BOUNDARY
	Content-Type: text/plain; name="attach-$2.txt"
	Content-Disposition: attachment; filename="attach-$2.txt"

	ATTACHMENT-$2
	--BOUNDARY--
	MAIL
}
eml 101 SUBJ-ALICE-101 alice@example.org
eml 102 SUBJ-BOB-102 bob@example.org
eml 103 SUBJ-ALIAS-103 alias-alice@example.org

# web server, through the router
start_web "$WEB_PORT" /login web/index.php
# the API scripts are files under web/api/: served without the router
start_web "$API_PORT" /css/style.css

status=0
INTEGRATION_BASE_URL="http://127.0.0.1:${WEB_PORT}" \
INTEGRATION_API_URL="http://127.0.0.1:${API_PORT}" \
INTEGRATION_MAILPIT_URL="http://127.0.0.1:${MAILPIT_HTTP_PORT}" \
INTEGRATION_ROOT="$WORK" \
INTEGRATION_WEB_LOG="${WORK}/logs/php-server-${WEB_PORT}.log" \
INTEGRATION_RSPAMD_API_USER="$(sed -n 's/^RSPAMD_API_USER=//p' .env)" \
INTEGRATION_RSPAMD_API_PASS="$RSPAMD_API_PASS" \
INTEGRATION_MAIL_API_USER="$(sed -n 's/^MAIL_API_USER=//p' .env)" \
INTEGRATION_MAIL_API_PASS="$MAIL_API_PASS" \
INTEGRATION_DB_DSN="mysql:host=${DB_HOST};port=${DB_PORT};dbname=rqwatch" \
INTEGRATION_DB_USER=root \
INTEGRATION_DB_PASS="$DB_PASS" \
	vendor/bin/phpunit -c qa/phpunit-integration.xml "${PHPUNIT_ARGS[@]}" || status=$?

if [ "$status" != 0 ] && [ -s logs/rqwatch.log ]; then
	echo "--- logs/rqwatch.log ---" >&2
	tail -50 logs/rqwatch.log >&2
fi
exit "$status"
