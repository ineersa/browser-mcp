# Development

## Local setup

```bash
composer install
```

Run server:

```bash
php bin/browser-mcp
```

Equivalent Symfony command:

```bash
php bin/console browser-mcp
```

## Quality checks

```bash
composer cs-fix
composer phpstan
composer tests
```

## Dev helper commands

Use these Symfony commands to quickly test behavior without an MCP client.

Interactive REPL for service-level testing:

```bash
php bin/console app:repl
```

- Modes: `search`, `reader`, `open`, `find`
- Useful for validating provider setup and checking raw output quickly

Log viewer for recent JSON logs:

```bash
php bin/console app:logs
```

- Shows latest entries in table form
- Inspect one entry with `--id=<line>`
- Limit rows with `--limit=<n>`

Code references:

- [src/Command/ReplCommand.php](../src/Command/ReplCommand.php)
- [src/Command/LogsCommand.php](../src/Command/LogsCommand.php)

## Debugging

Inspect MCP traffic:

```bash
npx @modelcontextprotocol/inspector
```

Run with Xdebug:

```bash
php -d xdebug.mode=debug -d xdebug.client_host=127.0.0.1 -d xdebug.client_port=9003 -d xdebug.start_with_request=yes bin/browser-mcp
```

## Runtime config

- Main config path comes from `CONFIG_FILE` (defaults to `browser_config.yaml`).
- Runtime files/logs/cache live under `APP_VAR_DIR`.
