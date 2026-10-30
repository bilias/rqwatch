#!/bin/bash
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Access-control tests (no database or server needed). -v prints one line
# per test (local runs); other arguments go to phpunit. Run from the project
# root.
set -euo pipefail
. qa/lib.sh
phpunit_args "$@"

exec vendor/bin/phpunit -c qa/phpunit-access.xml "${PHPUNIT_ARGS[@]}"
