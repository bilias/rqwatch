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
set -eu

if [ -n "${CI:-}" ]; then
	sh qa/taint-stubs.sh --check
else
	sh qa/taint-stubs.sh
fi

exec vendor/bin/psalm -c qa/psalm.xml --taint-analysis --no-cache --ignore-baseline --no-progress "$@"
