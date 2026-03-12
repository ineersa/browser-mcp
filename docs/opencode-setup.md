# OpenCode Setup

This page keeps client setup examples out of the main README.

## OpenCode (`opencode.json`)

```json
{
  "$schema": "https://opencode.ai/config.json",
  "mcp": {
    "browser-cli": {
      "type": "local",
      "command": "/absolute/path/to/browser-mcp/dist/browser-mcp",
      "env": {
        "APP_ENV": "prod",
        "APP_DEBUG": "false",
        "LOG_LEVEL": "warning",
        "APP_VAR_DIR": "/tmp/mcp/browser-mcp",
        "CONFIG_FILE": "/absolute/path/to/browser-mcp/browser_config.yaml",
        "JINA_SEARCH_TOKEN": "",
        "JINA_READER_TOKEN": "",
        "TAVILY_SEARCH_TOKEN": "",
        "TAVILY_READER_TOKEN": ""
      },
      "enabled": true
    }
  }
}
```

## OpenCode over HTTP endpoint

If Browser MCP runs in HTTP mode, use a remote MCP entry:

```json
{
  "$schema": "https://opencode.ai/config.json",
  "mcp": {
    "websearch": {
      "type": "remote",
      "url": "http://127.0.0.1:9001/sse",
      "enabled": true
    }
  }
}
```

See [docs/http-server-systemd.md](http-server-systemd.md) for full HTTP/systemd setup and `mcp.json` HTTP examples.
