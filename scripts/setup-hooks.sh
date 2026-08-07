#!/usr/bin/env bash
set -euo pipefail

echo "Installing git hooks from .githooks directory..."

if [ -d ".git" ]; then
    git config core.hooksPath .githooks
    chmod +x .githooks/* 2>/dev/null || true
    echo "✔ Git hooks configured to use .githooks/"
else
    echo "⚠️ Not a git repository or .git directory missing."
fi
