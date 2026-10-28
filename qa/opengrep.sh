#!/bin/sh
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
