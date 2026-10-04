#!/usr/bin/env bash
#
# Build a distributable Site Manager plugin ZIP.
#
# Produces builds/site-manager-<version>.zip with a single top-level
# "site-manager/" folder, so it installs cleanly via the WordPress "Upload
# Plugin" screen and the plugin slug stays "site-manager" (the repository
# folder name doesn't matter).
#
# The ZIP contains committed plugin files plus the bundled update checker
# (vendor/plugin-update-checker, pinned below and fetched at build time,
# since vendor/ is not committed). Development files are excluded via the
# `export-ignore` rules in .gitattributes.
#
# Usage:
#   ./build.sh             # build from the latest commit (HEAD)
#   ./build.sh <git-ref>   # build from a tag/branch/commit, e.g. v1.0.0
#
set -euo pipefail

# Pinned plugin-update-checker release bundled into the ZIP.
PUC_REPO="https://github.com/YahnisElsts/plugin-update-checker.git"
PUC_VERSION="v5.7"

SLUG="site-manager"

# Always operate from the repository root.
cd "$(git rev-parse --show-toplevel)"

REF="${1:-HEAD}"

# Read the version from each place it is declared, at the ref being built.
MAIN_FILE="$(git show "${REF}:${SLUG}.php")"
VERSION="$(sed -nE "s/.*define\( *'SITE_MANAGER_VERSION', *'([^']+)'.*/\1/p" <<<"${MAIN_FILE}")"
HEADER_VERSION="$(sed -nE 's/^[ *]*Version: *([^ ]+).*/\1/p' <<<"${MAIN_FILE}")"
README_VERSION="$(git show "${REF}:README.md" | sed -nE 's/^- \*\*Version:\*\* ([^ ]+).*/\1/p' | head -1)"
CHANGELOG_VERSION="$(git show "${REF}:CHANGELOG.md" | sed -nE 's/^## \[([^]]+)\].*/\1/p' | head -1)"

if [ -z "${VERSION}" ]; then
  echo "Error: could not determine SITE_MANAGER_VERSION from ${SLUG}.php" >&2
  exit 1
fi
if [ "${HEADER_VERSION}" != "${VERSION}" ] || [ "${README_VERSION}" != "${VERSION}" ] || [ "${CHANGELOG_VERSION}" != "${VERSION}" ]; then
  echo "Error: version mismatch — SITE_MANAGER_VERSION=${VERSION}, plugin header=${HEADER_VERSION:-?}, README=${README_VERSION:-?}, CHANGELOG=${CHANGELOG_VERSION:-?}" >&2
  exit 1
fi

OUT_DIR="builds"
OUT="${OUT_DIR}/${SLUG}-${VERSION}.zip"

STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT

# git archive ships only committed, non-export-ignored files.
git archive --format=tar --prefix="${SLUG}/" "${REF}" | tar -x -C "${STAGE}"

# Bundle the update checker so installed copies see new GitHub releases.
PUC_DIR="${STAGE}/${SLUG}/vendor/plugin-update-checker"
git clone --quiet --depth 1 --branch "${PUC_VERSION}" "${PUC_REPO}" "${PUC_DIR}" 2>/dev/null \
  || { echo "Error: could not fetch plugin-update-checker ${PUC_VERSION}" >&2; exit 1; }
rm -rf "${PUC_DIR}/.git" "${PUC_DIR}/.github"
if [ ! -f "${PUC_DIR}/plugin-update-checker.php" ]; then
  echo "Error: plugin-update-checker.php missing from ${PUC_VERSION}" >&2
  exit 1
fi

mkdir -p "${OUT_DIR}"
rm -f "${OUT}"
( cd "${STAGE}" && zip -qr -X - "${SLUG}" ) > "${OUT}"

COUNT="$(unzip -l "${OUT}" | tail -1 | awk '{print $2}')"
echo "Built ${OUT}"
echo "  version: ${VERSION}  |  ref: ${REF}  |  update checker: ${PUC_VERSION}  |  files: ${COUNT}"
echo "Upload this ZIP via WordPress > Plugins > Add New > Upload Plugin, or attach it to the GitHub release."
