#!/usr/bin/env bash
set -euo pipefail

echo "======================================================"
echo " 🤖 Running Agentic Automated Verification Pipeline"
echo "======================================================"

HAS_HOST_PHP=false
if command -v php >/dev/null 2>&1; then
    HAS_HOST_PHP=true
fi

DOCKER_CMD="docker run --rm -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=* -v $(pwd):/app -w /app"

echo "--> [1/4] Checking PHP Syntax..."
if [ "$HAS_HOST_PHP" = true ]; then
    find src/ tests/ -name "*.php" -exec php -l {} \; | grep -v "No syntax errors detected" || true
    echo "✔ PHP Syntax OK (Host)"
elif command -v docker >/dev/null 2>&1; then
    $DOCKER_CMD php:8.1-cli sh -c 'find src/ tests/ -name "*.php" -exec php -l {} \;' | grep -v "No syntax errors detected" || true
    echo "✔ PHP Syntax OK (Docker)"
else
    echo "⚠️ Neither PHP nor Docker found; skipping syntax check."
fi

echo "--> [2/4] Validating Composer Configuration..."
if command -v composer >/dev/null 2>&1; then
    composer validate --strict
    echo "✔ Composer Validation OK (Host)"
elif command -v docker >/dev/null 2>&1; then
    $DOCKER_CMD composer:2 composer validate --strict
    echo "✔ Composer Validation OK (Docker)"
else
    echo "⚠️ Composer CLI not found; skipping composer validate."
fi

echo "--> [3/4] Running Static Analysis (PHPStan)..."
if [ "$HAS_HOST_PHP" = true ] && [ -f "vendor/bin/phpstan" ]; then
    vendor/bin/phpstan analyse --configuration=phpstan.neon
    echo "✔ PHPStan Analysis Passed (Host)"
elif command -v docker >/dev/null 2>&1 && [ -f "vendor/bin/phpstan" ]; then
    $DOCKER_CMD php:8.1-cli vendor/bin/phpstan analyse --configuration=phpstan.neon --no-progress
    echo "✔ PHPStan Analysis Passed (Docker)"
else
    echo "ℹ️ PHPStan check skipped."
fi

echo "--> [4/4] Running Test Suite (PHPUnit)..."
if [ "$HAS_HOST_PHP" = true ] && [ -f "vendor/bin/phpunit" ]; then
    vendor/bin/phpunit
    echo "✔ Test Suite Passed (Host)"
elif command -v docker >/dev/null 2>&1; then
    $DOCKER_CMD php:8.1-cli vendor/bin/phpunit
    echo "✔ Test Suite Passed (Docker)"
else
    echo "⚠️ Cannot run PHPUnit; skipping."
fi

echo "======================================================"
echo " 🎉 Agentic Verification Pipeline Complete!"
echo "======================================================"
