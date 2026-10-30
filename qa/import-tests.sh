#!/bin/bash
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Rspamd import tests: a real rspamd ($RSPAMD_IMAGE) scans test mails and its
# metadata_exporter, contrib's rule pointed at a copy of this checkout served
# by php -S, posts them to the importer; tests/Import compares what was stored
# with rspamd's own verdict. Rspamd also runs contrib's maps, reading the map
# files this copy generates from tests/Import/fixtures.sql. Redis holds the
# failed-import spool and rspamd's fuzzy storage, which this copy learns into
# through its fuzzy API. MariaDB, Redis and rspamd run as rootless Podman
# containers, rspamd on the host network so it reaches php -S on loopback.
# Extra arguments go to phpunit. Run from the project root.
set -euo pipefail
. qa/lib.sh

MARIADB_IMAGE="${MARIADB_IMAGE:-docker.io/library/mariadb:10.11}"
RSPAMD_IMAGE="${RSPAMD_IMAGE:-docker.io/rspamd/rspamd:4}"
REDIS_IMAGE="${REDIS_IMAGE:-docker.io/library/redis:7}"
make_workdir

# database, with a clock unlike PHP's (unless PHP runs at UTC+5), so the
# tests see any mix of PHP and database time
DB_PASS=import-test
start_mariadb "$MARIADB_IMAGE" "$DB_PASS" --default-time-zone=+05:00

# redis
REDIS_PORT=$(free_port)
start_container REDIS_CID -p "127.0.0.1:${REDIS_PORT}:6379" "$REDIS_IMAGE"
for i in $(seq 30); do
	[ "$(podman exec "$REDIS_CID" redis-cli ping 2>/dev/null)" = PONG ] && break
	[ "$i" = 30 ] && { echo "Redis did not start" >&2; exit 1; }
	sleep 1
done

copy_tree

WEB_PORT=$(free_port)
NORMAL_PORT=$(free_port)
CONTROLLER_PORT=$(free_port)
PROXY_PORT=$(free_port)
FUZZY_PORT=$(free_port)
API_PASS=$(php -r 'echo bin2hex(random_bytes(16));')
MAIL_API_PASS=$(php -r 'echo bin2hex(random_bytes(16));')
APP_DB_PASS=$(php -r 'echo bin2hex(random_bytes(16));')
write_env -e "s#^MY_API_SERVER_ALIAS=.*#MY_API_SERVER_ALIAS=mx1#" \
	-e "s#^DB_HOST=.*#DB_HOST=127.0.0.1#" \
	-e "s#^DB_PORT=.*#DB_PORT=${DB_PORT}#" \
	-e "s#^DB_NAME=.*#DB_NAME=rqwatch#" \
	-e "s#^DB_USER=.*#DB_USER=rqwatch#" \
	-e "s#^DB_PASS=.*#DB_PASS=${APP_DB_PASS}#" \
	-e "s#^API_ENABLE=.*#API_ENABLE=true#" \
	-e "s#^RSPAMD_API_PASS=.*#RSPAMD_API_PASS=${API_PASS}#" \
	-e "s#^RSPAMD_API_ACL=.*#RSPAMD_API_ACL=\"127.0.0.1\"#" \
	-e "s#^MAIL_API_PASS=.*#MAIL_API_PASS=${MAIL_API_PASS}#" \
	-e "s#^MAIL_API_ACL=.*#MAIL_API_ACL=\"127.0.0.1\"#" \
	-e "s#^REDIS_ENABLE=.*#REDIS_ENABLE=true#" \
	-e "s#^REDIS_DSN=.*#REDIS_DSN=\"redis://127.0.0.1:${REDIS_PORT}\"#"

# fuzzy learning through this server's own rspamd controller (a secure_ip,
# so no password); a fallback charset unlike the default, for 8-bit headers
cat > config/config.local.php <<PHP
<?php
\$mail_fallback_charset = 'Windows-1253';
\$fuzzy_learn_enable = true;
\$API_SERVERS = ['mx1' => [
	'url' => 'http://127.0.0.1:${WEB_PORT}',
	'fuzzy_url' => 'http://127.0.0.1:${CONTROLLER_PORT}',
	'options' => [],
]];
PHP

$DB_EXEC < contrib/db-init.sql
# rqwatch's own account: without SUPER, so the tests can refuse its writes
# with read_only (root, used by the tests themselves, is exempt)
$DB_EXEC -e "CREATE USER 'rqwatch'@'%' IDENTIFIED BY '${APP_DB_PASS}'; GRANT ALL ON rqwatch.* TO 'rqwatch'@'%'"

# map entries (owned by an admin) and the map files rspamd reads
php bin/cli.php user:add -a -p "$(php -r 'echo bin2hex(random_bytes(16));')" admin admin@rqwatch.test >/dev/null
$DB_EXEC rqwatch < tests/Import/fixtures.sql
mkdir -p web/maps
php bin/cli.php cron:updatemapfiles
for map in ip_blacklist mail_from_blacklist mime_from_blacklist mail_from_whitelist mail_from_rcpt_to_blacklist; do
	[ "$(grep -vc '^#' "web/maps/${map}.txt" 2>/dev/null)" = 1 ] \
		|| { echo "web/maps/${map}.txt: expected one entry" >&2; exit 1; }
done

