#!/bin/bash
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# End-to-end tests: a real rspamd ($RSPAMD_IMAGE) scans test mails and its
# metadata_exporter, contrib's rule pointed at a copy of this checkout served
# by httpd and PHP-FPM in a container (qa/lib.sh, with PHP the version of
# $PHP), posts them to the importer;
# tests/EndToEnd compares what was stored
# with rspamd's own verdict. Rspamd also runs contrib's maps, reading the map
# files this copy generates from tests/EndToEnd/fixtures.sql. Redis holds
# rspamd's fuzzy storage, which this copy learns into through its fuzzy API,
# and, in the REDIS_ENABLE=true leg, this copy's caches and its failed-import
# spool (the spool tests skip in the off leg). Two legs on the same
# containers, off then on, each from a reseeded database and an empty Redis;
# E2E_REDIS=0 or 1 runs only that leg, and a failing leg does not stop the
# other. MariaDB, Redis and rspamd run as rootless Podman
# containers, rspamd on the host network so it reaches the web server on
# loopback. The admin pages are served too, for the web side of fuzzy
# learning; mx2 is a second storing node whose API is this copy's own, so a
# learn of an mx2 mail goes through fuzzy_mail.php as on a remote node.
# -v prints one line per test (local runs); other arguments go to phpunit.
# Run from the project root.
set -euo pipefail
. qa/lib.sh
phpunit_args "$@"

MARIADB_IMAGE="${MARIADB_IMAGE:-docker.io/library/mariadb:10.11}"
RSPAMD_IMAGE="${RSPAMD_IMAGE:-docker.io/rspamd/rspamd:4}"
REDIS_IMAGE="${REDIS_IMAGE:-docker.io/library/redis:7}"
ADMIN_RCPT=admin-notify@rqwatch.test
# Redis legs for this copy (REDIS_ENABLE): off, then on; E2E_REDIS=0 or 1
# runs only that one. rspamd's fuzzy storage uses Redis either way
LEGS="${E2E_REDIS:-0 1}"
case $LEGS in
	0 | 1 | "0 1") ;;
	*) echo "E2E_REDIS: 0, 1 or unset" >&2; exit 1 ;;
esac
REDIS_ENABLE=false
make_workdir

# database, with a clock unlike PHP's (unless PHP runs at UTC+5), so the
# tests see any mix of PHP and database time
DB_PASS=import-test
start_mariadb "$MARIADB_IMAGE" "$DB_PASS" --default-time-zone=+05:00

# redis
start_redis "$REDIS_IMAGE"

copy_tree

WEB_PORT=$(free_port)
NORMAL_PORT=$(free_port)
CONTROLLER_PORT=$(free_port)
PROXY_PORT=$(free_port)
FUZZY_PORT=$(free_port)
UI_PORT=$(free_port)
ADMIN_PASS=$("$PHP" -r 'echo bin2hex(random_bytes(16));')
API_PASS=$("$PHP" -r 'echo bin2hex(random_bytes(16));')
MAIL_API_PASS=$("$PHP" -r 'echo bin2hex(random_bytes(16));')
APP_DB_PASS=$("$PHP" -r 'echo bin2hex(random_bytes(16));')
write_env -e "s#^MY_API_SERVER_ALIAS=.*#MY_API_SERVER_ALIAS=mx1#" \
	-e "s#^WEB_HOST=.*#WEB_HOST=127.0.0.1:${UI_PORT}#" \
	-e "s#^WEB_HOST_NOTIFICATIONS=.*#WEB_HOST_NOTIFICATIONS=127.0.0.1:${UI_PORT}#" \
	-e "s#^WEB_SCHEME=.*#WEB_SCHEME=http#" \
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
	-e "s#^REDIS_ENABLE=.*#REDIS_ENABLE=${REDIS_ENABLE}#" \
	-e "s#^REDIS_DSN=.*#REDIS_DSN=\"redis://127.0.0.1:${REDIS_PORT}\"#"

# fuzzy learning through this server's own rspamd controller (a secure_ip,
# so no password); a fallback charset unlike the default, for 8-bit headers;
# an admin recipient from the start: the importer enrolls stored discard and
# reject mail in the admin feed only while one is set (assertQuarantined checks)
cat > config/config.local.php <<PHP
<?php
\$mail_fallback_charset = 'Windows-1253';
\$admin_notification_rcpt = '${ADMIN_RCPT}';
\$fuzzy_learn_enable = true;
\$API_SERVERS = [
	'mx1' => [
		'url' => 'http://127.0.0.1:${WEB_PORT}',
		'fuzzy_url' => 'http://127.0.0.1:${CONTROLLER_PORT}',
		'stat_url' => 'http://127.0.0.1:${CONTROLLER_PORT}/stat',
		'options' => [],
	],
	'mx2' => [
		'url' => 'http://127.0.0.1:${WEB_PORT}',
		'fuzzy_url' => 'http://127.0.0.1:${CONTROLLER_PORT}',
		'stat_url' => 'http://127.0.0.1:${CONTROLLER_PORT}/stat',
		'options' => [],
	],
];
PHP

