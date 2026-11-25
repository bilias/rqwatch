#!/bin/sh
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# ShellCheck every tracked shell script (*.sh), from the project root, so -x
# follows the harnesses' `. qa/lib.sh`. Extra arguments go to shellcheck.
# Exits non-zero on any finding. Needs shellcheck (the CI runner image has
# it; Fedora: dnf install ShellCheck).
set -eu
if ! command -v shellcheck >/dev/null 2>&1; then
	echo "shellcheck not found: install it (dnf install ShellCheck; on EL9 from EPEL)" >&2
	exit 127
fi
git ls-files -z '*.sh' | xargs -0 shellcheck -a -x "$@"
