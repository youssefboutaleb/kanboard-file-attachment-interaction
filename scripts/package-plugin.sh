#!/usr/bin/env bash
set -euo pipefail

PLUGIN_NAME="FileInteractionCore"

# Plugin.php::getPluginVersion() is the single source of truth for the release
# version. composer.json deliberately carries NO "version" field: it makes
# `composer validate --strict` fail in CI, and Composer derives versions from
# git tags anyway.
VERSION=$(sed -n "/function getPluginVersion/,/}/s/.*return '\([^']*\)'.*/\1/p" Plugin.php | head -1)

if [ -z "${VERSION}" ]; then
    echo "✖ Unable to read the version from Plugin.php::getPluginVersion()" >&2
    exit 1
fi

DIST_DIR="dist"
ARCHIVE_NAME="${DIST_DIR}/${PLUGIN_NAME}-${VERSION}.zip"

echo "======================================================"
echo " 📦 Packaging Kanboard Plugin: ${PLUGIN_NAME} (v${VERSION})"
echo "======================================================"

mkdir -p "${DIST_DIR}"
rm -f "${ARCHIVE_NAME}"

# Create temporary packaging staging folder
STAGE_DIR=$(mktemp -d)
TARGET_DIR="${STAGE_DIR}/${PLUGIN_NAME}"

mkdir -p "${TARGET_DIR}"

# Copy plugin files excluding dev & git directories
rsync -av --exclude='.git*' \
          --exclude='.agents' \
          --exclude='.githooks' \
          --exclude='docker-compose.yml' \
          --exclude='vendor' \
          --exclude='tests' \
          --exclude='dist' \
          --exclude='scripts' \
          --exclude='phpunit.xml' \
          --exclude='phpstan.neon' \
          ./ "${TARGET_DIR}/"

# Build zip archive
(cd "${STAGE_DIR}" && zip -r "${PLUGIN_NAME}-${VERSION}.zip" "${PLUGIN_NAME}")

mv "${STAGE_DIR}/${PLUGIN_NAME}-${VERSION}.zip" "${ARCHIVE_NAME}"
rm -rf "${STAGE_DIR}"

echo "✔ Plugin package created successfully: ${ARCHIVE_NAME}"
