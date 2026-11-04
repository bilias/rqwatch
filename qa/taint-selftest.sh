#!/bin/sh

# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.

# Taint self-test, from the project root: Psalm's taint analysis on the
# planted probes in qa/taint-selftest/ must report exactly what their
# "// expect" markers say. A clean composer psalm-taint cannot tell a
# working setup from a broken one (a plugin or Psalm update, a lost
# @psalm-taint-escape); this can.
set -eu

dir=$(mktemp -d)
trap 'rm -rf "$dir"' EXIT
report=$dir/report.json  # Psalm picks the format by extension

# exits non-zero when it reports the probes, which it must
vendor/bin/psalm -c qa/taint-selftest/psalm.xml --taint-analysis --no-cache \
	--no-progress --report="$report" --report-show-info=false >/dev/null || true

php qa/taint-selftest.php < "$report"
