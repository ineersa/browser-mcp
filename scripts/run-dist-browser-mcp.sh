#!/usr/bin/env bash
set -euo pipefail

export APP_ENV=prod
export APP_DEBUG=false
export LOG_LEVEL=info
export APP_VAR_DIR=/tmp/mcp/browser-mcp
export CONFIG_FILE="${CONFIG_FILE:-$(pwd)/browser_config.yaml}"
export JINA_SEARCH_TOKEN="${JINA_SEARCH_TOKEN:-}"
export JINA_READER_TOKEN="${JINA_READER_TOKEN:-}"
export TAVILY_SEARCH_TOKEN="${TAVILY_SEARCH_TOKEN:-}"
export TAVILY_READER_TOKEN="${TAVILY_READER_TOKEN:-}"

exec ./dist/browser-mcp
