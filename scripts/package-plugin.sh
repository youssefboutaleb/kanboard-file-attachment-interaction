#!/usr/bin/env bash
set -euo pipefail

PLUGIN_NAME="FileInteractionCore"
VERSION=$(grep '"version"' composer.json 2>/dev/null | cut -d'"' -f4 || echo "0.1.0")
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
