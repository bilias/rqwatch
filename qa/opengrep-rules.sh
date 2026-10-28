#!/bin/sh

# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.

# Fetch Semgrep's maintained community rules at a pinned commit into
# .semgrep-rules/ (gitignored); Opengrep runs them. The rules are under the
# Semgrep Rules License v1.0: internal use only, not redistributed here.
# Bump REV deliberately to a commit at least two weeks old, then rerun
# composer opengrep and review new findings.
set -eu

REV=9bbf021d0acffa3095fcffd17b2bc7787eb4796d
DIR=.semgrep-rules

if [ "$(git -C "$DIR" rev-parse HEAD 2>/dev/null || true)" != "$REV" ]; then
	rm -rf "$DIR"
	git init -q "$DIR"
	git -C "$DIR" fetch -q --depth 1 https://github.com/semgrep/semgrep-rules "$REV"
	git -C "$DIR" checkout -q FETCH_HEAD
fi
