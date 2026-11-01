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
# QA_PHP lists the binaries, default "php8.3 php8.4" (Debian/Ubuntu names);
# set it for other layouts, e.g. QA_PHP="php83 php84" (Remi) or full paths.
# A missing binary fails the run: every suite must pass on each version.
# Stops at the first failing version. Run from the project root.
set -eu

versions=${QA_PHP:-php8.3 php8.4}

# all present before any run starts
for php in $versions; do
	if ! command -v "$php" >/dev/null 2>&1; then
		echo "each-php: '$php' not found; install it or set QA_PHP" >&2
		exit 1
	fi
done

for php in $versions; do
	echo "=== $("$php" -r 'echo "PHP ", PHP_VERSION;') ($php): $*"
	PHP="$php" "$@"
done
