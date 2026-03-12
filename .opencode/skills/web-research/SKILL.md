---
name: web-research
description: For any web research task (multi-source lookup, fact checking, comparison, or evidence-backed summary), this skill MUST be loaded first.
license: MIT
compatibility: opencode
metadata:
  audience: researchers
  workflow: mcp-browser-tools
---

## What this skill does

Use this skill when the user asks for web research, fact checking, or summaries from online sources.
It enforces a strict evidence workflow using MCP browser tools and requires line-level citations.

## Invocation policy (mandatory)

- For any web research task (multi-source lookup, fact checking, comparison, or evidence-backed summary), this skill MUST be loaded first.
- All web research MUST be executed via the `Task` tool with `subagent_type: "web-researcher"`.
- Do not call direct websearch/browser tools from the main agent for research tasks.
- If the request includes both coding and web research, perform coding work normally but delegate all research steps to the `web-researcher` subagent.
- Exception: simple one-off `search`/`open`/`find` lookups are allowed directly without subagent orchestration.

## Subagent requirement (mandatory)

When handling web research, invoke exactly this pattern:

`Task(description: "...", subagent_type: "web-researcher", prompt: "...")`

- The subagent is responsible for discovery, source reading, verification, and citations.
- The parent agent should only coordinate scope, pass requirements, and present the subagent's evidence-backed results.

## Tools to use

- `browser.search`: discover candidate sources.
- `browser.open`: read source content with line numbers.
- `browser.find`: verify exact phrases or key terms in a source.

## Required workflow

1. Start with `browser.search` using one focused query that matches the user intent.
2. Run at least 2 additional searches with alternative phrasing (synonyms, official names, versions, dates, site-specific terms).
3. Open at least 3 relevant results with `browser.open` before drafting conclusions.
4. Follow links from strong sources when they point to primary evidence (official docs, original reports, specs, changelogs) and open those pages.
5. Use `browser.find` to verify every critical claim, number, date, version, and quoted wording.
6. Cross-check important claims across at least 2 independent sources when possible.
7. If sources conflict, report the conflict explicitly and cite both sides.
8. Write the final answer using only evidence collected in this run.

## Allowed research actions

- Run multiple query rounds until coverage is sufficient.
- Follow links discovered in search results or opened pages.
- Open additional pages to validate claims and context.
- Iterate between `search`, `open`, and `find` as needed.
- Stop only when claims are either verified with citations or marked as not found.

## Citation rules (mandatory)

- Every non-trivial factual claim must include a citation.
- Citations must include:
  - a direct URL
  - relevant line numbers from `browser.open` output (for example: `L6`, `L24`, `L41`)
- Prefer one citation per claim; for important claims, include multiple sources.
- If evidence is weak or conflicting, state that explicitly and cite both sources.

Recommended inline format:

`Claim text... ([1] https://example.com/page, lines L12, L18)`

Recommended references format at the end:

`[1] https://example.com/page (lines L12, L18)`

## Strictness rules (mandatory)

- Never invent facts, quotes, URLs, or line numbers.
- Never imply certainty without evidence.
- If data is not found in sources, say `Not found in reviewed sources`.
- If the page does not show the needed evidence, do not use it as support.
- Keep quotes exact when quoting; otherwise paraphrase carefully and still cite.
- Do not rely on prior memory for facts when current sources are available.
- Do not present a single-source claim as settled if independent confirmation is feasible.
- If the requested information cannot be found after reasonable research, return exactly: `Nothing found in reviewed sources`.
- If the task cannot be completed from available web evidence, return exactly: `Impossible to verify from available sources`.

## Output checklist before final answer

- Did I run multiple searches, not just one?
- Did I open and read primary sources?
- Did I verify critical claims with find/open lines?
- Does every important claim have URL + line citations?
- Did I avoid unsupported statements?
