#!/bin/bash
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# ZAP scan: runs a copy of this checkout against a throwaway MariaDB seeded
# like the integration suite (seed_test_data), serves it with httpd and
# PHP-FPM ($PHP_STREAM from Remi, default remi-8.4) as docs/INSTALL.md sets
# them up, in a Rocky 9 container built from qa/zap/Containerfile, and runs
# the OWASP ZAP Automation Framework plan qa/zap/rqwatch.yaml once per user
# in $ZAP_USERS (default: alice, then admin): spider, passive scan, active
# scan. Exits non-zero on a High alert or a plan error in any pass. Into
# $ZAP_OUT (default zap-report/) go, per USER: the HTML and JSON reports
# (rqwatch-zap-USER.*), ZAP's own log (zap-USER.log) and rqwatch's log lines
# of that pass (rqwatch-USER.log); and the copy's whole logs/ at the end.
# While a pass runs, follow ZAP in zap-home-USER/zap.log (each active scan
# rule as it starts and ends) and rqwatch in logs-live/rqwatch.log. The active
# scan submits every form it finds; only the copy and its database are
# touched, and mail goes to the null transport.
#
# ZAP runs in rootless Podman ($ZAP_IMAGE) on the host network, with the
# add-ons in $ZAP_ADDONS installed from the ZAP marketplace at the start of
# each pass (default: the beta active and passive scan rules; the image
# already has the release ones). For a local run with the alpha rules too:
#   ZAP_ADDONS="ascanrulesBeta pscanrulesBeta ascanrulesAlpha" qa/zap-scan.sh
# The database
# is a Podman container ($MARIADB_IMAGE) unless INTEGRATION_DB_EXEC,
# INTEGRATION_DB_HOST, INTEGRATION_DB_PORT and INTEGRATION_DB_PASS name an
# existing server, as for qa/integration-tests.sh.
# Run from the project root.
set -euo pipefail
. qa/lib.sh

MARIADB_IMAGE="${MARIADB_IMAGE:-docker.io/library/mariadb:10.11}"
ZAP_IMAGE="${ZAP_IMAGE:-ghcr.io/zaproxy/zaproxy:stable}"
PHP_STREAM="${PHP_STREAM:-remi-8.4}"
WEB_IMAGE="localhost/rqwatch-zap-web:${PHP_STREAM}"
ZAP_ADDONS="${ZAP_ADDONS-ascanrulesBeta pscanrulesBeta}"
addon_args=()
for addon in $ZAP_ADDONS; do
	addon_args+=(-addoninstall "$addon")
done
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

# no rspamd here: without this, every admin page ZAP requests looks up the
# example API_SERVERS from config.php and logs an ERROR for each
cat > config/config.local.php <<'PHP'
<?php
$rspamd_stat_disable = true;
PHP

seed_test_data

# The web server, in its own container on the host network: the copy is
# mounted where contrib/apache-rqwatch.conf expects it, and at $WORK too,
# where .env's QUARANTINE_DIR points. keep-id maps the invoking user to the
# image's rqwatch user (uid 1000), so PHP writes the copy as the invoking
# user and the cleanup can remove it; the logs PHP-FPM's master opens as
# root are created here first for the same reason.
echo "=== Building ${WEB_IMAGE} (base image and packages updated each run)"
old_image=$(podman image inspect -f '{{.Id}}' "$WEB_IMAGE" 2>/dev/null || true)
podman build -q --pull=newer -t "$WEB_IMAGE" --build-arg PHP_STREAM="$PHP_STREAM" \
	--build-arg UPDATE_STAMP="$(date +%s)" qa/zap >/dev/null
# the previous build, now untagged; kept if a concurrent run still uses it
if [ -n "$old_image" ] && [ "$old_image" != "$(podman image inspect -f '{{.Id}}' "$WEB_IMAGE")" ]; then
	podman rmi "$old_image" >/dev/null 2>&1 || true
fi
echo "=== Done building ${WEB_IMAGE} ==="
chmod 755 "$WORK"
mkdir -p q/tmp fpm/session
chmod 700 fpm
touch logs/php-slow.log logs/php-error.log
WEB_CID=$(podman run -d --rm --network=host --user root \
	--userns=keep-id:uid=1000,gid=1000 \
	-v "${WORK}:/var/www/html/rqwatch:Z" -v "${WORK}:${WORK}:Z" \
	-e WEB_PORT="$WEB_PORT" -e UPLOAD_TMP_DIR="${WORK}/q/tmp" \
	"$WEB_IMAGE" bash /var/www/html/rqwatch/qa/zap/web-start.sh)
LIB_CIDS+=("$WEB_CID")
for i in $(seq 30); do
	curl -fs -o /dev/null "${BASE}/login" && break
	if [ "$i" = 30 ]; then
		echo "Web server did not start" >&2
		podman logs "$WEB_CID" >&2
		exit 1
	fi
	sleep 1
