#!/bin/sh

# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.

# Psalm taint analysis, from the project root. The vendor taint stub is
# checked first in CI (fails if stale) and regenerated locally, so the
# analysis never runs on a stale stub. Extra arguments go to psalm.
#
# Only the code rqwatch deploys is analysed: the projectFiles of
# qa/psalm.xml without tests/. A test's input is its harness's environment
# (getenv(), a source since Psalm 7.19), and the rows a test inserts would
# flow into the app's reads of the same columns, so tests would only add
# findings no attacker can reach. qa/taint-selftest.sh has its own config.
set -eu

if [ -n "${CI:-}" ]; then
	sh qa/taint-stubs.sh --check
else
	sh qa/taint-stubs.sh
fi

exec vendor/bin/psalm -c qa/psalm.xml --taint-analysis --no-cache --ignore-baseline --no-progress "$@" \
	app bin web config qa
