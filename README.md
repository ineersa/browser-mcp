# Browser MCP

PHP/Symfony implementation of a simple browser MCP server with a pluggable backend (SearxNG).   
It provides three invokable services for search, open, and find, plus HTML→plaintext processing tailored for LLM consumption.

As a base [GPT-OSS repository browser-mcp](https://github.com/openai/gpt-oss?tab=readme-ov-file#browser) was used with some tweaks and upgrades.

## Installing and running MCP
To generate binary run `./prepare_binary.sh`, it should work on Linux.

To build binary, you have to install [box-project/box](https://github.com/box-project/box/blob/main/doc/installation.md#composer)
to generate PHAR.

Thanks to amazing projects like [Static PHP](https://static-php.dev/en/) and [FrankenPHP](https://frankenphp.dev/docs/embed/) we are able to run PHP applications as a single binary now.

The easiest way is to just download binary from releases for your platform.

## Env variables
```dotenv
### Set log level, default INFO, with log action level ERROR
LOG_LEVEL=info
# Where to store logs
APP_LOG_DIR="/tmp/mcp/python-mcp/log"
# Backend to use
BROWSER_BACKEND=searxng
# Backend URL
BACKEND_URL=http://server:8088
# Amount of tokens to return in page view
SEARCH_VIEW_TOKENS=1024
# Encoding to calculate tokens (TikToken)
SEARCH_ENCODING_NAME=o200k_base
# Lines to return near found results
FIND_CONTEXT_LINES=4
```

## MCP config:
**STDIO** is only supported transport for now, just add entry to `mcp.json` with a path to binary
```json
{
    "command": "./dist/browser-mcp",
    "args": [],
    "env": {
        "APP_LOG_DIR": "/tmp/.symfony/browser-mcp/log"
    }
}
```
You can also use `browser-mcp.phar` PHAR file.
The server exposes tools: `browser.search`, `browser.open`, `browser.find`.

If you want to use other transports use some wrapper for now, for example, [MCPO](https://github.com/open-webui/mcpo)

```bash
uvx mcpo --port 8000 -- ~/dist/browser-mcp
```

## Development
If you need to modify or want to run/debug a server locally, you should:
- `git clone` repository
- run `composer install`
- `./bin/browser-mcp` contains server, while `./bin/console` holds Symfony console

To debug server you should use `npx @modelcontextprotocol/inspector`

- Lint/format: `composer cs-fix`
- Static analysis: `composer phpstan`
- Tests: `composer tests`

## Notes
- Exa backend is not implemented, only SearxNG available.
- State is shared via `App\\Service\\BrowserState`, state resets each `search` tool call
- Added regex search for `find` tool
- Better cursor handling