# rspamd configuration: contrib's exporter rule pointed at this copy,
# contrib's maps reading this copy's map files, contrib's fuzzy storage on
# the test Redis, contrib's force_actions, actions and groups, and the
# test's rules and single normal worker
mkdir -p rspamd/local.d rspamd/lua.local.d
sed -e "s#^\([[:space:]]*url = \).*#\1\"http://127.0.0.1:${WEB_PORT}/api/metadata_importer_multipart.php?server=mx1\";#" \
	-e "s#^\([[:space:]]*password = \).*#\1\"${API_PASS}\";#" \
	contrib/rspamd/local.d/metadata_exporter.conf > rspamd/local.d/metadata_exporter.conf
if ! grep -q "127.0.0.1:${WEB_PORT}/" rspamd/local.d/metadata_exporter.conf \
	|| ! grep -q "\"${API_PASS}\"" rspamd/local.d/metadata_exporter.conf; then
	echo "contrib/rspamd/local.d/metadata_exporter.conf: url or password line not found" >&2
	exit 1
fi
for f in contrib/rspamd/local.d/multimap.conf contrib/rspamd/lua.local.d/rqwatch_*.lua; do
	dest="rspamd/${f#contrib/rspamd/}"
	sed "s#http://127.0.0.1/maps/#http://127.0.0.1:${WEB_PORT}/maps/#" "$f" > "$dest"
	grep -q "127.0.0.1:${WEB_PORT}/maps/" "$dest" || { echo "${f}: map URL not found" >&2; exit 1; }
done
sed "s#^\([[:space:]]*servers = \).*#\1\"127.0.0.1:${FUZZY_PORT}\";#" \
	contrib/rspamd/local.d/fuzzy_check.conf > rspamd/local.d/fuzzy_check.conf
grep -q "127.0.0.1:${FUZZY_PORT}" rspamd/local.d/fuzzy_check.conf \
	|| { echo "contrib/rspamd/local.d/fuzzy_check.conf: servers line not found" >&2; exit 1; }
{ cat contrib/rspamd/local.d/worker-fuzzy.inc; echo "servers = \"127.0.0.1:${REDIS_PORT}\";"
	cat tests/Import/rspamd/local.d/worker-fuzzy.inc; } > rspamd/local.d/worker-fuzzy.inc
cp contrib/rspamd/local.d/fuzzy_group.conf rspamd/local.d/
cp contrib/rspamd/local.d/force_actions.conf contrib/rspamd/local.d/actions.conf \
	contrib/rspamd/local.d/groups.conf tests/Import/rspamd/local.d/worker-normal.inc rspamd/local.d/
cp tests/Import/rspamd/rspamd.local.lua rspamd/
chmod -R a+rX rspamd

# web server: only the API is needed, so no router
start_web "$WEB_PORT" /css/style.css

# rspamd
start_container RSPAMD_CID --network host \
	-e RSPAMD_LOCAL_ADDR=127.0.0.1 \
	-e RSPAMD_PORT_NORMAL="$NORMAL_PORT" \
	-e RSPAMD_PORT_CONTROLLER="$CONTROLLER_PORT" \
	-e RSPAMD_PORT_PROXY="$PROXY_PORT" \
	-e RSPAMD_PORT_FUZZY="$FUZZY_PORT" \
	-v "${WORK}/rspamd:/etc/rspamd:ro,Z" "$RSPAMD_IMAGE"
for i in $(seq 60); do
	curl -fs -o /dev/null "http://127.0.0.1:${NORMAL_PORT}/ping" && break
	[ "$i" = 60 ] && { echo "rspamd did not start" >&2; podman logs --tail 50 "$RSPAMD_CID" >&2; exit 1; }
	sleep 1
done

echo "rspamd $(curl -s "http://127.0.0.1:${CONTROLLER_PORT}/stat" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["version"] ?? "?";')," \
	"MariaDB $($DB_EXEC -N -e 'SELECT VERSION()')," \
	"Redis $(podman exec "$REDIS_CID" redis-cli INFO server | sed -n 's/^redis_version:\([^\r]*\).*/\1/p')"

status=0
IMPORT_RSPAMD_URL="http://127.0.0.1:${NORMAL_PORT}" \
IMPORT_ROOT="$WORK" \
IMPORT_REDIS_PORT="$REDIS_PORT" \
IMPORT_WEB_URL="http://127.0.0.1:${WEB_PORT}" \
IMPORT_MAIL_API_USER="$(sed -n 's/^MAIL_API_USER=//p' .env)" \
IMPORT_MAIL_API_PASS="$MAIL_API_PASS" \
IMPORT_QUARANTINE_DIR="${WORK}/q" \
IMPORT_DB_DSN="mysql:host=127.0.0.1;port=${DB_PORT};dbname=rqwatch;charset=utf8mb4" \
IMPORT_DB_USER=root \
IMPORT_DB_PASS="$DB_PASS" \
	vendor/bin/phpunit -c qa/phpunit-import.xml "$@" || status=$?

if [ "$status" != 0 ]; then
	if [ -s logs/rqwatch.log ]; then
		echo "--- logs/rqwatch.log ---" >&2
		tail -50 logs/rqwatch.log >&2
	fi
	echo "--- rspamd (exporter, force_actions and task lines) ---" >&2
	podman logs "$RSPAMD_CID" 2>&1 | grep -E 'metadata_exporter|force_actions|task_write_log' | tail -30 >&2 || true
	echo "--- rspamd (map fetches) ---" >&2
	podman logs "$RSPAMD_CID" 2>&1 | grep -F '/maps/' | grep -v rspamd_map_add | tail -40 >&2 || true
fi
exit "$status"
