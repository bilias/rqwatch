#!/bin/sh

# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.

# Run Opengrep with the rqwatch rules and the pinned community rules, from
# the project root. Extra arguments go to opengrep scan (CI adds
# --sarif-output). Exits non-zero on any finding.
set -eu

sh qa/opengrep-rules.sh

exec opengrep scan --error \
	--config qa/opengrep.yml \
	--config .semgrep-rules/php \
	--config .semgrep-rules/generic/secrets \
	--config .semgrep-rules/yaml/github-actions \
	--exclude-rule semgrep-rules.php.lang.security.unlink-use \
	"$@" .
