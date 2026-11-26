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
# Only the code rqwatch deploys is analysed: qa/psalm.xml without tests/,
# as a config written for this run. A test's input is its harness's
# environment (getenv(), a source since Psalm 7.19), and the rows a test
# inserts would flow into the app's reads of the same columns, so tests would
# only add findings no attacker can reach. A config, not a path list, keeps
# this a full run: projectFiles stays the one list of what is analysed, and
# Psalm prints no notice about the unused-handler check, which is off here
# (composer psalm keeps it). Paths in qa/psalm.xml are relative to the
# project root (resolveFromConfigFile="false"), so the copy works from a temp
# directory. qa/taint-selftest.sh has its own config.
set -eu

if [ -n "${CI:-}" ]; then
	sh qa/taint-stubs.sh --check
else
	sh qa/taint-stubs.sh
fi

dir=$(mktemp -d)
trap 'rm -rf "$dir"' EXIT
config=${dir}/psalm-taint.xml
sed -e '/^\t\t<directory name="tests" \/>$/d' \
	-e 's/^\tfindUnusedPsalmSuppress="true"$/&\n\tfindUnusedIssueHandlerSuppression="false"/' \
	qa/psalm.xml > "${config}"

# a reworded qa/psalm.xml must fail here, not quietly analyse tests/ again
if grep -q 'name="tests"' "${config}" || ! grep -q 'findUnusedIssueHandlerSuppression="false"' "${config}"; then
	echo "psalm-taint: qa/psalm.xml changed, update the config edits in $0" >&2
	exit 1
fi

vendor/bin/psalm -c "${config}" --taint-analysis --no-cache --ignore-baseline --no-progress "$@"
