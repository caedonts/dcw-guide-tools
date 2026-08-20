#!/usr/bin/env bash
#
# Cut a release from whatever has accumulated under "## Unreleased".
#
#   bin/release.sh 0.4.0
#
# Bumps both version spots, moves the Unreleased notes under the new heading,
# commits, tags, pushes, builds the zip and creates the GitHub release with the
# zip attached (the updater prefers a real build over GitHub's zipball).
#
# Deploys to staging do NOT need this — those go by zip upload, and asset
# cache-busting runs off file mtime, not the version.

set -euo pipefail

VERSION="${1:-}"
ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
MAIN="$ROOT/dcw-guide-tools.php"
LOG="$ROOT/CHANGELOG.md"

die() { printf '\nerror: %s\n' "$1" >&2; exit 1; }

[ -n "$VERSION" ] || die "usage: bin/release.sh <version>   e.g. bin/release.sh 0.4.0"

printf '%s' "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$' \
  || die "version must look like 1.2.3, got '$VERSION'"

cd "$ROOT"

[ -z "$(git status --porcelain)" ] || die "working tree is dirty — commit or stash first"

git rev-parse "v$VERSION" >/dev/null 2>&1 && die "tag v$VERSION already exists"

CURRENT="$(grep -m1 "^const VERSION" "$MAIN" | sed "s/.*'\(.*\)'.*/\1/")"
[ "$CURRENT" != "$VERSION" ] || die "already at $VERSION"

# Refuse to ship an empty release rather than tagging nothing.
awk '/^## Unreleased/{f=1;next} /^## /{f=0} f' "$LOG" | grep -q '[^[:space:]]' \
  || die "nothing under '## Unreleased' in CHANGELOG.md"

printf 'Releasing %s -> %s\n\n' "$CURRENT" "$VERSION"
awk '/^## Unreleased/{f=1;next} /^## /{f=0} f' "$LOG"
printf '\n'
read -r -p "Cut this release? [y/N] " reply
[ "$reply" = "y" ] || [ "$reply" = "Y" ] || die "aborted"

# 1. version, in both places the updater cares about
sed -i '' \
  -e "s/^ \* Version:           .*/ * Version:           $VERSION/" \
  -e "s/^const VERSION = '.*';/const VERSION = '$VERSION';/" \
  "$MAIN"

grep -q "Version:           $VERSION" "$MAIN" || die "version header did not update"
grep -q "const VERSION = '$VERSION'" "$MAIN"  || die "version constant did not update"

# 2. changelog: retitle Unreleased, open a fresh empty one above it
TODAY="$(date +%F)"
python3 - "$LOG" "$VERSION" "$TODAY" <<'PY'
import sys
from pathlib import Path

log, version, today = Path(sys.argv[1]), sys.argv[2], sys.argv[3]
text = log.read_text()
marker = "## Unreleased\n"
assert text.count(marker) == 1, "expected exactly one '## Unreleased'"
text = text.replace(marker, f"## Unreleased\n\n## {version} — {today}\n", 1)
log.write_text(text)
PY

# 3. commit, tag, push
git add -A
git commit -q -m "Release $VERSION"
git tag -a "v$VERSION" -m "v$VERSION"
git push -q origin main
git push -q origin "v$VERSION"

# 4. build the zip from a clean checkout of the tag, so the artefact is exactly
#    what the tag says it is
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
git archive --format=tar --prefix=dcw-guide-tools/ "v$VERSION" | ( cd "$TMP" && tar xf - )
( cd "$TMP" && zip -rq "dcw-guide-tools.zip" dcw-guide-tools )

# 5. release, notes taken from the section just written
NOTES="$(awk -v v="## $VERSION" '$0 ~ "^"v {f=1;next} /^## /{f=0} f' "$LOG")"
gh release create "v$VERSION" "$TMP/dcw-guide-tools.zip" \
  --title "$VERSION" --notes "$NOTES"

printf '\nReleased v%s. sha256 of the attached zip:\n' "$VERSION"
shasum -a 256 "$TMP/dcw-guide-tools.zip"
