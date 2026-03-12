# Web Research Subagent

Browser MCP includes a project-local OpenCode subagent setup for web research tasks.
It's an example, and I actually use for research before some tasks implementations.

Files:

- [.opencode/agents/web-researcher.md](../.opencode/agents/web-researcher.md)
- [.opencode/skills/web-research/SKILL.md](../.opencode/skills/web-research/SKILL.md)

## Intent

This setup enforces a stricter research workflow than ad-hoc single-page lookups:

- multiple search queries
- opening and cross-checking sources
- line-level evidence
- explicit handling for missing or conflicting evidence

## Usage

In OpenCode, invoke the subagent for research-heavy prompts (or use your project policy that routes to it automatically).

One-off lookups are still fine with direct `browser.search` / `browser.open` / `browser.find`.

## Notes

- The subagent configuration currently limits tools to `websearch_*` and `skill`.
- Keep this document and the `.opencode` files aligned when policies change.
