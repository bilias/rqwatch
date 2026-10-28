#!/bin/bash
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Data-scoping tests: runs a copy of this checkout against a throwaway
# MariaDB loaded with contrib/db-init.sql and tests/Scoping/fixtures.sql,
# serves it with php -S and runs tests/Scoping over HTTP. Your .env,
# config.local.php and databases are never touched.
#
# Locally it starts a rootless Podman container ($MARIADB_IMAGE). In CI set
# SCOPING_DB_EXEC (a mariadb client command), SCOPING_DB_HOST, SCOPING_DB_PORT
# and SCOPING_DB_PASS for an existing server instead. Extra arguments go to
# phpunit. Run from the project root.
set -euo pipefail

MARIADB_IMAGE="${MARIADB_IMAGE:-docker.io/library/mariadb:10.11@sha256:6c65a843396dc6c78dd0da19eccaa0a91d25d9d26a4c2afcb75010ac3f59f5ea}"
SRC=$(pwd)
WORK=$(mktemp -d)
CID=
WEB_PID=

cleanup() {
	[ -n "$WEB_PID" ] && kill "$WEB_PID" 2>/dev/null || true
	[ -n "$CID" ] && podman rm -f "$CID" >/dev/null 2>&1 || true
	rm -rf "$WORK"
}
trap cleanup EXIT

free_port() {
	php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'
}

# database
if [ -z "${SCOPING_DB_EXEC:-}" ]; then
	DB_HOST=127.0.0.1
	DB_PORT=$(free_port)
	DB_PASS=scoping-test
	CID=$(podman run -d --rm -e MARIADB_ROOT_PASSWORD="$DB_PASS" \
		-p "127.0.0.1:${DB_PORT}:3306" --tmpfs /var/lib/mysql "$MARIADB_IMAGE")
	DB_EXEC="podman exec -i $CID mariadb -uroot -p$DB_PASS"
	for i in $(seq 60); do
		podman exec "$CID" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 && break
		[ "$i" = 60 ] && { echo "MariaDB did not start" >&2; exit 1; }
		sleep 1
	done
else
	DB_HOST="$SCOPING_DB_HOST"
	DB_PORT="$SCOPING_DB_PORT"
	DB_PASS="$SCOPING_DB_PASS"
	DB_EXEC="$SCOPING_DB_EXEC"
fi

# a copy of the working tree (including uncommitted changes) and vendor/
git ls-files -co --exclude-standard -z | tar --null -T - -cf - | tar -xf - -C "$WORK"
cp -a vendor "$WORK/"
cd "$WORK"
mkdir -p logs q

WEB_PORT=$(free_port)
sed -e "s#^WEB_HOST=.*#WEB_HOST=127.0.0.1:${WEB_PORT}#" \
	-e "s#^WEB_HOST_NOTIFICATIONS=.*#WEB_HOST_NOTIFICATIONS=127.0.0.1:${WEB_PORT}#" \
	-e "s#^WEB_SCHEME=.*#WEB_SCHEME=http#" \
	-e "s#^MY_API_SERVER_ALIAS=.*#MY_API_SERVER_ALIAS=mx1#" \
	-e "s#^QUARANTINE_DIR=.*#QUARANTINE_DIR=${WORK}/q#" \
	-e "s#^FAILED_LOGIN_TIMEOUT=.*#FAILED_LOGIN_TIMEOUT=0#" \
	-e "s#^DB_HOST=.*#DB_HOST=${DB_HOST}#" \
	-e "s#^DB_PORT=.*#DB_PORT=${DB_PORT}#" \
	-e "s#^DB_NAME=.*#DB_NAME=rqwatch#" \
	-e "s#^DB_USER=.*#DB_USER=root#" \
	-e "s#^DB_PASS=.*#DB_PASS=${DB_PASS}#" \
	-e "s#^MAILER_DSN=.*#MAILER_DSN=\"null://null\"#" \
	-e "s#^REDIS_ENABLE=.*#REDIS_ENABLE=false#" \
	-e "s#^LDAP_AUTH_ENABLED=.*#LDAP_AUTH_ENABLED=false#" \
	-e "s#^OPENIDC_AUTH_ENABLED=.*#OPENIDC_AUTH_ENABLED=false#" \
	.env-example > .env

# schema, users, test data, quarantined mails
$DB_EXEC < contrib/db-init.sql
php bin/cli.php user:add -a -p pw-admin admin admin@example.org >/dev/null
php bin/cli.php user:add -p pw-alice alice alice@example.org >/dev/null
php bin/cli.php user:add -p pw-bob bob bob@example.org >/dev/null
sed "s#@Q@#${WORK}/q#g" tests/Scoping/fixtures.sql | $DB_EXEC rqwatch

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

# web server
php -S "127.0.0.1:${WEB_PORT}" -t web web/index.php >logs/php-server.log 2>&1 &
WEB_PID=$!
for i in $(seq 30); do
	curl -fs -o /dev/null "http://127.0.0.1:${WEB_PORT}/login" && break
	[ "$i" = 30 ] && { echo "Web server did not start" >&2; cat logs/php-server.log >&2; exit 1; }
	sleep 1
done

status=0
SCOPING_BASE_URL="http://127.0.0.1:${WEB_PORT}" \
SCOPING_DB_DSN="mysql:host=${DB_HOST};port=${DB_PORT};dbname=rqwatch" \
SCOPING_DB_USER=root \
SCOPING_DB_PASS="$DB_PASS" \
	vendor/bin/phpunit -c qa/phpunit-scoping.xml "$@" || status=$?

if [ "$status" != 0 ] && [ -s logs/rqwatch.log ]; then
	echo "--- logs/rqwatch.log ---" >&2
	tail -50 logs/rqwatch.log >&2
fi
exit "$status"
