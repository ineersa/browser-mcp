# HTTP Server and systemd

Use this mode when you need an HTTP endpoint over STDIO.
Underneath it's running php dev server for streamable HTTP, so it's not for production, but it's good enough for general usage.

Example startup shell script: [scripts/run-dist-browser-mcp.sh](../scripts/run-dist-browser-mcp.sh).

## 1) Enable HTTP transport

Set in `browser_config.yaml`:

```yaml
general:
    transport: http
    host: 127.0.0.1
    port: 9001
```

## 2) Export env vars

```bash
export APP_ENV=prod
export APP_DEBUG=false
export LOG_LEVEL=warning
export APP_VAR_DIR=/tmp/mcp/browser-mcp
export CONFIG_FILE=/absolute/path/to/browser_config.yaml
export JINA_SEARCH_TOKEN=""
export JINA_READER_TOKEN=""
export TAVILY_SEARCH_TOKEN=""
export TAVILY_READER_TOKEN=""
```

## 3) Start server

```bash
./dist/browser-mcp
```

Or use the example script:

```bash
./scripts/run-dist-browser-mcp.sh
```

MCP endpoint:

- `http://127.0.0.1:9001/mcp`

## `mcp.json` example (HTTP)

Generic `mcp.json` style:

```json
{
  "mcpServers": {
    "browser": {
      "url": "http://127.0.0.1:9001/mcp"
    }
  }
}
```

OpenCode remote example:

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

## Run as user systemd service

Create environment file:

```bash
mkdir -p ~/.config/browser-mcp
cat > ~/.config/browser-mcp/browser-mcp.env <<'EOF'
APP_ENV=prod
APP_DEBUG=false
LOG_LEVEL=warning
APP_VAR_DIR=/tmp/mcp/browser-mcp
CONFIG_FILE=/absolute/path/to/browser_config.yaml
JINA_SEARCH_TOKEN=
JINA_READER_TOKEN=
TAVILY_SEARCH_TOKEN=
TAVILY_READER_TOKEN=
EOF
```

Create `~/.config/systemd/user/browser-mcp.service`:

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

Enable and start:

```bash
systemctl --user daemon-reload
systemctl --user enable --now browser-mcp.service
systemctl --user status browser-mcp.service
```

Logs:

```bash
journalctl --user -u browser-mcp.service -f
```
