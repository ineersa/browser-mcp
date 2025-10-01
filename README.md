# Browser MCP

PHP/Symfony implementation of a simple browser MCP server with a pluggable backend (SearxNG).   
It provides three invokable services for search, open, and find, plus HTML→plaintext processing tailored for LLM consumption.

As a base [GPT-OSS repository browser-mcp](https://github.com/openai/gpt-oss?tab=readme-ov-file#browser) was used with some tweaks and upgrades.

## Setup
- Requirements: PHP 8.4+, Composer
- Install: `composer install`

## Configuration
- `BROWSER_BACKEND` controls the backend driver. Default: `searx`.
  - Example: `BROWSER_BACKEND=searx`
- `SEARXNG_URL` points to your SearxNG instance. Default: `http://server:8088`.
  - Example: `SEARXNG_URL=https://searx.example.com`

These are wired via `config/services.yaml` using a factory that provides `App\Service\Backend\BackendInterface`.

You can set env vars in a local `.env` or export them in your shell before running.

## Usage

### Run the MCP server
- Default command: `php bin/browser-mcp`
- Or via console: `php bin/console browser-mcp`

The server exposes tools: `browser.search`, `browser.open`, `browser.find`.

## Development
- Lint/format: `composer cs-fix`
- Static analysis: `composer phpstan`
- Tests: `composer tests`

## Notes
- Exa backend is not implemented, only SearxNG available.
- State is shared via `App\\Service\\BrowserState`, state resets each `search` tool call
- Added regex search for `find` tool
- Better cursor handling
