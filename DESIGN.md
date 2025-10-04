# MCP Browser Server v2 — Architecture & API

> This document specifies a **model-reliable** browser toolset for MCP servers. It replaces the v1 “overloaded” API (union types, implicit cursors, UI banners) with **strict, single-purpose tools**, explicit state, and strongly typed payloads.

---

## Goals

* **Zero ambiguity** for models: no union types, no optional state.
* **Deterministic flows**: 1-based result indices; explicit cursor passing after search.
* **Machine + human outputs**: structured fields for planning **and** a `render` block for pasting.
* **Fail fast**: strict schema validation; no coercion of malformed inputs.
* **Incremental migration**: v2 can ship beside v1 and be flipped with a feature flag.

---

## Toolset Overview

| Tool          | Purpose                                              |
| ------------- | ---------------------------------------------------- |
| `search`      | Return top results and mint a **cursor**.            |
| `open_result` | Open a result from a prior `search` via `result_id`. |
| `open_url`    | Open an arbitrary URL (no dependency on `search`).   |
| `scroll`      | Move the viewport of an already opened page.         |
| `find`        | Find a pattern within an opened page.                |

**Design rules**

* After `search`, **every** follow-up call requires `cursor` (string).
* `result_id` is **always integer** (1-based). `url` is **always string** (absolute).
* Keys are `snake_case`. Input schemas use `additionalProperties: false`.

---

## Common Types

```ts
type CursorId = string;          // opaque (e.g., "c_9b2f0a")
type LineIndex = number;         // 0-based absolute line offset
type LineCount = number;         // number of lines to display

interface Viewport { start: LineIndex; end: LineIndex; }

interface Citation {
  cursor: CursorId;
  L_start: LineIndex;
  L_end: LineIndex;
}
```

---

## Tool Specifications

### 1) `search`

**Description**
Return top results for `query`. IDs are **1-based** and valid only for the returned `cursor`. To read, call `open_result` with that `cursor` and a `result_id`.

**Input Schema**

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "query": { "type": "string" },
    "topn":  { "type": "integer", "minimum": 1, "maximum": 10, "default": 5 }
  },
  "required": ["query"],
  "title": "searchArguments"
}
```

**Output Schema**

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "cursor": { "type": "string" },
    "results": {
      "type": "array",
      "items": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "id":     { "type": "integer", "minimum": 1 },
          "title":  { "type": "string" },
          "url":    { "type": "string", "format": "uri" },
          "snippet":{ "type": "string" }
        },
        "required": ["id", "title", "url"]
      }
    },
    "render": { "type": "string" }
  },
  "required": ["cursor", "results", "render"],
  "title": "searchOutput"
}
```

**Behavior**

* Mint a **new** `cursor` that binds to this result set (stable order).
* `render` contains a concise, numbered list for LLMs/humans.

| Field     | Level        | Data Type | Content Example                     | Used For                               |
| --------- | ------------ | --------- | ----------------------------------- | -------------------------------------- |
| `snippet` | Per-result   | `string`  | “The Scheduler component allows…”   | Summarizing each search hit            |
| `render`  | Whole output | `string`  | Formatted block listing all results | The text block the model sees / pastes |


---

### 2) `open_result`

**Description**
Open a page from a prior `search` by `cursor` and **1-based** `result_id`. Use `loc` (absolute line) and `num_lines` to control viewport.

**Input Schema**

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "cursor":    { "type": "string" },
    "result_id": { "type": "integer", "minimum": 1 },
    "loc":       { "type": "integer", "minimum": 0, "default": 0 },
    "num_lines": { "type": "integer", "minimum": 20, "maximum": 200, "default": 80 }
  },
  "required": ["cursor", "result_id"],
  "title": "openResultArguments"
}
```

**Output Schema**

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "cursor":  { "type": "string" },
    "url":     { "type": "string", "format": "uri" },
    "title":   { "type": "string" },
    "viewport": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "start": { "type": "integer" },
        "end":   { "type": "integer" }
      },
      "required": ["start", "end"]
    },
    "text":    { "type": "string" },
    "citation": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "cursor":  { "type": "string" },
        "L_start": { "type": "integer" },
        "L_end":   { "type": "integer" }
      },
      "required": ["cursor", "L_start", "L_end"]
    },
    "render":  { "type": "string" }
  },
  "required": ["cursor", "url", "viewport", "text", "citation", "render"],
  "title": "openResultOutput"
}
```

**Behavior**

* Resolves `result_id` to URL using the `cursor`’s search state.
* Returns a **viewport slice** of the page content.
* `citation` is ready to be included with factual claims.

