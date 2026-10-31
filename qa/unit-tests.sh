#!/bin/bash
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Unit tests (no database, server or network): tests/Unit/Helpers, the
# helpers on their own, and tests/Unit/Routing, route access levels and the
# access middlewares (--testsuite routing runs those alone). -v prints one
# line per test (local runs); other arguments go to phpunit. Run from the
# project root.
set -euo pipefail
. qa/lib.sh
phpunit_args "$@"

exec vendor/bin/phpunit -c qa/phpunit-unit.xml "${PHPUNIT_ARGS[@]}"