done

mkdir -p "$OUT"
# what serves the scan: PHP, httpd and the PHP packages with their versions
podman exec "$WEB_CID" sh -c 'php-fpm -v; echo; httpd -v; echo; rpm -q rocky-release httpd; rpm -qa "php*" | sort' \
	> "${OUT}/web-versions.txt"
# httpd's access and error logs and PHP-FPM's master log, as they are written,
# so a cancelled run keeps them too
podman logs -f "$WEB_CID" > "${OUT}/web-server.log" 2>&1 &
LIB_PIDS+=("$!")

# zap_login USER: logs USER (password pw-USER, from seed_test_data) in and
# sets ZAP_SESSION and ZAP_SELF_ID (the user's id, kept off its own edit form)
zap_login() {
	local user=$1 jar="${WORK}/cookies-$1" token code
	token=$(curl -fs -c "$jar" -b "$jar" "${BASE}/login" \
		| sed -n 's/.*name="login_form\[_token\]"[^>]*value="\([^"]*\)".*/\1/p')
	curl -fs -o /dev/null -c "$jar" -b "$jar" "${BASE}/login" \
		--data-urlencode "login_form[username]=${user}" \
		--data-urlencode "login_form[password]=pw-${user}" \
		--data-urlencode "login_form[_token]=${token}"
	ZAP_SESSION=$(awk '$6 == "RQWATCHSESSID" { print $7 }' "$jar")
	# /profile answers every logged-in user; anyone else is redirected
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "RQWATCHSESSID=${ZAP_SESSION}" "${BASE}/profile")
	if [ -z "$ZAP_SESSION" ] || [ "$code" != 200 ]; then
		echo "Login as ${user} failed (GET /profile: ${code})" >&2
		exit 1
	fi
	ZAP_SELF_ID=$($DB_EXEC -N rqwatch -e "SELECT id FROM users WHERE username = '${user}'")
}

# One pass per user: a plain user reaches the user routes and the scoped
# queries, admin the admin routes. The plain user goes first: the admin
# pass's active scan submits the user edit forms and may change its password.
ZAP_USERS="${ZAP_USERS:-alice admin}"
mkdir -p "$OUT"
# the copy's logs as they are written; replaced by a copy at the end
ln -sfn "${WORK}/logs" "${OUT}/logs-live"
status=0
for user in $ZAP_USERS; do
	echo "=== ZAP pass as ${user} (follow: ${OUT}/zap-home-${user}/zap.log)"
	# rqwatch's log lines from here on, the login included, are this pass's
	log_start=0
	if [ -f logs/rqwatch.log ]; then
		log_start=$(wc -l < logs/rqwatch.log)
	fi
	zap_login "$user"
	# ZAP's home on the host, for zap.log while the pass runs
	zap_home="${OUT}/zap-home-${user}"
	rm -rf "$zap_home"
	mkdir -p "$zap_home"
	rm -rf zap
	mkdir zap
	cp qa/zap/rqwatch.yaml zap/
	# keep-id: ZAP's uid 1000 writes the reports as the invoking user;
	# ZAP_AUTH_HEADER*: ZAP sets this Cookie header on every request it sends;
	# no pids limit: ZAP's threads outgrow Podman's default
	podman run --rm --pull=newer --network=host --userns=keep-id:uid=1000,gid=1000 \
		--pids-limit=-1 -v "${WORK}/zap:/zap/wrk:Z" -v "${zap_home}:/home/zap/.ZAP:Z" \
		-e ZAP_TARGET="$BASE" -e ZAP_SELF_ID="$ZAP_SELF_ID" \
		-e ZAP_AUTH_HEADER=Cookie -e ZAP_AUTH_HEADER_VALUE="RQWATCHSESSID=${ZAP_SESSION}" \
		"$ZAP_IMAGE" zap.sh -cmd -host 127.0.0.1 -port "$(free_port)" \
		"${addon_args[@]}" -autorun /zap/wrk/rqwatch.yaml || status=$?
	for report in zap/rqwatch-zap.*; do
		if [ -e "$report" ]; then
			cp "$report" "${OUT}/rqwatch-zap-${user}.${report##*.}"
		fi
	done
	# keep zap.log; the rest of the home (session database, add-ons) is ZAP's scratch
	if [ -f "${zap_home}/zap.log" ]; then
		cp "${zap_home}/zap.log" "${OUT}/zap-${user}.log"
	fi
	rm -rf "$zap_home"
	if [ -f logs/rqwatch.log ]; then
		tail -n "+$((log_start + 1))" logs/rqwatch.log > "${OUT}/rqwatch-${user}.log"
	fi
done

rm -f "${OUT}/logs-live"
cp -r logs "$OUT/"
echo "ZAP reports and logs: ${OUT}"
exit "$status"
