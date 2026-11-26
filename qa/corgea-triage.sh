#!/bin/bash
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Corgea triage: marks groups of Corgea findings as false positives through
# the bulk triage API (PATCH /api/v1/triage/findings), one request per group,
# each with its own justification. GROUPS is a JSON array of
# {"name": ..., "justification": ..., "ids": [issue ids]}, kept with the
# reports in corgea-report/ (ignored by git); the ids are the issue ids of
# rqwatch-corgea-issues.json, and a decision reaches every detection of the
# finding. Without --apply it only previews: Corgea reports how many findings
# each group matches and changes nothing.
# Usage: qa/corgea-triage.sh GROUPS [--apply]
# Needs the corgea CLI's login, as qa/corgea-report.sh: the token and URL come
# from CORGEA_TOKEN and CORGEA_URL, else ~/.corgea/config.toml. Exits
# non-zero if any request fails.
set -euo pipefail

PHP="${PHP:-php}"
GROUPS_FILE="${1:-}"
APPLY=0
case "${2:-}" in
	'') ;;
	--apply) APPLY=1 ;;
	*) GROUPS_FILE= ;;
esac
if [[ -z "${GROUPS_FILE}" ]] || [[ ! -f "${GROUPS_FILE}" ]]; then
	echo "Usage: $0 GROUPS [--apply]" >&2
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
if [[ -z "${TOKEN}" ]]; then
	echo "No Corgea token: run corgea login or set CORGEA_TOKEN" >&2
	exit 1
fi

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

# The auth header as the CLI sends it, from a file so the token stays off the
# command line
umask 077
if [[ "${TOKEN}" =~ ^[^.]+\.[^.]+\.[^.]+$ ]]; then
	printf 'Authorization: Bearer %s\n' "${TOKEN}" > "${WORK}/headers"
else
	printf 'CORGEA-TOKEN: %s\n' "${TOKEN}" > "${WORK}/headers"
fi
printf 'CORGEA-SOURCE: cli\nContent-Type: application/json\n' >> "${WORK}/headers"
umask 022

# shellcheck disable=SC2016 # single-quoted PHP code: its $ are PHP's
count=$("${PHP}" -r 'echo count(json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR));' "${GROUPS_FILE}")
status=0
for ((g = 0; g < count; g++)); do
	# the request body of group $g, and its name on stdout
	# shellcheck disable=SC2016 # single-quoted PHP code: its $ are PHP's
	name=$("${PHP}" -r '
		$group = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR)[(int) $argv[2]];
		file_put_contents($argv[4], json_encode([
			"finding_ids" => $group["ids"],
			"target_status" => "false_positive",
			"justification" => $group["justification"],
			"apply" => $argv[3] === "1",
		], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
		echo $group["name"], " (", count($group["ids"]), " ids)";
	' "${GROUPS_FILE}" "${g}" "${APPLY}" "${WORK}/body.json")
	code=$(curl -sS -X PATCH -H "@${WORK}/headers" --data-binary "@${WORK}/body.json" \
		-o "${WORK}/response.json" -w '%{http_code}' "${URL}/api/v1/triage/findings") || code=000
	if [[ "${code}" != 200 ]] && [[ "${code}" != 202 ]]; then
		echo "=== ${name}: HTTP ${code}" >&2
		cat "${WORK}/response.json" >&2 2>/dev/null || true
		echo >&2
		status=1
		continue
	fi
	# action status, counts and unresolved ids, as Corgea reports them
	# shellcheck disable=SC2016 # single-quoted PHP code: its $ are PHP's
	"${PHP}" -r '
		$r = json_decode((string) file_get_contents($argv[1]), true);
		$a = $r["action"] ?? [];
		echo "=== ", $argv[2], ": ", $a["status"] ?? "?", " ", json_encode($a["counts"] ?? null), "\n";
		if (!empty($r["unresolved_ids"])) {
			echo "    unresolved: ", implode(" ", $r["unresolved_ids"]), "\n";
		}
	' "${WORK}/response.json" "${name}"
done
exit "${status}"
