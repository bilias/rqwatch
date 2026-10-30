# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Shared steps of the test harnesses (qa/*-tests.sh); sourced, not run, from
# the project root. make_workdir creates $WORK; whatever is started through
# start_container or start_web is stopped, and $WORK removed, on exit.

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
	php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'
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

# start_web PORT PROBE_PATH [ROUTER]: php -S on the copy's web/, waiting
# until PROBE_PATH answers
start_web() {
	local port=$1 probe=$2 i
	shift 2
	php -S "127.0.0.1:${port}" -t web "$@" >logs/php-server.log 2>&1 &
	LIB_PIDS+=("$!")
	for i in $(seq 30); do
		curl -fs -o /dev/null "http://127.0.0.1:${port}${probe}" && return 0
		sleep 1
	done
	echo "Web server did not start" >&2
	cat logs/php-server.log >&2
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
