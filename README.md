# Browser MCP

PHP/Symfony implementation of a simple browser MCP server with pluggable search backends (SearxNG, Jina AI, Tavily, DuckDuckGo).

### Table of Contents

- [Configuration](#configuration)
  - [ENV configuration](#env-configuration)
- [MCP config](#mcp-config)
  - [OpenCode (`opencode.json`) examples](#opencode-opencodejson-examples)
  - [Cursor (`.cursor/mcp.json`) examples](#cursor-cursormcpjson-examples)
  - [Run server with all env vars](#run-server-with-all-env-vars)
  - [Run as systemd user service (HTTP)](#run-as-systemd-user-service-http)
- [MCP tools](#mcp-tools)
- [OpenCode web research subagent](#opencode-web-research-subagent)
- [Development](#development)
- [Binary generation](#binary-generation)
- [Debug](#debug)

## Configuration

Primary runtime config lives in YAML ([`browser_config.yaml`](browser_config.yaml) by default).

<details>
<summary>Show <code>browser_config.yaml</code> (full)</summary>

```yaml
# Main runtime configuration file.
# Path is controlled via the `CONFIG_FILE` environment variable (defaults to `browser_config.yaml`).

general:
    # Transport used by the server:
    # - `stdio`: MCP over stdin/stdout (recommended for local tools)
    # - `http`:  MCP over HTTP (network endpoint)
    transport: http
    # Bind host for HTTP transport. Ignored for STDIO.
    # Use `127.0.0.1` for local-only, `0.0.0.0` to listen on all interfaces.
    host: 0.0.0.0
    # Bind port for HTTP transport. Ignored for STDIO.
    port: 9001
    # Cache TTL (seconds) for `browser.search` results/state.
    search_cache_ttl_seconds: 600
    # Cache TTL (seconds) for `browser.open` page fetches/content.
    open_cache_ttl_seconds: 300

searchers:
    # Which search provider implementation to use (must exist under `providers`).
    selected: searxng
    providers:
        searxng:
            # Base URL of a SearxNG instance.
            url: http://server:8088
        jinaai:
            # Jina Search API token. Best set via env and referenced here.
            token: "%env(JINA_SEARCH_TOKEN)%"
        tavily:
            # Tavily Search API token. Best set via env and referenced here.
            token: "%env(TAVILY_SEARCH_TOKEN)%"
        duckduckgo:
            # Request timeout (seconds) per query attempt.
            timeout_seconds: 5
            # Retry count for transient failures.
            max_retries: 1
            # User-Agent header for DuckDuckGo requests.
            user_agent: "Mozilla/5.0 (X11; Linux x86_64; rv:148.0) Gecko/20100101 Firefox/148.0"

readers:
    # Which reader (page fetch + extraction) provider to use (must exist under `providers`).
    selected: http
    providers:
        http:
            # Request timeout (seconds) when fetching pages over HTTP(S).
            timeout_seconds: 30
            # Retry count for transient fetch/extraction failures.
            max_retries: 2
            # User-Agent header used when fetching pages.
            user_agent: "Mozilla/5.0 (X11; Linux x86_64; rv:148.0) Gecko/20100101 Firefox/148.0"
            # HTML/CSS class names considered "noise" and deprioritized/removed during extraction.
            noise_class_tokens:
                - codeblock-lines
                - linenos
                - line-numbers
                - gutter
        jinaai:
            # Jina Reader API token. Best set via env and referenced here.
            token: "%env(JINA_READER_TOKEN)%"
            # Request timeout (seconds) per read/extract attempt.
            timeout_seconds: 15
            # Retry count for transient failures.
            max_retries: 1
        tavily:
            # Tavily Extract/Reader API token. Best set via env and referenced here.
            token: "%env(TAVILY_READER_TOKEN)%"
            # Request timeout (seconds) per read/extract attempt.
            timeout_seconds: 15
            # Retry count for transient failures.
            max_retries: 1
```

</details>

Set `CONFIG_FILE` as follows:

- when running as a PHP script (`php bin/browser-mcp` / `php bin/console browser-mcp`): you can use an absolute or relative path
- when running the packaged PHAR/binary (`dist/browser-mcp.phar`): use an absolute path

Minimal environment variables:

### ENV configuration

```dotenv
# Set log level, default WARNING, with log action level ERROR
LOG_LEVEL=warning
# Where to store data (logs, cache, sessions), must be absolute path for PHAR/Binary
APP_VAR_DIR="/tmp/mcp/browser-mcp"
# Runtime config file, absolute path for PHAR/BINARY
CONFIG_FILE=browser_config.yaml
```

## MCP config:

The server supports **STDIO** (default) and **HTTP** transports.

### OpenCode (`opencode.json`) examples

<details>
<summary>Show OpenCode MCP config example</summary>

```json
{
    "$schema": "https://opencode.ai/config.json",
    "mcp": {
        "websearch": {
            "type": "remote",
            "url": "http://127.0.0.1:9001/mcp",
            "enabled": true
        },
        "browser-cli": {
            "type": "local",
            "command": "php",
            "args": ["bin/browser-mcp"],
            "env": {
                "APP_ENV": "prod",
                "APP_DEBUG": "false",
                "LOG_LEVEL": "info",
                "APP_VAR_DIR": "/tmp/mcp/browser-mcp",
                "CONFIG_FILE": "browser_config.yaml",
                "JINA_SEARCH_TOKEN": "",
                "JINA_READER_TOKEN": "",
                "TAVILY_SEARCH_TOKEN": "",
                "TAVILY_READER_TOKEN": ""
            },
            "enabled": true
        }
    },
    "experimental": {
        "mcp_timeout": 3600000
    }
}
```

</details>

`websearch` shows a remote Streamable HTTP connection (`/mcp`), while `browser-cli` runs this server locally via CLI.
Some clients also work with root (`/`) as the MCP endpoint, but `/mcp` is the recommended explicit URL.

### Cursor (`.cursor/mcp.json`) examples

Cursor uses `mcpServers` (not `mcp`) and server entries differ slightly from OpenCode.

<details>
<summary>Show Cursor MCP config example</summary>

```json
{
    "mcpServers": {
        "websearch": {
            "url": "http://127.0.0.1:9001/mcp"
        },
        "browser-cli": {
            "type": "stdio",
            "command": "php",
            "args": ["bin/browser-mcp"],
            "env": {
                "APP_ENV": "prod",
                "APP_DEBUG": "false",
                "LOG_LEVEL": "info",
                "APP_VAR_DIR": "/tmp/mcp/browser-mcp",
                "CONFIG_FILE": "browser_config.yaml",
                "JINA_SEARCH_TOKEN": "",
                "JINA_READER_TOKEN": "",
                "TAVILY_SEARCH_TOKEN": "",
                "TAVILY_READER_TOKEN": ""
            }
        }
    }
}
```

</details>

### Run server with all env vars

Set all runtime env vars explicitly before starting the server:

<details>
<summary>Show full env var exports</summary>

```bash
export APP_ENV=prod
export APP_DEBUG=false
export LOG_LEVEL=info
export APP_VAR_DIR=/tmp/mcp/browser-mcp
export CONFIG_FILE=/absolute/path/to/browser_config.yaml
export JINA_SEARCH_TOKEN=""
export JINA_READER_TOKEN=""
export TAVILY_SEARCH_TOKEN=""
export TAVILY_READER_TOKEN=""
```

</details>

Run as local CLI/STDIO MCP server:

<details>
<summary>Show run commands (CLI / PHAR / binary)</summary>

```bash
php bin/browser-mcp
```

Run as PHAR (same env vars):

```bash
php dist/browser-mcp.phar
```

Run as native binary (same env vars):

```bash
./dist/browser-mcp
```

</details>

Example script (exports envs and starts `dist/browser-mcp`):

<details>
<summary>Show example wrapper script</summary>

```bash
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
```

</details>

You can also use the ready example script in this repository:

<details>
<summary>Show ready script commands</summary>

```bash
chmod +x scripts/run-dist-browser-mcp.sh
./scripts/run-dist-browser-mcp.sh
```

</details>

To expose a network MCP endpoint, set HTTP transport in `browser_config.yaml`:

<details>
<summary>Show HTTP transport config snippet</summary>

```yaml
general:
    transport: http
    host: 127.0.0.1
    port: 8000
```

</details>

Then start the server with the same env vars and connect your MCP client to `http://127.0.0.1:8000`.

### Run as systemd user service (HTTP)

Create an env file (tokens optional):

<details>
<summary>Show systemd env file creation</summary>

```bash
mkdir -p ~/.config/browser-mcp
cat > ~/.config/browser-mcp/browser-mcp.env <<'EOF'
APP_ENV=prod
APP_DEBUG=false
LOG_LEVEL=info
APP_VAR_DIR=/tmp/mcp/browser-mcp
CONFIG_FILE=/absolute/path/to/browser_config.yaml
JINA_SEARCH_TOKEN=
JINA_READER_TOKEN=
TAVILY_SEARCH_TOKEN=
TAVILY_READER_TOKEN=
EOF
```

</details>

Create `~/.config/systemd/user/browser-mcp.service`:

<details>
<summary>Show systemd unit file</summary>

```ini
[Unit]
Description=Browser MCP HTTP server
After=network.target

[Service]
Type=simple
WorkingDirectory=/absolute/path/to/browser-mcp
EnvironmentFile=%h/.config/browser-mcp/browser-mcp.env
ExecStart=/absolute/path/to/browser-mcp/dist/browser-mcp
Restart=always
RestartSec=2

[Install]
WantedBy=default.target
```

</details>

Enable and start:

<details>
<summary>Show systemd commands</summary>

```bash
systemctl --user daemon-reload
systemctl --user enable --now browser-mcp.service
systemctl --user status browser-mcp.service
```

</details>

View logs:

<details>
<summary>Show journalctl command</summary>

```bash
journalctl --user -u browser-mcp.service -f
```

</details>

## MCP tools

The server exposes three MCP tools:

| Tool | Brief description | Required params | Optional params |
| --- | --- | --- | --- |
| `browser.search` | Search the web via SearxNG and return ranked results. Resets cached pages for a fresh session. | `query` | `topn` (default `5`, range `1-10`) |
| `browser.open` | Open a URL and render readable page text with line numbers (windowed or full-page). | `url` | `startAtLine`, `numberOfLines` (default `50`), `fetchAll` |
| `browser.find` | Run a PCRE regex against an opened page and return matches with nearby context. | `url`, `regex` | _none_ |

All tools return text output with references when links are present. Errors follow a machine-readable format starting with `Result: error` and include `Error Message` and `Hint`.

## OpenCode web research subagent

This repository includes a project-local subagent at `.opencode/agents/web-researcher.md` and a skill at `.opencode/skills/web-research/SKILL.md` as an example.

- Purpose: mandatory path for web research tasks with strict evidence workflow.
- Model: `llama.cpp/flash` (local) with `temperature: 0.6`.
- Tools: only `websearch_*` and `skill`.
- Behavior: run multiple queries, follow links, verify claims, and cite URL + line numbers.

Use it manually with `@web-researcher`; treat it as required for web research tasks. For one-off web queries, direct `websearch_*` calls are fine.

## Development

If you need to modify or want to run/debug a server locally, you should:

- `git clone` repository
- run `composer install`
- `./bin/browser-mcp` contains server, while `./bin/console` holds Symfony console

## Binary generation

To generate a native binary run `./prepare_binary.sh` (Linux).

To build PHAR, you have to install [box-project/box](https://github.com/box-project/box/blob/main/doc/installation.md#composer).

Thanks to amazing projects like [Static PHP](https://static-php.dev/en/) and [FrankenPHP](https://frankenphp.dev/docs/embed/) we are able to run PHP applications as a single binary now.

The easiest way is to just download a prebuilt binary from releases for your platform.

To debug server you should use `npx @modelcontextprotocol/inspector`

- Lint/format: `composer cs-fix`
- Static analysis: `composer phpstan`
- Tests: `composer tests`

## Debug

```bash
php -d xdebug.mode=debug -d xdebug.client_host=127.0.0.1 -d xdebug.client_port=9003 -d xdebug.start_with_request=yes ~/mcp-servers/browser-mcp/bin/browser-mcp
```
