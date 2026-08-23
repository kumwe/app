#!/bin/bash
# Claude Code pointer to the canonical, vendor-neutral bootstrap.
# All setup logic lives in tools/agent-setup.sh — never add any here.
set -uo pipefail

# Cloud sessions only; a local machine is the developer's own to provision.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
    exit 0
fi

bash "${CLAUDE_PROJECT_DIR:-.}/tools/agent-setup.sh"

# Persist the generated test-lane environment for every shell in this session.
if [ -n "${CLAUDE_ENV_FILE:-}" ] && [ -f "${CLAUDE_PROJECT_DIR:-.}/.agent-env" ]; then
    cat "${CLAUDE_PROJECT_DIR:-.}/.agent-env" >> "$CLAUDE_ENV_FILE"
fi
