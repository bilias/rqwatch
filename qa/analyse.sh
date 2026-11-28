#!/bin/sh
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Runs PHPStan and Psalm once per target PHP version, as CI does. The target
# is configuration (PHPStan phpVersion, Psalm --php-version), not the PHP that
# runs the tools, so a version need not be installed. QA_ANALYSE_PHP lists the
# versions, default "8.3 8.4 8.5" (keep in sync with the matrix in ci.yml).
# Every version runs even if one fails; the exit status says if any did.
# Run from the project root.
set -u

export TMPDIR="${TMPDIR:-/var/tmp}"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
trap 'exit 130' INT TERM

status=0
for v in ${QA_ANALYSE_PHP:-8.3 8.4 8.5}; do
	case ${v} in
		[0-9]*.[0-9]*) ;;
		*) echo "analyse: not a version: ${v}" >&2; exit 2 ;;
	esac
	id=$(echo "${v}" | awk -F. '{ printf "%d%02d00", $1, $2 }')
	printf 'includes:\n\t- %s/qa/phpstan.neon\nparameters:\n\tphpVersion: %s\n' \
		"${PWD}" "${id}" > "${tmp}/phpstan.neon"
	echo "=== PHPStan, PHP ${v}"
	vendor/bin/phpstan analyse -c "${tmp}/phpstan.neon" --memory-limit=512M --error-format friendly || status=1
	echo "=== Psalm, PHP ${v}"
	vendor/bin/psalm -c qa/psalm.xml --no-progress --no-cache --php-version="${v}" || status=1
done
exit "${status}"
