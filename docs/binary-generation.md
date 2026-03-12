# Binary Generation

You have three common run artifacts:

- Source entrypoint: `bin/browser-mcp`
- PHAR: `dist/browser-mcp.phar`
- Native binary: `dist/browser-mcp`

## Build PHAR with Box

Install Box first (see official Box install docs), then run:

```bash
box compile
```

Default output path is `dist/browser-mcp.phar` (see `box.json.dist`).

## Build native binary

The repository includes `prepare_binary.sh`, which:

1. Copies project to `/tmp/browser-mcp`
2. Builds PHAR with Box
3. Builds static image via `static-build.Dockerfile`
4. Copies generated files back to local `dist/`

Run:

```bash
./prepare_binary.sh
```

Requirements:

- Docker
- Box
- Linux environment compatible with the static build flow

## Notes

- Prefer release artifacts if you do not need custom builds.
- Runtime env vars (`APP_VAR_DIR`, `CONFIG_FILE`, tokens) are still required for PHAR/native runs.
