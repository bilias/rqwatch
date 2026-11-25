#!/bin/sh
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Betterleaks over what is public: the history of the GitHub remote's
# branches (the first remote on github.com, or BETTERLEAKS_REMOTE), as last
# fetched, from the project root; not the other remotes or local branches.
# Runs the default rules plus the two in .betterleaks.toml for the shapes of
# rqwatch's own secrets (.env and config.local.php). Findings that are not
# secrets (published example values, test passwords, placeholders) are listed
# by fingerprint in .betterleaksignore; anything else is reported, with the
# secret redacted, and exits 1. Not a CI job: a secret on GitHub is already
# public, so a check before pushing has to run here. Extra arguments go to
# betterleaks git, and a later --log-opts replaces the scope
# (--log-opts=github/master..HEAD: the commits about to be pushed). Needs
# betterleaks on PATH, or BETTERLEAKS naming the binary: download a release
# from https://github.com/betterleaks/betterleaks/releases and check it
# against the release's checksums.txt.
set -eu
BETTERLEAKS="${BETTERLEAKS:-betterleaks}"
if ! command -v "$BETTERLEAKS" >/dev/null 2>&1; then
	echo "betterleaks not found: install it (https://github.com/betterleaks/betterleaks/releases) or set BETTERLEAKS" >&2
	exit 127
fi
remote="${BETTERLEAKS_REMOTE:-$(git remote -v | awk '$2 ~ /github\.com[:\/]/ && $3 == "(fetch)" { print $1; exit }')}"
if [ -z "$remote" ]; then
	echo "No remote on github.com: set BETTERLEAKS_REMOTE" >&2
	exit 1
fi
echo "=== Betterleaks $("$BETTERLEAKS" version), history of ${remote}"
status=0
"$BETTERLEAKS" git --no-banner --redact --verbose --config .betterleaks.toml \
	--gitleaks-ignore-path .betterleaksignore --platform=none --log-opts="--remotes=$remote" "$@" . \
	|| status=$?
if [ "$status" -eq 0 ]; then
	echo "=== Betterleaks: clean"
else
	echo "=== Betterleaks: failed (exit ${status})" >&2
fi
exit "$status"
