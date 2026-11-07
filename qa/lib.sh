# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Shared steps of the test harnesses (qa/*-tests.sh); sourced, not run, from
# the project root. make_workdir creates $WORK; whatever is started through
# start_container or start_web_container is stopped, and $WORK removed, on
# exit. $PHP is the PHP every host step runs (phpunit, bin/cli.php);
# qa/each-php.sh sets it per version, otherwise the php on PATH. The copy is
# served by httpd and PHP-FPM in a container (start_web_container), locally
# and in CI.

PHP="${PHP:-php}"
LIB_CIDS=()
LIB_PIDS=()
WORK=

lib_cleanup() {
	local pid cid
	for pid in "${LIB_PIDS[@]}"; do
		kill "$pid" 2>/dev/null || true
	done
	for cid in "${LIB_CIDS[@]}"; do
		podman rm -f "$cid" >/dev/null 2>&1 || true
	done
	[ -n "$WORK" ] && rm -rf "$WORK"
	return 0
}

make_workdir() {
	WORK=$(mktemp -d)
	trap lib_cleanup EXIT
}

free_port() {
	"$PHP" -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'
}

# start_container VAR PODMAN_RUN_ARGS...: podman run -d --rm --pull=newer,
# the container id stored in VAR (a variable, not output: a $(...) subshell
# would lose the cleanup record)
start_container() {
	local var=$1 cid
	shift
	cid=$(podman run -d --rm --pull=newer "$@")
	LIB_CIDS+=("$cid")
	printf -v "$var" '%s' "$cid"
}

# start_mailpit IMAGE: a throwaway Mailpit on free loopback ports; sets
# MAILPIT_CID, MAILPIT_SMTP_PORT and MAILPIT_HTTP_PORT and waits until it answers
start_mailpit() {
	MAILPIT_SMTP_PORT=$(free_port)
	MAILPIT_HTTP_PORT=$(free_port)
	start_container MAILPIT_CID -p "127.0.0.1:${MAILPIT_SMTP_PORT}:1025" \
		-p "127.0.0.1:${MAILPIT_HTTP_PORT}:8025" "$1"
	wait_mailpit
}

# wait_mailpit: until the Mailpit API on MAILPIT_HTTP_PORT answers
wait_mailpit() {
	local i
	for i in $(seq 30); do
		curl -fs -o /dev/null "http://127.0.0.1:${MAILPIT_HTTP_PORT}/readyz" && return 0
		sleep 1
	done
	echo "Mailpit did not start" >&2
	exit 1
}

# start_redis IMAGE: a throwaway Redis on a free loopback port; sets REDIS_CID
# and REDIS_PORT and waits until it answers
start_redis() {
	REDIS_PORT=$(free_port)
	start_container REDIS_CID -p "127.0.0.1:${REDIS_PORT}:6379" "$1"
	wait_redis
}

# wait_redis: until Redis on REDIS_PORT answers PING (plain TCP, no client needed)
wait_redis() {
	local i reply
	for i in $(seq 30); do
		# a port forwarder may accept before Redis listens: resets are retried quietly
		reply=$({ exec 3<>"/dev/tcp/127.0.0.1/${REDIS_PORT}" && printf 'PING\r\n' >&3 \
			&& timeout 2 head -c 5 <&3; } 2>/dev/null) || reply=
		[ "$reply" = "+PONG" ] && return 0
		sleep 1
	done
	echo "Redis did not start" >&2
	exit 1
}

# start_mariadb IMAGE ROOT_PASSWORD [SERVER_ARGS...]: a throwaway MariaDB on
# a free loopback port; sets DB_CID, DB_PORT and DB_EXEC (a root client)
start_mariadb() {
	local image=$1 pass=$2 i
	shift 2
	DB_PORT=$(free_port)
	start_container DB_CID -e MARIADB_ROOT_PASSWORD="$pass" \
		-p "127.0.0.1:${DB_PORT}:3306" --tmpfs /var/lib/mysql "$image" "$@"
	DB_EXEC="podman exec -i $DB_CID mariadb -uroot -p$pass"
	for i in $(seq 60); do
		podman exec "$DB_CID" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 && return 0
		sleep 1
	done
	echo "MariaDB did not start" >&2
	exit 1
}

# copy_tree: the working tree (including uncommitted changes) and vendor/
# into $WORK, then cd there, with logs/ and q/ (the quarantine)
copy_tree() {
	git ls-files -co --exclude-standard -z | tar --null -T - -cf - | tar -xf - -C "$WORK"
	cp -a vendor "$WORK/"
	cd "$WORK"
	mkdir -p logs q
}

# write_env SED_ARGS...: .env from .env-example with the settings every
# harness wants (quarantine in $WORK/q, mail to the null transport, no LDAP
# or OpenID Connect) plus the caller's sed expressions
write_env() {
	sed -e "s#^QUARANTINE_DIR=.*#QUARANTINE_DIR=${WORK}/q#" \
		-e "s#^MAILER_DSN=.*#MAILER_DSN=\"null://null\"#" \
		-e "s#^LDAP_AUTH_ENABLED=.*#LDAP_AUTH_ENABLED=false#" \
		-e "s#^OPENIDC_AUTH_ENABLED=.*#OPENIDC_AUTH_ENABLED=false#" \
		"$@" .env-example > .env
}

