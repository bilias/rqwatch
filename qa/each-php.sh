#!/bin/sh
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Runs a test harness once per supported PHP version, with $PHP set to that
# version's binary (qa/lib.sh uses it for every step), e.g.
#   sh qa/each-php.sh bash qa/unit-tests.sh -v
# QA_PHP lists the versions, default "8.3 8.4". Each is found as phpX.Y
# (Debian/Ubuntu), phpXY (Remi), /opt/remi/phpXY/root/usr/bin/php or php,
# whichever reports that version; an entry that is not X.Y is a binary used
# as given (no spaces). A version not found is skipped with a warning, or
# fails the run with QA_PHP_STRICT=1; CI runs every version. Stops at the
# first failing run. Run from the project root.
set -eu

# major.minor of a PHP binary, empty if it does not run
php_version() {
	"$1" -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;' 2>/dev/null || true
}

# the first binary that reports version $1, empty if none
find_php() {
	nodot=$(echo "$1" | tr -d .)
	for candidate in "php$1" "php$nodot" "/opt/remi/php$nodot/root/usr/bin/php" php; do
		command -v "$candidate" >/dev/null 2>&1 || continue
		if [ "$(php_version "$candidate")" = "$1" ]; then
			echo "$candidate"
			return
		fi
	done
}

binaries=
missing=
for entry in ${QA_PHP:-8.3 8.4}; do
	case $entry in
		[0-9]*.[0-9]*) php=$(find_php "$entry") ;;
		*) php=$entry; [ -n "$(php_version "$php")" ] || php= ;;
	esac
	if [ -n "$php" ]; then
		binaries="$binaries $php"
	else
		missing="$missing $entry"
	fi
done

if [ -n "$missing" ]; then
	echo "each-php: PHP not found, not tested here:$missing" >&2
	[ "${QA_PHP_STRICT:-0}" = 1 ] && exit 1
fi
if [ -z "$binaries" ]; then
	echo "each-php: no PHP to run; install one or set QA_PHP" >&2
	exit 1
fi

for php in $binaries; do
	echo "=== $("$php" -r 'echo "PHP ", PHP_VERSION;') ($php): $*"
	PHP="$php" "$@"
done

if [ -n "$missing" ]; then
	echo "each-php: not tested here:$missing (CI runs every version)" >&2
fi
