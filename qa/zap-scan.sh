#!/bin/bash
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# ZAP scan: runs a copy of this checkout against a throwaway MariaDB seeded
# like the integration suite (seed_test_data), serves it with php -S and runs
# the OWASP ZAP Automation Framework plan qa/zap/rqwatch.yaml as admin:
# spider, passive scan, active scan. Exits non-zero on a High alert or a plan
# error. The HTML and JSON reports and the copy's logs/ go to $ZAP_OUT
# (default zap-report/). The active scan submits every form it finds; only
# the copy and its database are touched, and mail goes to the null transport.
#
# ZAP runs in rootless Podman ($ZAP_IMAGE) on the host network. The database
# is a Podman container ($MARIADB_IMAGE) unless INTEGRATION_DB_EXEC,
# INTEGRATION_DB_HOST, INTEGRATION_DB_PORT and INTEGRATION_DB_PASS name an
# existing server, as for qa/integration-tests.sh.
# Run from the project root.
set -euo pipefail
. qa/lib.sh

MARIADB_IMAGE="${MARIADB_IMAGE:-docker.io/library/mariadb:10.11}"
ZAP_IMAGE="${ZAP_IMAGE:-ghcr.io/zaproxy/zaproxy:stable}"
OUT=$(realpath -m "${ZAP_OUT:-zap-report}")
make_workdir

# database
if [ -z "${INTEGRATION_DB_EXEC:-}" ]; then
	DB_HOST=127.0.0.1
	DB_PASS=zap-scan
	start_mariadb "$MARIADB_IMAGE" "$DB_PASS"
else
	DB_HOST="$INTEGRATION_DB_HOST"
	DB_PORT="$INTEGRATION_DB_PORT"
	DB_PASS="$INTEGRATION_DB_PASS"
	DB_EXEC="$INTEGRATION_DB_EXEC"
fi

copy_tree

WEB_PORT=$(free_port)
BASE="http://127.0.0.1:${WEB_PORT}"
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
	-e "s#^REDIS_ENABLE=.*#REDIS_ENABLE=false#"

seed_test_data

# ZAP sends requests in parallel: php -S forks this many workers
export PHP_CLI_SERVER_WORKERS=4
start_web "$WEB_PORT" /login web/index.php

# log in as admin; ZAP sends this session cookie with every request
jar="${WORK}/cookies"
token=$(curl -fs -c "$jar" -b "$jar" "${BASE}/login" \
	| sed -n 's/.*name="login_form\[_token\]"[^>]*value="\([^"]*\)".*/\1/p')
curl -fs -o /dev/null -c "$jar" -b "$jar" "${BASE}/login" \
	--data-urlencode "login_form[username]=admin" \
	--data-urlencode "login_form[password]=pw-admin" \
	--data-urlencode "login_form[_token]=${token}"
ZAP_SESSION=$(awk '$6 == "RQWATCHSESSID" { print $7 }' "$jar")
code=$(curl -s -o /dev/null -w '%{http_code}' -b "RQWATCHSESSID=${ZAP_SESSION}" "${BASE}/admin")
if [ -z "$ZAP_SESSION" ] || [ "$code" != 200 ]; then
	echo "Login as admin failed (GET /admin: ${code})" >&2
	exit 1
fi
# the plan keeps the scan off the admin's own edit form
ZAP_ADMIN_ID=$($DB_EXEC -N rqwatch -e "SELECT id FROM users WHERE username = 'admin'")

mkdir zap
cp qa/zap/rqwatch.yaml zap/
status=0
# keep-id: ZAP's uid 1000 writes the reports as the invoking user
podman run --rm --pull=newer --network=host --userns=keep-id:uid=1000,gid=1000 \
	-v "${WORK}/zap:/zap/wrk:Z" \
	-e ZAP_TARGET="$BASE" -e ZAP_SESSION="$ZAP_SESSION" -e ZAP_ADMIN_ID="$ZAP_ADMIN_ID" \
	"$ZAP_IMAGE" zap.sh -cmd -host 127.0.0.1 -port "$(free_port)" \
	-autorun /zap/wrk/rqwatch.yaml || status=$?

mkdir -p "$OUT"
cp zap/rqwatch-zap.* "$OUT/" 2>/dev/null || true
cp -r logs "$OUT/"
echo "ZAP reports and logs: ${OUT}"
exit "$status" 
