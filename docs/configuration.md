# Configuration

This page contains setup details that are intentionally kept out of [README.md](../README.md).

## Runtime config file

Main runtime config is `browser_config.yaml` (path controlled by `CONFIG_FILE`).

It controls:

- transport (`stdio` or `http`)
- host/port for HTTP mode
- cache TTL values
- selected searcher and reader providers
- per-provider options (tokens, timeouts, retries, user-agent, reader noise hints)

## Environment variables

Common runtime envs:

```bash
export APP_ENV=prod
export APP_DEBUG=false
export LOG_LEVEL=warning
export APP_VAR_DIR=/tmp/mcp/browser-mcp
export CONFIG_FILE=/absolute/path/to/browser_config.yaml
```

Provider tokens (set only if needed):

```bash
export JINA_SEARCH_TOKEN=""
export JINA_READER_TOKEN=""
export TAVILY_SEARCH_TOKEN=""
export TAVILY_READER_TOKEN=""
```

## Client config examples

For OpenCode and Cursor examples, see [docs/opencode-setup.md](opencode-setup.md).

## HTTP mode

To run as network MCP endpoint, set in `browser_config.yaml`:

```yaml
general:
  transport: http
  host: 127.0.0.1
  port: 9001
```

Then run with your chosen artifact (`browser-mcp`, `dist/browser-mcp`, or `dist/browser-mcp.phar`).

Full HTTP + systemd setup is in [docs/http-server-systemd.md](http-server-systemd.md).

## Related docs

- [README.md](../README.md)
- [docs/providers.md](providers.md)
- [docs/http-server-systemd.md](http-server-systemd.md)
