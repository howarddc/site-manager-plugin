#!/usr/bin/env bash
# Build a release zip of the plugin.
#
# Usage: ./build.sh
#
# Writes builds/site-manager-<version>.zip containing a top-level
# site-manager/ folder (the installed plugin slug, independent of this
# repo's folder name). Dev files never ship.

set -euo pipefail

SLUG="site-manager"
REPO_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

VERSION="$( grep -m1 "Version:" "$REPO_DIR/$SLUG.php" \
            | sed -E 's/.*Version:[[:space:]]*([0-9.]+(-[0-9A-Za-z.]+)?).*/\1/' )"
if [ -z "$VERSION" ]; then
  echo "Could not parse Version: from $SLUG.php" >&2
  exit 1
fi

STAGE="$( mktemp -d )"
trap 'rm -rf "$STAGE"' EXIT

rsync -a "$REPO_DIR/" "$STAGE/$SLUG/" \
  --exclude ".git" \
  --exclude ".gitignore" \
  --exclude ".gitattributes" \
  --exclude ".claude" \
  --exclude "builds" \
  --exclude ".DS_Store" \
  --exclude "AGENTS.md" \
  --exclude "CLAUDE.md" \
  --exclude "README.md" \
  --exclude "build.sh"

mkdir -p "$REPO_DIR/builds"
ZIP_PATH="$REPO_DIR/builds/$SLUG-$VERSION.zip"
rm -f "$ZIP_PATH"
( cd "$STAGE" && zip -rq "$ZIP_PATH" "$SLUG" )

echo "Built $(basename "$ZIP_PATH") ($(du -h "$ZIP_PATH" | cut -f1))"
