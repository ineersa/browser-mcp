# Browser MCP

Browser MCP is a PHP/Symfony MCP server that gives agents a focused web workflow: search for sources, open readable content with line numbers, and verify claims in-page.

It is built for research agents and coding assistants where predictable output and provider flexibility matter more than a full browser runtime.

Architecture overview: [docs/architecture.md](docs/architecture.md)

## Installation

1. Download binary (latest release)

Download the binary for your platform from the latest release:

- `https://github.com/ineersa/browser-mcp/releases/latest`

2. Download `browser_config.yaml`

Use the config from from repository root as an example:

- `https://raw.githubusercontent.com/ineersa/browser-mcp/main/browser_config.yaml`

3. Add to `mcp.json`

Minimal stdio example:

```json
{
    "mcpServers": {
        "browser": {
            "type": "stdio",
            "command": "/absolute/path/to/browser-mcp",
            "env": {
                "APP_ENV": "prod",
                "APP_DEBUG": "false",
                "LOG_LEVEL": "warning",
                "APP_VAR_DIR": "/tmp/mcp/browser-mcp",
                "CONFIG_FILE": "/absolute/path/to/browser_config.yaml",
                "JINA_SEARCH_TOKEN": "",
                "JINA_READER_TOKEN": "",
                "TAVILY_SEARCH_TOKEN": "",
                "TAVILY_READER_TOKEN": ""
            }
        }
    }
}
```

Minimal required envs:

```bash
export LOG_LEVEL=warning
export APP_VAR_DIR=/tmp/mcp/browser-mcp
export CONFIG_FILE=/absolute/path/to/browser_config.yaml
```

### Running over HTTP and systemd setup (includes `.sh` example): [docs/http-server-systemd.md](docs/http-server-systemd.md)

### Configuration and client setups: [docs/configuration.md](docs/configuration.md)

## Providers

Provider selection is configured in `browser_config.yaml`:

- `searchers.selected` for `browser.search`
- `readers.selected` for `browser.open` and `browser.find`

Search providers:

| Provider        | Select value(s)                        | API key               |
| --------------- | -------------------------------------- | --------------------- |
| SearxNG         | `searxng` (`searx`)                    | Not required          |
| DuckDuckGo Lite | `duckduckgo` (`duckduckgolite`, `ddg`) | Not required          |
| Jina AI Search  | `jinaai` (`jina`)                      | `JINA_SEARCH_TOKEN`   |
| Tavily Search   | `tavily`                               | `TAVILY_SEARCH_TOKEN` |

Reader providers:

| Provider       | Select value(s)   | API key               |
| -------------- | ----------------- | --------------------- |
| HTTP reader    | `http`            | Not required          |
| Jina AI Reader | `jinaai` (`jina`) | `JINA_READER_TOKEN`   |
| Tavily Reader  | `tavily`          | `TAVILY_READER_TOKEN` |

### How to configure providers in detail: [docs/providers.md](docs/providers.md).

## Tools

Tool descriptions below reflect actual code in `src/Tools` and `src/Service`.

| Tool             | What it does                                                                                                                          | Required args  | Optional args                                                  |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------- | -------------- | -------------------------------------------------------------- |
| `browser.search` | Runs web search for discovery and returns ranked results with canonical URLs and short summaries.                                     | `query`        | `topn` (default `5`, range `1-10`)                             |
| `browser.open`   | Opens a page and returns line-numbered text. Supports explicit windowing and automatic anchoring near relevant search snippets.       | `url`          | `startAtLine`, `numberOfLines` (default `50`), `fetchAll`      |
| `browser.find`   | Finds text in a page using `contains` (case-insensitive, whitespace-flexible) or `exact` (strict case/whitespace-sensitive) matching. | `url`, `query` | `match` (`contains` or `exact`), `context_lines` (default `5`) |

Notes:

- `browser.open` line numbers are zero-based (`L0`, `L1`, ...).
- `browser.find` returns TOON output with `url`, `query`, `match`, and `matches` (`id`, `line`, `chunk`).
- Tool errors return `Result: error` with `Error Message` and `Hint`.

## Subagent/SKILL example

Web research subagent example: [docs/web-research-subagent.md](docs/web-research-subagent.md)

## Development

Development workflow: [docs/development.md](docs/development.md)

## Binary generation

PHAR/native binary generation: [docs/binary-generation.md](docs/binary-generation.md)