---

### 3) `open_url`

**Description**
Open an arbitrary `url`. Use when you already have a URL (no dependency on `search`).

**Input Schema**

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "url":      { "type": "string", "format": "uri" },
    "loc":      { "type": "integer", "minimum": 0, "default": 0 },
    "num_lines":{ "type": "integer", "minimum": 20, "maximum": 200, "default": 80 }
  },
  "required": ["url"],
  "title": "openUrlArguments"
}
```

**Output Schema**

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "cursor":  { "type": "string" },
    "url":     { "type": "string", "format": "uri" },
    "title":   { "type": "string" },
    "viewport": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "start": { "type": "integer" },
        "end":   { "type": "integer" }
      },
      "required": ["start", "end"]
    },
    "text":    { "type": "string" },
    "citation": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "cursor":  { "type": "string" },
        "L_start": { "type": "integer" },
        "L_end":   { "type": "integer" }
      },
      "required": ["cursor", "L_start", "L_end"]
    },
    "render":  { "type": "string" }
  },
  "required": ["cursor", "url", "viewport", "text", "citation", "render"],
  "title": "openUrlOutput"
}
```

**Behavior**

* Mints a **new** `cursor` bound to this page.
* Returns a viewport slice + machine citation.

---

### 4) `scroll`

**Description**
Scroll the page identified by `cursor`. Prefer absolute `loc`; `delta` is a relative adjustment.

**Input Schema**

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "cursor":    { "type": "string" },
    "loc":       { "type": "integer", "minimum": 0 },
    "delta":     { "type": "integer", "minimum": -5000, "maximum": 5000, "default": 0 },
    "num_lines": { "type": "integer", "minimum": 20, "maximum": 200, "default": 80 }
  },
  "required": ["cursor"],
  "title": "scrollArguments"
}
```

**Output Schema**

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "cursor":  { "type": "string" },
    "viewport": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "start": { "type": "integer" },
        "end":   { "type": "integer" }
      },
      "required": ["start", "end"]
    },
    "text":    { "type": "string" },
    "citation": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "cursor":  { "type": "string" },
        "L_start": { "type": "integer" },
        "L_end":   { "type": "integer" }
      },
      "required": ["cursor", "L_start", "L_end"]
    },
    "render":  { "type": "string" }
  },
  "required": ["cursor", "viewport", "text", "citation", "render"],
  "title": "scrollOutput"
}
```

---

### 5) `find`

**Description**
Find `pattern` in the page given by `cursor`. Set `is_regex` true for regex.

**Input Schema**

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "cursor":   { "type": "string" },
    "pattern":  { "type": "string" },
    "is_regex": { "type": "boolean", "default": false }
  },
  "required": ["cursor", "pattern"],
  "title": "findArguments"
}
```

**Output Schema**

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "cursor": { "type": "string" },
    "matches": {
      "type": "array",
      "items": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "loc":     { "type": "integer" },
          "preview": { "type": "string" }
        },
        "required": ["loc", "preview"]
      }
    },
    "render": { "type": "string" }
  },
  "required": ["cursor", "matches", "render"],
  "title": "findOutput"
}
```

---

## Error Model

* **Validation layer** rejects malformed payloads before handlers run.
* **No coercion**. Wrong shapes return:

```json
{
  "error": "invalid_argument",
  "message": "result_id must be an integer",
  "hint": "Call open_result with {cursor, result_id} (1-based)."
}
```

* Common errors:

    * `unknown_cursor`: expired or not found.
    * `result_out_of_range`: `result_id` not in current result set.
    * `unsupported_mime`: non-textual content w/o extractor.
    * `fetch_failed`: network/HTTP issues (include status code).
    * `timeout`: page load/extract exceeded budget.

---

## Server Services

> Keep these as small, focused classes; most are pure or side-effect–light. Names are illustrative.

1. **CursorManager**

    * Issues opaque `CursorId`, tracks **search result sets** and **page snapshots**.
    * Guarantees **stable 1-based indices** for a cursor’s results.
    * TTL + eviction policy (LRU/time-based).

2. **SearchEngine**

    * Wraps your actual web search provider.
    * Returns `SearchResult[]` (title, url, snippet).
    * Can include normalization (dedupe by canonical URL).

3. **PageLoader**

    * Fetches URL, follows redirects, normalizes encodings.
    * MIME/type sniffing, content-length guardrails, timeouts.
    * Extracts **plain text** + link map via **ContentExtractor** (HTML, PDF via text layer, etc.).