# start_web_container PROBE_PATH PORT...: the copy served by httpd and PHP-FPM
# as docs/INSTALL.md sets them up, in a Rocky 9 container built from
# qa/web/Containerfile on the host network, on 127.0.0.1 at each PORT; waits
# until PROBE_PATH answers on the first. PHP is Remi's $PHP_STREAM, by default
# the version of $PHP (remi-X.Y), so host and web steps match. The container's
# output (httpd's logs, PHP-FPM's master log) streams to $WEB_SERVER_LOG,
# default logs/web-server.log; PHP's own errors go to logs/php-error.log.
# The packages are updated once a day (the first run of the day rebuilds the
# image's last layer), or on every run with QA_WEB_UPDATE=1.
# Sets WEB_CID. Run in the copy, after copy_tree.
start_web_container() {
	local probe=$1 image old_image stamp i
	shift
	PHP_STREAM="${PHP_STREAM:-remi-$("$PHP" -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')}"
	image="localhost/rqwatch-qa-web:${PHP_STREAM}"
	if [ "${QA_WEB_UPDATE:-0}" = 1 ]; then
		stamp=$(date +%s)
	else
		stamp=$(date +%F)
	fi
	echo "=== Building ${image} (packages updated: ${stamp})"
	old_image=$(podman image inspect -f '{{.Id}}' "$image" 2>/dev/null || true)
	podman build -q --pull=newer --force-rm -t "$image" --build-arg PHP_STREAM="$PHP_STREAM" \
		--build-arg UPDATE_STAMP="$stamp" qa/web >/dev/null
	# the previous build, now untagged; kept if a concurrent run still uses it
	if [ -n "$old_image" ] && [ "$old_image" != "$(podman image inspect -f '{{.Id}}' "$image")" ]; then
		podman rmi "$old_image" >/dev/null 2>&1 || true
	fi
	# The copy is mounted where contrib/apache-rqwatch.conf expects it, and at
	# $WORK too, where .env's QUARANTINE_DIR points. keep-id maps the invoking
	# user to the image's rqwatch user (uid 1000), so PHP writes the copy as the
	# invoking user and the cleanup can remove it; the logs PHP-FPM's master
	# opens as root are created here first for the same reason.
	chmod 755 "$WORK"
	mkdir -p q/tmp fpm/session
	chmod 700 fpm
	touch logs/php-slow.log logs/php-error.log
	# k8s-file: with journald, Podman's default on systemd hosts, every request
	# would also land in the system log
	WEB_CID=$(podman run -d --rm --network=host --user root --log-driver=k8s-file \
		--userns=keep-id:uid=1000,gid=1000 \
		-v "${WORK}:/var/www/html/rqwatch:Z" -v "${WORK}:${WORK}:Z" \
		-e WEB_PORTS="$*" -e UPLOAD_TMP_DIR="${WORK}/q/tmp" \
		"$image" bash /var/www/html/rqwatch/qa/web/web-start.sh)
	LIB_CIDS+=("$WEB_CID")
	podman logs -f "$WEB_CID" > "${WEB_SERVER_LOG:-logs/web-server.log}" 2>&1 &
	LIB_PIDS+=("$!")
	for i in $(seq 30); do
		curl -fs -o /dev/null "http://127.0.0.1:${1}${probe}" && return 0
		sleep 1
	done
	echo "Web server did not start" >&2
	podman logs "$WEB_CID" >&2
	exit 1
}

# phpunit_args HARNESS_ARGS...: -v becomes --testdox (one line per test, for
# local runs; CI leaves it out), anything else goes to phpunit as is; sets
# PHPUNIT_ARGS
phpunit_args() {
	local arg
	PHPUNIT_ARGS=()
	for arg in "$@"; do
		if [ "$arg" = -v ]; then
			PHPUNIT_ARGS+=(--testdox)
		else
			PHPUNIT_ARGS+=("$arg")
		fi
	done
}


# seed_test_data: the schema, users admin/alice/bob (passwords pw-<name>),
# tests/Integration/fixtures.sql and the quarantined mails it references,
# through $DB_EXEC; run in the copy, after copy_tree and write_env. The
# database is dropped first: db-init.sql's DROP TABLEs fail on a populated
# one (foreign keys), so this is what lets a harness seed it again
seed_test_data() {
	$DB_EXEC -e 'DROP DATABASE IF EXISTS rqwatch'
	$DB_EXEC < contrib/db-init.sql
	"$PHP" bin/cli.php user:add -a -p pw-admin admin admin@example.org >/dev/null
	"$PHP" bin/cli.php user:add -p pw-alice alice alice@example.org >/dev/null
	"$PHP" bin/cli.php user:add -p pw-bob bob bob@example.org >/dev/null
	sed "s#@Q@#${WORK}/q#g" tests/Integration/fixtures.sql | $DB_EXEC rqwatch
	seed_mail 101 SUBJ-ALICE-101 alice@example.org
	seed_mail 102 SUBJ-BOB-102 bob@example.org
	seed_mail 103 SUBJ-ALIAS-103 alias-alice@example.org
}

# seed_mail ID SUBJECT RECIPIENT: q/ID.eml, a text body and one attachment
seed_mail() {
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
