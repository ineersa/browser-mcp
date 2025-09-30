# browser-mcp

PHP/Symfony implementation of a simple browser MCP server with a pluggable backend (SearxNG). It provides three invokable services for search, open, and find, plus HTML→plaintext processing tailored for LLM consumption.

## Setup
- Requirements: PHP 8.2+, Composer
- Install: `composer install`

## Configuration
- `BROWSER_BACKEND` controls the backend driver. Default: `searx`.
  - Example: `BROWSER_BACKEND=searx`
- `SEARXNG_URL` points to your SearxNG instance. Default: `http://server:8088`.
  - Example: `SEARXNG_URL=https://searx.example.com`

These are wired via `config/services.yaml` using a factory that provides `App\\Service\\Backend\\BackendInterface`.

You can set env vars in a local `.env` or export them in your shell before running.

## Usage

### Run the MCP server
- Default command: `php bin/browser-mcp`
- Or via console: `php bin/console browser-mcp`

The server exposes tools: `browser.search`, `browser.open`, `browser.find`.

### Use services directly (via container)
Use the Symfony container to invoke the services directly (they are public):

```php
<?php
use App\Service\SearchService;
use App\Service\OpenService;
use App\Service\FindService;

// $container is the Symfony container
$search = $container->get(SearchService::class);
echo $search('gpt-4 research');

$open = $container->get(OpenService::class);
echo $open(0); // open first search result

$find = $container->get(FindService::class);
echo $find(pattern: 'benchmark');
echo $find(regex: '/bench(mark|press)/i');
```

## Development
- Lint/format: `composer cs-fix`
- Static analysis: `composer phpstan`
- Tests: `composer tests`

## Notes
- Exa backend is intentionally not implemented.
- Tools wiring calls these services; state is shared via `App\\Service\\BrowserState`.
