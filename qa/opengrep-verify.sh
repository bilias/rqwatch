#!/bin/sh
# usage: opengrep-verify.sh [version]; prints the binary's sha256 for OPENGREP_SHA256
set -eu
OPENGREP_VERSION=${1:-v1.33.1}
F=opengrep_manylinux_x86

D=$(mktemp -d)
trap 'rm -rf "$D"' EXIT
trap 'exit 1' INT TERM
cd "$D"

ok=
for i in 1 2 3 4 5; do
	gh release download "$OPENGREP_VERSION" -R opengrep/opengrep --clobber \
		-p "$F" -p "$F.sig" -p "$F.cert" && { ok=1; break; }
	sleep 20
done
[ -n "$ok" ] || { echo "download failed" >&2; exit 1; }

cosign verify-blob --cert "$F.cert" --signature "$F.sig" \
	--certificate-identity-regexp '^https://github\.com/opengrep/opengrep/\.github/workflows/' \
	--certificate-oidc-issuer https://token.actions.githubusercontent.com "$F"
echo "Opengrep version: ${OPENGREP_VERSION}"
sha256sum "$F" | cut -d' ' -f1
