#!/bin/bash
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Infection (mutation testing) on the unit suite. It changes the app/ code
# the unit tests cover, one small mutation at a time, and reports each change
# the suite still passes ("escaped"): logic no unit test pins down. Many
# escaped mutants are harmless (equivalent code), so read the report rather
# than chase a score. Local only, not CI: a full run takes minutes and its
# output needs judgement. It runs in a copy of the working tree (copy_tree),
# because a mutant can turn a path the tests write under into a relative one
# and litter the checkout. Reports go to $INFECTION_OUT (default
# infection-report/): infection.html, infection.log, infection.json. Extra
# arguments go to infection: --filter=app/Utils/Helper.php for one file
# (deprecated, but 0.35 matches a positional path unresolved, so ../app/... finds nothing),
# --git-diff-lines --git-diff-base=github/master for the lines changed since.
# Needs a coverage driver: pcov (dnf install php-pecl-pcov) or Xdebug. Run
# from the project root.
set -euo pipefail
. qa/lib.sh

# a variable, not a pipe: grep -q would close it early and pipefail fail it
modules=$("$PHP" -m)
opts=()
if grep -qix pcov <<< "$modules"; then
	opts+=(--initial-tests-php-options='-d pcov.enabled=1')
elif grep -qix xdebug <<< "$modules"; then
	export XDEBUG_MODE=coverage
else
	echo "no coverage driver: install pcov (dnf install php-pecl-pcov) or Xdebug" >&2
	exit 1
fi

OUT=$(realpath -m "${INFECTION_OUT:-infection-report}")
mkdir -p "$OUT"
ROOT=$(pwd)
make_workdir
copy_tree
# the repository, for --git-diff-* (Infection only reads it)
ln -s "${ROOT}/.git" .git

status=0
"$PHP" vendor/bin/infection -c qa/infection.json5 --threads=max --no-progress \
	--logger-html="${OUT}/infection.html" --logger-text="${OUT}/infection.log" \
	--logger-json="${OUT}/infection.json" "${opts[@]}" "$@" || status=$?
echo "Infection reports: ${OUT}"
exit "$status"
