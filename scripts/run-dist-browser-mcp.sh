#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd)"

export APP_ENV=prod
export APP_DEBUG=false
export LOG_LEVEL=warning
export APP_VAR_DIR="/tmp/mcp/browser-mcp"
export CONFIG_FILE="${CONFIG_FILE:-${PROJECT_DIR}/browser_config.yaml}"
export JINA_SEARCH_TOKEN="${JINA_SEARCH_TOKEN:-}"
export JINA_READER_TOKEN="${JINA_READER_TOKEN:-}"
export TAVILY_SEARCH_TOKEN="${TAVILY_SEARCH_TOKEN:-}"
export TAVILY_READER_TOKEN="${TAVILY_READER_TOKEN:-}"

exec php "${PROJECT_DIR}/dist/browser-mcp.phar"
