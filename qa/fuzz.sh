#!/bin/bash
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Coverage-guided fuzzing of the parsers of sender-controlled data with
# nikic/php-fuzzer. Local only, not CI. One target per file in qa/fuzz/
# (TARGET is its name without .php); it gets random inputs grown from its
# seeds (qa/fuzz/seeds/TARGET/) and reports an Error, a PHP notice or
# warning, or a run over 3 seconds, as a crash.
#
#   qa/fuzz.sh TARGET [MINUTES]           fuzz, default 10 minutes
#   qa/fuzz.sh TARGET run-single FILE     run one input, print its trace
#   qa/fuzz.sh TARGET minimize-crash FILE shrink a crashing input
#
# Everything is written under fuzz-work/TARGET/ (ignored): the grown corpus
# (corpus/, kept between runs), crash-HASH.txt and minimized-HASH.txt (one
# per step: the newest is the smallest). One bug can leave many crash files.
# A crash becomes a unit test case with its fix; the corpus is not committed.
#
# The tool is installed on first use into .php-fuzzer/ (ignored) from git,
# at FUZZER_COMMIT: v0.0.11 plus the PHP 8.5 fix (42a80bf; v0.0.11's own
# mutator raises a warning on 8.5 that stops the run). Not the release phar,
# which is v0.0.11; not composer.json, whose nikic/php-parser it would
# share (its own dependencies resolve when it is installed). To update: change FUZZER_COMMIT and rm -rf .php-fuzzer. Runs with
# $PHP (default php); needs pcntl for the timeout. Run from the project root.
set -euo pipefail
. qa/lib.sh

FUZZER_DIR=.php-fuzzer
FUZZER_REPO=https://github.com/nikic/PHP-Fuzzer.git
FUZZER_COMMIT=23e440d79d353d5f42c9e41a50f7b4f930b85c00

usage() {
	sed -n 's/^#   //p' "$0" >&2
	exit 2
}

[[ $# -ge 1 ]] || usage
name=$(basename "$1" .php)
target=$(realpath "qa/fuzz/${name}.php")
[[ -f "${target}" ]] || { echo "no target qa/fuzz/${name}.php" >&2; exit 2; }
shift

if [[ ! -f "${FUZZER_DIR}/bin/php-fuzzer" ]]; then
	git clone --quiet "${FUZZER_REPO}" "${FUZZER_DIR}"
	git -C "${FUZZER_DIR}" checkout --quiet "${FUZZER_COMMIT}"
	composer install --working-dir="${FUZZER_DIR}" --no-dev --no-interaction --no-progress
fi
head=$(git -C "${FUZZER_DIR}" rev-parse HEAD)
if [[ "${head}" != "${FUZZER_COMMIT}" ]]; then
	echo "${FUZZER_DIR} is not at ${FUZZER_COMMIT}: rm -rf ${FUZZER_DIR} to reinstall" >&2
	exit 1
fi
fuzzer=$(realpath "${FUZZER_DIR}/bin/php-fuzzer")

work="fuzz-work/${name}"
mkdir -p "${work}/corpus"
# coverage drivers off: the fuzzer instruments the code itself
run() {
	(cd "${work}" && XDEBUG_MODE=off "${PHP}" -d pcov.enabled=0 "${fuzzer}" "$@")
}

case ${1:-} in
	run-single|minimize-crash)
		[[ $# -eq 2 ]] || usage
		input=$(realpath "$2")
		run "$1" "${target}" "${input}"
		exit
		;;
	''|*[!0-9]*)
		[[ $# -eq 0 ]] || usage
		minutes=10
		;;
	*)
		[[ $# -eq 1 ]] || usage
		minutes=$1
		;;
esac

# seeds join the corpus kept from earlier runs
cp "qa/fuzz/seeds/${name}/"* "${work}/corpus/"
echo "=== fuzzing ${name} for ${minutes} minute(s); progress in ${work}/fuzz.log"
status=0
(cd "${work}" && XDEBUG_MODE=off timeout "${minutes}m" "${PHP}" -d pcov.enabled=0 "${fuzzer}" fuzz "${target}" corpus) \
	> "${work}/fuzz.log" 2>&1 || status=$?
# timeout's 124 is the run's normal end
[[ ${status} -eq 124 ]] && status=0
tail -n 3 "${work}/fuzz.log"
shopt -s nullglob
crashes=("${work}"/crash-*.txt)
if [[ ${#crashes[@]} -gt 0 ]]; then
	echo "=== ${#crashes[@]} crash file(s) in ${work}/ (full output in fuzz.log)" >&2
	exit 1
fi
if grep -q 'CORPUS CRASH' "${work}/fuzz.log"; then
	echo "=== a corpus input crashes (fuzz.log): fixed code, or remove that file" >&2
	exit 1
fi
echo "=== ${name}: no crash in ${minutes} minute(s)"
exit "${status}"
