#!/bin/bash
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Corgea report: fetches the results of an existing Corgea scan (run from the
# web UI against the GitHub repository) into $CORGEA_OUT (default
# corgea-report/), without scanning anything here. The HTML, SARIF and
# Markdown reports (rqwatch-corgea.html, .sarif, .md) are the ones Corgea
# renders, from the endpoint `corgea scan --out-format` uses
# (GET /api/v1/scan/ID/report). rqwatch-corgea-issues.json is every page of
# `corgea ls --issues --scan-id ID --json` merged into one array, with each
# issue's status and triage.
# Usage: qa/corgea-report.sh SCAN_ID (the id in the scan's URL)
# Needs the corgea CLI, logged in (corgea login): the token and URL come from
# CORGEA_TOKEN and CORGEA_URL, else ~/.corgea/config.toml, as the CLI reads
# them. Run from the project root.
set -euo pipefail

PHP="${PHP:-php}"
SCAN_ID="${1:-}"
if ! [[ "$SCAN_ID" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]]; then
	echo "Usage: $0 SCAN_ID (a UUID, from the scan's URL)" >&2
	exit 2
fi

# config_value KEY: KEY's string value in the CLI's config.toml
config_value() {
	sed -n "s/^$1 = \"\(.*\)\"$/\1/p" "${HOME}/.corgea/config.toml" 2>/dev/null || true
}
TOKEN="${CORGEA_TOKEN:-$(config_value token)}"
URL="${CORGEA_URL:-$(config_value url)}"
URL="${URL:-https://www.corgea.app}"
URL="${URL%/}"
if [ -z "$TOKEN" ]; then
	echo "No Corgea token: run corgea login or set CORGEA_TOKEN" >&2
	exit 1
fi

OUT=$(realpath -m "${CORGEA_OUT:-corgea-report}")
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$OUT"

# The auth header as the CLI sends it (a JWT as a bearer token, any other
# token as CORGEA-TOKEN), from a file so the token stays off the command line
umask 077
if [[ "$TOKEN" =~ ^[^.]+\.[^.]+\.[^.]+$ ]]; then
	printf 'Authorization: Bearer %s\n' "$TOKEN" > "${WORK}/headers"
else
	printf 'CORGEA-TOKEN: %s\n' "$TOKEN" > "${WORK}/headers"
fi
echo 'CORGEA-SOURCE: cli' >> "${WORK}/headers"
umask 022

# fetch_report QUERY EXT: the report Corgea renders (no QUERY: HTML)
fetch_report() {
	echo "=== ${2}"
	curl -fsS -H "@${WORK}/headers" -o "${OUT}/rqwatch-corgea.${2}" \
		"${URL}/api/v1/scan/${SCAN_ID}/report${1}"
}
fetch_report '' html
fetch_report '?format=sarif' sarif
fetch_report '?format=markdown' md

echo "=== issues"
page=1
pages=1
files=()
while [ "$page" -le "$pages" ]; do
	corgea ls --issues --scan-id "$SCAN_ID" --page "$page" --page-size 100 --json \
		> "${WORK}/issues-${page}.json"
	files+=("${WORK}/issues-${page}.json")
	pages=$("$PHP" -r 'echo (int) (json_decode(file_get_contents($argv[1]), true)["total_pages"] ?? 1);' \
		"${WORK}/issues-${page}.json")
	page=$((page + 1))
done
"$PHP" -r '
	$all = [];
	foreach (array_slice($argv, 1) as $f) {
		$all = array_merge($all, json_decode(file_get_contents($f), true)["results"] ?? []);
	}
	echo json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
' "${files[@]}" > "${OUT}/rqwatch-corgea-issues.json"

echo "Corgea reports for scan ${SCAN_ID}: ${OUT}"
