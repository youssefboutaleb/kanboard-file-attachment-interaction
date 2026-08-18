#!/usr/bin/env bash
#
# Build the distributable Kanboard plugin archive.
#
# The archive is an ALLOW-LIST, not a deny-list. The previous exclude-based rsync
# shipped every file nobody had thought to exclude — CLAUDE.md, AGENTS.md,
# walkthrough.md (154 KB of agent notes), implementation_plan.md, settings.json
# and .phpunit.result.cache all reached end users. With an allow-list a new file
# in the repository root cannot silently end up in a release.
#
# ARCHIVE SHAPE: Kanboard extracts straight into plugins/, and
# Core\Plugin\Installer::update() reads statIndex(0) to learn which directory to
# remove before reinstalling. Everything must therefore live under a single
# top-level directory named exactly after the plugin, and that directory must be
# the archive's first entry.
set -euo pipefail

cd "$(dirname "$0")/.."

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

# Exactly what a Kanboard installation needs at runtime, plus the legal and
# user-facing files a public release is expected to carry. Anything not named
# here does not ship.
PAYLOAD=(
    "Plugin.php"
    "src"
    "Template"
    "Assets"
    "LICENSE"
    "NOTICE"
    "README.md"
    "CHANGELOG.md"
    "composer.json"
)

# Files that must never reach an end user, checked for after staging so a rename
# or a new agent-scratch file fails the build instead of shipping.
FORBIDDEN=(
    "CLAUDE.md" "AGENTS.md" "walkthrough.md" "implementation_plan.md"
    "settings.json" "composer.lock" "phpunit.xml" "phpstan.neon"
    "docker-compose.yml" ".phpunit.result.cache"
)

echo "======================================================"
echo " 📦 Packaging Kanboard Plugin: ${PLUGIN_NAME} (v${VERSION})"
echo "======================================================"

mkdir -p "${DIST_DIR}"
rm -f "${ARCHIVE_NAME}"

STAGE_DIR=$(mktemp -d)
trap 'rm -rf "${STAGE_DIR}"' EXIT
TARGET_DIR="${STAGE_DIR}/${PLUGIN_NAME}"
mkdir -p "${TARGET_DIR}"

for item in "${PAYLOAD[@]}"; do
    if [ ! -e "${item}" ]; then
        echo "✖ Required payload entry missing: ${item}" >&2
        exit 1
    fi
    cp -R "${item}" "${TARGET_DIR}/"
done

# Strip anything that rode in inside a copied directory (editor backups, caches,
# stray VCS metadata).
find "${TARGET_DIR}" \( -name '.git*' -o -name '.DS_Store' -o -name '*.log' \
    -o -name '.phpunit.result.cache' -o -name 'node_modules' \) -prune -exec rm -rf {} + 2>/dev/null || true

for forbidden in "${FORBIDDEN[@]}"; do
    if [ -e "${TARGET_DIR}/${forbidden}" ]; then
        echo "✖ Development file leaked into the package: ${forbidden}" >&2
        exit 1
    fi
done

# src/ must not reference tests/, which is not shipped.
if grep -rq "tests/stubs" "${TARGET_DIR}/src" 2>/dev/null; then
    echo "✖ Packaged src/ references tests/stubs, which is excluded from the archive" >&2
    exit 1
fi

( cd "${STAGE_DIR}" && zip -rq "${PLUGIN_NAME}-${VERSION}.zip" "${PLUGIN_NAME}" )
mv "${STAGE_DIR}/${PLUGIN_NAME}-${VERSION}.zip" "${ARCHIVE_NAME}"

# Installer::update() takes statIndex(0) as the directory to replace; if the
# archive did not start with our folder it would delete the wrong thing.
# `| head -1` would SIGPIPE unzip and trip `set -o pipefail`, so read the full
# listing and take the first line with a pure-shell parameter expansion.
ARCHIVE_ENTRIES=$(unzip -Z1 "${ARCHIVE_NAME}")
FIRST_ENTRY=${ARCHIVE_ENTRIES%%$'\n'*}
if [ "${FIRST_ENTRY}" != "${PLUGIN_NAME}/" ] && [ "${FIRST_ENTRY}" != "${PLUGIN_NAME}" ]; then
    echo "✖ First archive entry is '${FIRST_ENTRY}', expected '${PLUGIN_NAME}/'" >&2
    exit 1
fi

echo "✔ Plugin package created: ${ARCHIVE_NAME} ($(du -h "${ARCHIVE_NAME}" | cut -f1))"
echo "  Root entry : ${FIRST_ENTRY}"
echo "  Files      : $(printf '%s\n' "${ARCHIVE_ENTRIES}" | wc -l)"
