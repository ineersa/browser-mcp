# Repository Guidelines

## Project Structure & Module Organization
- Source: `src/` (Symfony Console app). Key areas: `Command/` (entry commands), `Tools/` (MCP tools), `Service/` (SearchService, OpenService, FindService, BrowserState, Utilities, PageProcessor), `Service/Backend/` (SearxNG backend), `reference/` (Python stubs). Autoload namespace: `App\\`.
- Config: `config/` (DI, logging), env: `.env`, runtime files: `var/`.
- Binaries: `bin/console` (generic) and `bin/browser-mcp` (runs default `browser-mcp` command).
- Tests: `tests/` (PHPUnit), vendor deps in `vendor/`. Build artifacts in `dist/`.

## Build, Test, and Development Commands
- Install: `composer install` (PHP ≥ 8.2 required).
- Run locally: `php bin/browser-mcp` (default) or `php bin/console browser-mcp`; you can also invoke services via the container in app code.
  - Configure via `.env` (e.g., `APP_ENV=dev`, `APP_DEBUG=1`).
- Lint/format: `composer cs-fix` (php-cs-fixer, Symfony rules).
- Static analysis: `composer phpstan` (config `phpstan.dist.neon`).
- Tests: `composer tests` (PHPUnit testdox).
- Static binary (optional): `docker build -f static-build.Dockerfile .`.

## Coding Style & Naming Conventions
- PHP strict types; 4-space indent; UTF-8; PSR-4 under `App\\`.
- Classes: StudlyCase; methods/props: camelCase; constants: UPPER_SNAKE_CASE.
- Folders: `Command/*Command.php`, `Tools/*Tool.php`, `Service/*Service.php`.
- Run `composer cs-fix` before pushing; no mixed tabs/spaces. Keep imports ordered.

## Testing Guidelines
- Framework: PHPUnit 12. Tests live in `tests/`; bootstrap at `tests/bootstrap.php`.
- Name tests `*Test.php`, mirror namespaces. Prefer small, isolated tests around Tools/Services.
- Run `composer tests` locally; keep tests green and deterministic.

## Configuration
- Backend selection: set `BROWSER_BACKEND` to `searx` (default). Future values may be added.
- SearxNG endpoint: set `SEARXNG_URL` (default `http://server:8088`).
- All services read these via the container (see `config/services.yaml`).

## Service Usage Examples
- Search: `$search = $container->get(App\Service\SearchService::class); echo $search('rust book');`
- Open: `$open = $container->get(App\Service\OpenService::class); echo $open(0);`
- Find: `$find = $container->get(App\Service\FindService::class); echo $find(pattern: 'install');`

## MCP Tools
- Exposed tools: `browser.search`, `browser.open`, `browser.find`.

## Commit & Pull Request Guidelines
- Commits: imperative mood, concise scope (e.g., "Add SearchTool input validation"). Group related changes.
- PRs: include summary, rationale, and how to verify (commands/output). Link issues. Update docs if behavior changes.
- CI readiness: run `composer cs-fix`, `composer phpstan`, and `composer tests` before opening a PR.

## Security & Configuration Tips
- Never commit secrets. Use `.env.local` for machine-specific overrides.
- Logging via Monolog; adjust `LOG_LEVEL` and optional `APP_LOG_DIR` in `.env`.
