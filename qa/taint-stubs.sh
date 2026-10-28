#!/bin/sh
# Regenerate qa/taint-stubs.phpstub from the installed vendor code, or with
# --check fail if it is out of date. Run from the project root.
# Skipped in a --no-dev Composer run (COMPOSER_DEV_MODE=0).
set -eu

[ "${COMPOSER_DEV_MODE:-1}" = 0 ] && exit 0

stub=qa/taint-stubs.phpstub
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT

php qa/gen-taint-stubs.php > "$tmp"

if [ "${1:-}" = --check ]; then
	diff -u "$stub" "$tmp"
elif ! cmp -s "$stub" "$tmp"; then
	cp "$tmp" "$stub"
	echo "Updated $stub: review and commit it" >&2
fi