# schema, users and map entries, from scratch: run again for each later leg
seed_e2e() {
	# db-init.sql's DROP TABLEs fail on a populated database (foreign keys)
	$DB_EXEC -e 'DROP DATABASE IF EXISTS rqwatch'
	$DB_EXEC < contrib/db-init.sql
	# rqwatch's own account: without SUPER, so the tests can refuse its writes
	# with read_only (root, used by the tests themselves, is exempt); a server
	# account, so replaced rather than created on a reseed
	$DB_EXEC -e "CREATE OR REPLACE USER 'rqwatch'@'%' IDENTIFIED BY '${APP_DB_PASS}'; GRANT ALL ON rqwatch.* TO 'rqwatch'@'%'"

	# map entries (owned by an admin) and the map files rspamd reads; fresh
	# tables give the entries the same ids, so the files come out the same
	"$PHP" bin/cli.php user:add -a -p "$ADMIN_PASS" admin admin@rqwatch.test >/dev/null
	$DB_EXEC rqwatch < tests/EndToEnd/fixtures.sql
	mkdir -p web/maps
	"$PHP" bin/cli.php cron:updatemapfiles
	local map
	for map in ip_blacklist mail_from_blacklist mime_from_blacklist mail_from_whitelist mail_from_rcpt_to_blacklist; do
		[ "$(grep -vc '^#' "web/maps/${map}.txt" 2>/dev/null)" = 1 ] \
			|| { echo "web/maps/${map}.txt: expected one entry" >&2; exit 1; }
	done
}
seed_e2e

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
	cat tests/EndToEnd/rspamd/local.d/worker-fuzzy.inc; } > rspamd/local.d/worker-fuzzy.inc
cp contrib/rspamd/local.d/fuzzy_group.conf rspamd/local.d/
cp contrib/rspamd/local.d/force_actions.conf contrib/rspamd/local.d/actions.conf \
	contrib/rspamd/local.d/groups.conf tests/EndToEnd/rspamd/local.d/worker-normal.inc rspamd/local.d/
cp tests/EndToEnd/rspamd/rspamd.local.lua rspamd/
chmod -R a+rX rspamd

# web server: the API and the map files on WEB_PORT, the admin pages on
# UI_PORT
start_web_container /css/style.css "$WEB_PORT" "$UI_PORT"

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

echo "rspamd $(curl -s "http://127.0.0.1:${CONTROLLER_PORT}/stat" | "$PHP" -r 'echo json_decode(stream_get_contents(STDIN), true)["version"] ?? "?";')," \
	"MariaDB $($DB_EXEC -N -e 'SELECT VERSION()')," \
	"Redis $(podman exec "$REDIS_CID" redis-cli INFO server | sed -n 's/^redis_version:\([^\r]*\).*/\1/p')"

status=0
first=true
for leg in $LEGS; do
	[ "$leg" = 1 ] && REDIS_ENABLE=true || REDIS_ENABLE=false
	sed -i "s#^REDIS_ENABLE=.*#REDIS_ENABLE=${REDIS_ENABLE}#" .env
	# a later leg starts from the state the first had: an empty quarantine (but
	# q/tmp, the web container's upload dir), an empty Redis (rspamd's fuzzy
	# storage, the spool), this leg's lines only in rqwatch.log, a reseeded
	# database; rspamd, the web server and the map files stay
	if [ "$first" = false ]; then
		find q -mindepth 1 -maxdepth 1 ! -name tmp -exec rm -rf {} +
		[ -f logs/rqwatch.log ] && : > logs/rqwatch.log
		# rspamd writes learned hashes in batches every sync (1s, the test's
		# worker-fuzzy.inc) plus up to half that as jitter: let a learn the
		# previous leg's last test queued land before the flush, not after it
		sleep 2
		podman exec "$REDIS_CID" redis-cli FLUSHALL >/dev/null
		seed_e2e
	fi
	first=false
	echo "=== End to end, REDIS_ENABLE=${REDIS_ENABLE}"

	leg_status=0
	E2E_RSPAMD_URL="http://127.0.0.1:${NORMAL_PORT}" \
	E2E_ROOT="$WORK" \
	E2E_REDIS_PORT="$REDIS_PORT" \
	E2E_REDIS_ENABLED="$REDIS_ENABLE" \
	E2E_WEB_URL="http://127.0.0.1:${WEB_PORT}" \
	E2E_UI_URL="http://127.0.0.1:${UI_PORT}" \
	E2E_ADMIN_PASS="$ADMIN_PASS" \
	E2E_MAIL_API_USER="$(sed -n 's/^MAIL_API_USER=//p' .env)" \
	E2E_MAIL_API_PASS="$MAIL_API_PASS" \
	E2E_QUARANTINE_DIR="${WORK}/q" \
	E2E_DB_DSN="mysql:host=127.0.0.1;port=${DB_PORT};dbname=rqwatch;charset=utf8mb4" \
	E2E_DB_USER=root \
	E2E_DB_PASS="$DB_PASS" \
		"$PHP" vendor/bin/phpunit -c qa/phpunit-e2e.xml "${PHPUNIT_ARGS[@]}" || leg_status=$?

	if [ "$leg_status" != 0 ]; then
		status=$leg_status
		if [ -s logs/rqwatch.log ]; then
			echo "--- logs/rqwatch.log (REDIS_ENABLE=${REDIS_ENABLE}) ---" >&2
			tail -50 logs/rqwatch.log >&2
		fi
		echo "--- rspamd (exporter, force_actions and task lines) ---" >&2
		podman logs "$RSPAMD_CID" 2>&1 | grep -E 'metadata_exporter|force_actions|task_write_log' | tail -30 >&2 || true
		echo "--- rspamd (map fetches) ---" >&2
		podman logs "$RSPAMD_CID" 2>&1 | grep -F '/maps/' | grep -v rspamd_map_add | tail -40 >&2 || true
	fi
done
exit "$status"