4. **ContentExtractor**

    * Strategy per MIME: HTML → DOM → text; PDF → text layer; TXT → pass-through.
    * Builds `PageSnapshot` (see Data Structures).

5. **ViewportService**

    * Slices `PageSnapshot.text` by `loc`/`num_lines`.
    * Calculates `{start,end}` + returns `text` fragment.
    * Provides **line index mapping** stable across scrolls.

6. **CitationService**

    * Generates `Citation` from cursor + viewport.
    * Optional: converts to human bracket style `【cursor†Lx-Ly】`.

7. **FindService**

    * Runs literal or regex search against snapshot text.
    * Produces `Match[]` with `loc` and `preview`.

8. **RenderBuilder**

    * Produces concise `render` strings for each tool output.
    * Result lists, page slices with headers, match tables.

9. **SchemaValidator (Middleware)**

    * Validates input against JSON Schema (`additionalProperties:false`).
    * Emits structured errors; blocks execution on failure.

10. **Telemetry & RateLimiter (Optional)**

    * Metrics for success/error types, latency, sizes.
    * Per-tenant and global budgets.

11. **Cache (Optional)**

    * Page content & normalized text keyed by canonical URL + ETag.

---

## Data Structures (PHP)

> Immutable where possible; DTOs are friendly to tests/serialization.

```php
final readonly class SearchResult
{
    public function __construct(
        public int $id,           // 1-based within a cursor
        public string $title,
        public string $url,
        public ?string $snippet = null,
    ) {}
}

final readonly class PageSnapshot
{
    /**
     * @param array<int,string> $lines    // pre-split text, one line per entry
     * @param array<int,LinkRef> $outgoing // stable per-cursor link refs (optional)
     */
    public function __construct(
        public string $url,
        public string $title,
        public array $lines,
        public ?array $outgoing = null,
        public ?string $errorMessage = null,
    ) {}
}

final readonly class LinkRef
{
    public function __construct(
        public int $id,           // 1-based link id for this snapshot (optional)
        public string $url,
        public string $title,
        public ?int $lineIdx = null,
    ) {}
}

final readonly class ViewSlice
{
    public function __construct(
        public int $start,        // line index inclusive
        public int $end,          // line index inclusive
        public string $text,      // joined lines[start..end]
    ) {}
}

final readonly class CitationDto
{
    public function __construct(
        public string $cursor,
        public int $L_start,
        public int $L_end,
    ) {}
}

final readonly class MatchDto
{
    public function __construct(
        public int $loc,
        public string $preview,
    ) {}
}

final readonly class CursorBinding
{
    /**
     * @param SearchResult[] $results
     */
    public function __construct(
        public string $cursor,
        public array $results,             // populated after search
        public ?PageSnapshot $snapshot = null // set after open_url/open_result
    ) {}
}
```

### Mapping from your current types

* Your `PageContents` → **PageSnapshot**

    * `url`, `title` map 1:1
    * `text` → split into `lines` (pre-split once; fast viewport slices)
    * `urls` map → lift into `outgoing: LinkRef[]`
    * `snippets` (array<string, Extract>) → optional `outgoing` or a dedicated `Snippet` DTO if you keep both

* Your `Extract` → **LinkRef** or **MatchDto** depending on usage

    * For “found results within page”: use `MatchDto` with `loc`, `preview`
    * For “outgoing link catalog”: use `LinkRef` with `lineIdx`

---

## Handler Skeletons (Symfony-style)

```php
#[McpTool(name: 'search', description: 'Return top results for `query`...')]
final class SearchTool
{
    public function __construct(
        private SearchEngine $engine,
        private CursorManager $cursors,
        private RenderBuilder $render,
    ) {}

    public function __invoke(string $query, int $topn = 5): array
    {
        $raw = $this->engine->search($query, $topn);
        $results = [];
        foreach (array_values($raw) as $i => $r) {
            $results[] = new SearchResult($i + 1, $r->title, $r->url, $r->snippet ?? null);
        }
        $binding = $this->cursors->createForResults($results);

        return [
            'cursor'  => $binding->cursor,
            'results' => array_map(fn(SearchResult $sr) => [
                'id' => $sr->id, 'title' => $sr->title, 'url' => $sr->url, 'snippet' => $sr->snippet ?? ''
            ], $results),
            'render'  => $this->render->results($results),
        ];
    }
}
```

