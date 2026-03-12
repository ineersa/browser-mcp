# Architecture

This document explains how Browser MCP processes `search`, `open`, and `find`, and where provider selection, formatting, and caching happen.

## High-level flow

```text
MCP client
   |
   v
ServerFactory (registers tools)
   |
   +--> SearchTool -> SearchService -> SearcherContract (provider from SearcherFactory)
   |
   +--> OpenTool   -> OpenService   -> ReaderContract   (provider from ReaderFactory)
   |
   +--> FindTool   -> FindService   -> ReaderContract   (provider from ReaderFactory)
```

Tools are registered in [src/Server/ServerFactory.php](../src/Server/ServerFactory.php).

## Provider layer

Provider choice is data-driven via `browser_config.yaml`:

- Searcher provider from `searchers.selected`
- Reader provider from `readers.selected`

Factory mapping:

- Search: `searxng`, `jinaai`/`jina`, `tavily`, `duckduckgo`/`duckduckgolite`/`ddg`
- Reader: `http`, `jinaai`/`jina`, `tavily`

Code references:

- [src/Service/Searcher/SearcherFactory.php](../src/Service/Searcher/SearcherFactory.php)
- [src/Service/Reader/ReaderFactory.php](../src/Service/Reader/ReaderFactory.php)

## Formatter chains

Each service builds a formatter chain that transforms domain objects into final tool output.

Search chain:

```text
SearchResultSet
  -> NormalizeHitsFormatter
  -> SearchResultToArrayFormatter
  -> ToonFormatter
  -> TOON text output
```

Open chain:

```text
ReadDocument
  -> NumLinesFormatter
  -> LinedOutputFormatter
  -> markdown/text output with header, line numbers, references
```

Find chain:

```text
FindDocument
  -> FindResultToArrayFormatter
  -> ToonFormatter
  -> TOON text output
```

Code references:

- [src/Service/Formatter/FormatterChain.php](../src/Service/Formatter/FormatterChain.php)
- [src/Service/SearchService.php](../src/Service/SearchService.php)
- [src/Service/OpenService.php](../src/Service/OpenService.php)
- [src/Service/FindService.php](../src/Service/FindService.php)

## Caching model

Browser MCP uses Symfony cache via `CacheInterface`.

### Cache keys and TTLs

- `search_result_set.<sha256(provider|topn|query)>`
  - Written by `SearchService`
  - TTL: `general.search_cache_ttl_seconds` (default `600`)
- `search_snippets.<sha256(canonicalUrl)>`
  - Written by `SearchService`
  - Stores normalized snippets for open-window anchoring
  - TTL: `general.search_cache_ttl_seconds`
- `read_document.<sha256(canonicalUrl)>`
  - Written by `OpenService` and `FindService`
  - TTL: `general.open_cache_ttl_seconds` (default `300`)

### Important runtime behavior

`BrowserMcpCommand` clears app cache on startup (`clearAppCache()`), so cache is warm only within a running server lifecycle.

Code references:

- [src/Command/BrowserMcpCommand.php](../src/Command/BrowserMcpCommand.php)
- [src/Service/SearchService.php](../src/Service/SearchService.php)
- [src/Service/OpenService.php](../src/Service/OpenService.php)
- [src/Service/FindService.php](../src/Service/FindService.php)

## Search/Open coupling

`search` and `open` are intentionally connected:

1. `search` stores per-URL snippets in cache.
2. `open` (when `startAtLine` is omitted) tries to locate those snippets in wrapped page lines.
3. If matched, `open` starts near the best line; otherwise it falls back to top-of-page.

This improves first-open relevance without forcing clients to manage offsets manually.

## URL normalization and line model

- URLs are canonicalized in `Utilities::canonicalizeUrl()` before caching and reads.
- Page text is wrapped into fixed-width lines (`Utilities::wrapLines`, width 80).
- `open` line numbers are zero-based (`L0`, `L1`, ...).

Code references:

- [src/Service/Utilities.php](../src/Service/Utilities.php)
- [src/Service/Formatter/NumLinesFormatter.php](../src/Service/Formatter/NumLinesFormatter.php)
- [src/Service/Formatter/LinedOutputFormatter.php](../src/Service/Formatter/LinedOutputFormatter.php)
