#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
WORKSPACE_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${WORKSPACE_DIR}/.env"
cd -- "${WORKSPACE_DIR}"

if [[ ! -e "${ENV_FILE}" ]]; then
  cp -- .env.example "${ENV_FILE}"
fi

chmod 600 -- "${ENV_FILE}"

if ! grep -Eq '^[[:space:]]*TOKEN[0-9]*=[^[:space:]]+' "${ENV_FILE}"; then
  printf '%s\n' 'Warning: no GitHub token is configured in .env; set TOKEN (or TOKEN2, etc.) before running the app.' >&2
fi
