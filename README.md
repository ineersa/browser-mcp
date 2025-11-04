# Browser MCP

PHP/Symfony implementation of a simple browser MCP server with a pluggable backend (SearxNG).   
It provides three invokable services for `search`, `open`, and `find`, plus HTML→plaintext processing tailored for LLM consumption.

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
# Enable Puppeteer-based fetching for JS-heavy pages
USE_PUPPETEER=false
# Path to the node executable used by PuppeteerWorker
PUPPETEER_NODE_BINARY=node
# Navigation timeout for Puppeteer (seconds)
PUPPETEER_TIMEOUT=45
```

## Puppeteer rendering (optional)
To render JavaScript-heavy pages you can delegate fetching to Puppeteer instead of the Symfony HTTP client.

1. Install Node.js 18+ and run   
    ```bash
       npm install puppeteer puppeteer-extra puppeteer-extra-plugin-stealth puppeteer-extra-plugin-user-preferences puppeteer-extra-plugin-user-data-dir
    ```
    from the project root (or provide compatible installations globally using `npm install -g`).  
   The helper automatically enables the stealth plugin when present and falls back to vanilla Puppeteer otherwise. 
2. Ensure the `node` binary is on your `PATH`, or override `PUPPETEER_NODE_BINARY` with the full path to your Node.js executable.
3. Enable Puppeteer by setting `USE_PUPPETEER=true` (e.g., in `.env.local`). Optional: adjust `PUPPETEER_TIMEOUT` to control how long the worker waits for pages to finish loading.

When enabled, `PuppeteerWorker` invokes `bin/puppeteer-fetch.js`, which launches a headless browser, waits for the network to settle, performs a short auto-scroll to trigger lazy content, and returns the rendered HTML to the backend.

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

### Debug
```bash
php -d xdebug.mode=debug -d xdebug.client_host=127.0.0.1 -d xdebug.client_port=9003 -d xdebug.start_with_request=yes ~/mcp-servers/browser-mcp/bin/browser-mcp
```

## Tools definitions and logic
### Response contract
- Every tool reply is a single text block (`TextContent`) that starts with the page title (the domain is appended in parentheses) and, when available, an explicit `URL: ...` line.
- A bold status line such as `**viewing lines [12 - 61] of 420**` shows what portion of the page is rendered; bodies are token-limited and lines are prefixed with `L<index>` when scrolling output (`browser.open`/`browser.find`).
- Citations inside the body follow the `【id†excerpt†domain】` convention and always map to a trailing `References:` section where `[id]` resolves to a canonical URL.
- If a tool fails validation or the backend errors, the response stays machine-readable: it begins with `Result: error`, followed by `Error Message:` and a `Hint:` string to help recover.

### `browser.search`
- **Purpose**: Run SearxNG-backed web search and seed later `open`/`find` calls.
- **Parameters**: `query` (string, required); `topn` (int, optional, default `5`, bounds `1-10`).
- **Output shape**: Numbered list where each entry shows the title (with domain), a canonical `URL:` line, and a trimmed `Summary:`. The `References` table reuses the same numbers, so `[1]` matches result `1.` above.
- **State**: Clears any cached pages in the browser state before returning fresh results.

### `browser.open`
- **Purpose**: Fetch and render a slice of a page for reading or scrolling.
- **Parameters**: `url` (string, required absolute URL), `start_at_line` (int, required, 0-based). Optional: `number_of_lines` (int, default `50`, minimum `1`), `fetch_all` (bool, default `false`; when `true`, ignores `number_of_lines` and returns the entire page body).
- **Output shape**: Page text rendered with prefixed line numbers (`L42:`) and capped by the token budget; the scrollbar line reports the viewed window. Inline citations map to the page’s outbound links, and the `References` section lists every discovered URL. When `fetch_all` is used, the same header/footer rules apply and references still do not count toward line totals.
- **State**: Pages are cached by canonical URL so subsequent `open` or `find` calls reuse the fetched copy unless an error occurs.

### `browser.find`
- **Purpose**: Locate regex matches within a previously opened page (or fetch it once).
- **Parameters**: `url` (string, required), `regex` (string, required, PCRE syntax with delimiters such as `/pattern/iu`).
- **Output shape**: Each match is rendered as `# 【id†match at L<line>】` followed by a few context lines; when no match exists the tool explains next steps. The `References` list keeps a single entry pointing back to the source page.
- **State**: Uses the cached page if available and refuses to run on existing `find` result URLs to avoid recursion. Results are stored so you can scroll them with `browser.open`.
