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
# files this copy generates from tests/Import/fixtures.sql. MariaDB and rspamd
# run as rootless Podman containers, rspamd on the host network so it reaches
# php -S on loopback. Local only. Extra arguments go to phpunit. Run from the
# project root.
set -euo pipefail

MARIADB_IMAGE="${MARIADB_IMAGE:-docker.io/library/mariadb:10.11}"
RSPAMD_IMAGE="${RSPAMD_IMAGE:-docker.io/rspamd/rspamd:4}"
WORK=$(mktemp -d)
DB_CID=
RSPAMD_CID=
WEB_PID=

cleanup() {
	[ -n "$WEB_PID" ] && kill "$WEB_PID" 2>/dev/null || true
	[ -n "$RSPAMD_CID" ] && podman rm -f "$RSPAMD_CID" >/dev/null 2>&1 || true
	[ -n "$DB_CID" ] && podman rm -f "$DB_CID" >/dev/null 2>&1 || true
	rm -rf "$WORK"
}
trap cleanup EXIT

free_port() {
	php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'
}

# database
DB_PORT=$(free_port)
DB_PASS=import-test
DB_CID=$(podman run -d --rm --pull=newer -e MARIADB_ROOT_PASSWORD="$DB_PASS" \
	-p "127.0.0.1:${DB_PORT}:3306" --tmpfs /var/lib/mysql "$MARIADB_IMAGE")
DB_EXEC="podman exec -i $DB_CID mariadb -uroot -p$DB_PASS"
for i in $(seq 60); do
	podman exec "$DB_CID" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 && break
	[ "$i" = 60 ] && { echo "MariaDB did not start" >&2; exit 1; }
	sleep 1
done

# a copy of the working tree (including uncommitted changes) and vendor/
git ls-files -co --exclude-standard -z | tar --null -T - -cf - | tar -xf - -C "$WORK"
cp -a vendor "$WORK/"
cd "$WORK"
mkdir -p logs q

WEB_PORT=$(free_port)
API_PASS=$(php -r 'echo bin2hex(random_bytes(16));')
sed -e "s#^MY_API_SERVER_ALIAS=.*#MY_API_SERVER_ALIAS=mx1#" \
	-e "s#^QUARANTINE_DIR=.*#QUARANTINE_DIR=${WORK}/q#" \
	-e "s#^DB_HOST=.*#DB_HOST=127.0.0.1#" \
	-e "s#^DB_PORT=.*#DB_PORT=${DB_PORT}#" \
	-e "s#^DB_NAME=.*#DB_NAME=rqwatch#" \
	-e "s#^DB_USER=.*#DB_USER=root#" \
	-e "s#^DB_PASS=.*#DB_PASS=${DB_PASS}#" \
	-e "s#^API_ENABLE=.*#API_ENABLE=true#" \
	-e "s#^RSPAMD_API_PASS=.*#RSPAMD_API_PASS=${API_PASS}#" \
	-e "s#^RSPAMD_API_ACL=.*#RSPAMD_API_ACL=\"127.0.0.1\"#" \
	-e "s#^MAILER_DSN=.*#MAILER_DSN=\"null://null\"#" \
	-e "s#^REDIS_ENABLE=.*#REDIS_ENABLE=false#" \
	-e "s#^LDAP_AUTH_ENABLED=.*#LDAP_AUTH_ENABLED=false#" \
	-e "s#^OPENIDC_AUTH_ENABLED=.*#OPENIDC_AUTH_ENABLED=false#" \
	.env-example > .env

$DB_EXEC < contrib/db-init.sql

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
# contrib's maps reading this copy's map files, contrib's force_actions,
# actions and groups, and the test's rules and single normal worker
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
cp contrib/rspamd/local.d/force_actions.conf contrib/rspamd/local.d/actions.conf \
	contrib/rspamd/local.d/groups.conf tests/Import/rspamd/local.d/worker-normal.inc rspamd/local.d/
cp tests/Import/rspamd/rspamd.local.lua rspamd/
chmod -R a+rX rspamd

# web server: only the API is needed, so no router
php -S "127.0.0.1:${WEB_PORT}" -t web >logs/php-server.log 2>&1 &
WEB_PID=$!
for i in $(seq 30); do
	curl -fs -o /dev/null "http://127.0.0.1:${WEB_PORT}/css/style.css" && break
	[ "$i" = 30 ] && { echo "Web server did not start" >&2; cat logs/php-server.log >&2; exit 1; }
	sleep 1
done

# rspamd
NORMAL_PORT=$(free_port)
CONTROLLER_PORT=$(free_port)
PROXY_PORT=$(free_port)
RSPAMD_CID=$(podman run -d --rm --pull=newer --network host \
	-e RSPAMD_LOCAL_ADDR=127.0.0.1 \
	-e RSPAMD_PORT_NORMAL="$NORMAL_PORT" \
	-e RSPAMD_PORT_CONTROLLER="$CONTROLLER_PORT" \
	-e RSPAMD_PORT_PROXY="$PROXY_PORT" \
	-v "${WORK}/rspamd:/etc/rspamd:ro,Z" "$RSPAMD_IMAGE")
for i in $(seq 60); do
	curl -fs -o /dev/null "http://127.0.0.1:${NORMAL_PORT}/ping" && break
	[ "$i" = 60 ] && { echo "rspamd did not start" >&2; podman logs --tail 50 "$RSPAMD_CID" >&2; exit 1; }
	sleep 1
done

echo "rspamd $(curl -s "http://127.0.0.1:${CONTROLLER_PORT}/stat" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["version"] ?? "?";')," \
	"MariaDB $($DB_EXEC -N -e 'SELECT VERSION()')"

status=0
IMPORT_RSPAMD_URL="http://127.0.0.1:${NORMAL_PORT}" \
IMPORT_QUARANTINE_DIR="${WORK}/q" \
IMPORT_DB_DSN="mysql:host=127.0.0.1;port=${DB_PORT};dbname=rqwatch;charset=utf8mb4" \
IMPORT_DB_USER=root \
IMPORT_DB_PASS="$DB_PASS" \
	vendor/bin/phpunit -c qa/phpunit-import.xml --testdox "$@" || status=$?

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
