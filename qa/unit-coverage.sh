#!/bin/bash
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Code coverage of the unit suite: which app/ lines and methods no unit test
# runs, to find what to test next (Infection mutates only covered code, so
# it cannot show this). Local only, not CI. Reports go to $COVERAGE_OUT
# (default coverage-report/, ignored by git): clover.xml (per line),
# coverage.txt (per class) and html/ (browse it); the summary is printed.
# coverage.txt lists only classes with some coverage: the rest have none.
# Extra arguments go to phpunit (--testsuite helpers, say). Needs a coverage
# driver: pcov (dnf install php-pecl-pcov) or Xdebug. Run from the project
# root.
set -euo pipefail
. qa/lib.sh

# a variable, not a pipe: grep -q would close it early and pipefail fail it
modules=$("$PHP" -m)
php_opts=()
if grep -qix pcov <<< "$modules"; then
	php_opts+=(-d pcov.enabled=1)
	driver=pcov
elif grep -qix xdebug <<< "$modules"; then
	export XDEBUG_MODE=coverage
	driver=Xdebug
else
	echo "no coverage driver: install pcov (dnf install php-pecl-pcov) or Xdebug" >&2
	exit 1
fi

OUT=$(realpath -m "${COVERAGE_OUT:-coverage-report}")
mkdir -p "$OUT"
# the previous run's pages for classes since removed would linger
rm -rf "${OUT}/html"

echo "=== Unit coverage, PHP $("$PHP" -r 'echo PHP_VERSION;'), ${driver}"
status=0
"$PHP" "${php_opts[@]}" vendor/bin/phpunit -c qa/phpunit-unit.xml \
	--coverage-clover "${OUT}/clover.xml" --coverage-text="${OUT}/coverage.txt" \
	--coverage-html "${OUT}/html" "$@" || status=$?
# phpunit colours the file as it does the terminal
if [ -f "${OUT}/coverage.txt" ]; then
	sed -i 's/\x1b\[[0-9;]*m//g' "${OUT}/coverage.txt"
fi
if [ "$status" -eq 0 ]; then
	sed -n '/Summary:/,/^$/p' "${OUT}/coverage.txt"
	echo "=== Unit coverage: reports in ${OUT}"
else
	echo "=== Unit coverage: failed (exit ${status}); reports in ${OUT}" >&2
fi
exit "$status"