```php
#[McpTool(name: 'open_result', description: 'Open from prior search by `cursor` and 1-based `result_id`.')]
final class OpenResultTool
{
    public function __construct(
        private CursorManager $cursors,
        private PageLoader $loader,
        private ViewportService $viewport,
        private CitationService $cite,
        private RenderBuilder $render,
    ) {}

    public function __invoke(string $cursor, int $result_id, int $loc = 0, int $num_lines = 80): array
    {
        $binding  = $this->cursors->require($cursor);
        $result   = $this->cursors->resolveResult($binding, $result_id);
        $snapshot = $this->loader->open($result->url);
        $this->cursors->attachSnapshot($binding, $snapshot);

        $slice    = $this->viewport->slice($snapshot, $loc, $num_lines);
        $citation = $this->cite->fromSlice($binding->cursor, $slice);

        return [
            'cursor'   => $binding->cursor,
            'url'      => $snapshot->url,
            'title'    => $snapshot->title,
            'viewport' => ['start' => $slice->start, 'end' => $slice->end],
            'text'     => $slice->text,
            'citation' => ['cursor' => $citation->cursor, 'L_start' => $citation->L_start, 'L_end' => $citation->L_end],
            'render'   => $this->render->viewport($snapshot, $slice),
        ];
    }
}
```

---

## Rendering Guidance (`render`)

Keep it compact and stable. Avoid UI banners like `[CURSOR:#0]`. Example:

```
Results (cursor c_9b2f0a)
1) Scheduler (Symfony Docs) — symfony.com
   URL: https://symfony.com/doc/current/components/scheduler.html
   Snippet: The Scheduler component helps you schedule tasks...

2) How does Symfony Scheduler get triggered? — stackoverflow.com
   URL: https://stackoverflow.com/q/...
   Snippet: With the Symfony Scheduler we can schedule actions...
```

For page viewports:

```
Title: Scheduler (Symfony Docs)
URL: https://symfony.com/doc/current/components/scheduler.html
Lines 120–199:

L120: The Scheduler component helps...
...
L199: See also: Messenger integration.
```

---

## Validation & Middleware

* Apply schema validation **before** handlers.
* Enforce:

    * `additionalProperties: false`
    * exact key names (`num_lines`, not `numLines`)
    * strict types (no union types)
* Return structured errors; never guess the intent.

---

## Testing Strategy

* **Contract tests** per tool:

    * Happy path (search → open_result → scroll → find)
    * Shape errors (missing required, wrong types, extra fields)
    * Boundary (result_id out of range; loc near EOF)
* **Snapshot tests**:

    * `render` strings (tolerate whitespace via normalized compare)
    * `viewport` start/end stable under same inputs
* **Services**:

    * `ViewportService::slice` with synthetic pages
    * `FindService` literal and regex
    * `CursorManager` stability and TTL eviction
* **Integration**:

    * End-to-end simulating model usage sequences
    * Network timeouts and MIME edge cases (PDF, HTML w/o body, etc.)

---

## Migration from v1

* Keep v1 (`search/open/find`) flagged as **deprecated**.
* Introduce v2 tools under new names.
* **No overloading**: `open_result` vs `open_url` instead of `open(id | url)`.
* In tests, port fixtures; adjust assertions to new output shapes.
* Monitor calls: log v1 usage; remove after grace period.

---

## Performance & Caching

* Cache normalized page text by `(canonical_url, ETag, Content-Length)`.
* Pre-split into lines once; store in `PageSnapshot.lines`.
* Guardrails:

    * Max fetch size (e.g., 2–5 MB)
    * Global timeout per fetch (e.g., 8–12 s)
    * Per-tenant rate limits

---

## Security Basics

* Respect `robots.txt` / `noindex` where appropriate for your use-case.
* Set a clear UA string; retry with backoff; handle 429.
* Sanitize outputs; strip scripts/iframes during extraction.

---

## Design Rationale (Why this works better for LLMs)

* **No unions** → models stop sending `{ "id": { "int": 0 } }`.
* **Explicit cursor** → prevents “open current page” ambiguity.
* **Split tools** → different inputs/outputs don’t collide.
* **Machine + render** → planning stays reliable; the reply looks good.
* **Fail fast** → models learn the contract quickly from clear errors.

---

## Quick Checklist

* [ ] Implement tools & schemas exactly as specified (strict).
* [ ] Add SchemaValidator middleware.
* [ ] Build CursorManager, ViewportService, CitationService, RenderBuilder.
* [ ] Keep indices **1-based** for `search.results`.
* [ ] Always require `cursor` post-search.
* [ ] Return `render` in **every** output.
* [ ] Log structured errors; avoid coercion.
* [ ] Migrate tests; monitor; deprecate v1.

---

If you want, I can tailor these DTOs to your exact namespaces and add a few PHPUnit test cases (happy path + invalid payload) matching your current project layout.
