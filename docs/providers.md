# Providers

Browser MCP has two provider groups:

- Searchers (`browser.search`)
- Readers (`browser.open` and `browser.find`)

Provider selection is done in `browser_config.yaml`.

## Configuration shape

```yaml
searchers:
    selected: searxng
    providers:
        searxng:
            url: http://server:8088
        jinaai:
            token: "%env(JINA_SEARCH_TOKEN)%"
        tavily:
            token: "%env(TAVILY_SEARCH_TOKEN)%"
        duckduckgo:
            timeout_seconds: 5
            max_retries: 1
            user_agent: "Mozilla/5.0 ..."

readers:
    selected: http
    providers:
        http:
            timeout_seconds: 30
            max_retries: 2
            user_agent: "Mozilla/5.0 ..."
            noise_class_tokens:
                - codeblock-lines
                - linenos
                - line-numbers
                - gutter
        jinaai:
            token: "%env(JINA_READER_TOKEN)%"
            timeout_seconds: 15
            max_retries: 1
        tavily:
            token: "%env(TAVILY_READER_TOKEN)%"
            timeout_seconds: 15
            max_retries: 1
```

## Search providers

### SearxNG

- Select with `searchers.selected: searxng` (alias `searx`)
- Requires `searchers.providers.searxng.url`
- API key not required

This requires setup of SearxNG with JSON output, I recommend to check out
[https://docs.openwebui.com/features/chat-conversations/web-search/providers/searxng/](https://docs.openwebui.com/features/chat-conversations/web-search/providers/searxng/) for setup instructions.

### DuckDuckGo Lite

- Select with `searchers.selected: duckduckgo` (aliases: `duckduckgolite`, `ddg`)
- API key not required
- Optional tuning: `timeout_seconds`, `max_retries`, `user_agent`

Basically we are parsing DuckDuckGo Lite output.
Be careful using it as you can get blocked.

### Jina AI Search

- Select with `searchers.selected: jinaai` (alias `jina`)
- Requires token in `searchers.providers.jinaai.token`
- Typical env mapping: `JINA_SEARCH_TOKEN`

Jina AI search available only with token.
You can get free token with 10M tokens included, each search request takes 10K tokens.

### Tavily Search

- Select with `searchers.selected: tavily`
- Requires token in `searchers.providers.tavily.token`
- Typical env mapping: `TAVILY_SEARCH_TOKEN`

Tavily has free tier and gives 1000 API tokens for a month.

Tavily search tool is slightly different from others, as we use it with full content of search page results.

It's great as it provides larger snippets, and we cache pages from search results for further opening, which saves times and API tokens.

## Reader providers

### HTTP Reader

- Select with `readers.selected: http`
- API key not required
- Supports retries, timeouts, custom user agent, and noise-class cleanup hints

Markdown conversion done with [https://github.com/ineersa/html2text](https://github.com/ineersa/html2text).
It supports Github links with custom opening rules.
It cleans up context from navigation and non-important blocks.

Works pretty well for technical docs, github, blogs and so on.

### Jina AI Reader

- Select with `readers.selected: jinaai` (alias `jina`)
- Requires token in `readers.providers.jinaai.token`
- Typical env mapping: `JINA_READER_TOKEN`

Well just a wrapper for Jina AI reader, can be used for free, works nice.

### Tavily Reader

- Select with `readers.selected: tavily`
- Requires token in `readers.providers.tavily.token`
- Typical env mapping: `TAVILY_READER_TOKEN`

Wrapper for Tavily extract, don't recommend using it as it eats API tokens, and it's better to use that free ones on a search.

## Env var checklist

Only set tokens for providers you actually use:

```bash
export JINA_SEARCH_TOKEN=""
export JINA_READER_TOKEN=""
export TAVILY_SEARCH_TOKEN=""
export TAVILY_READER_TOKEN=""
```

## Related docs

- [README.md](../README.md)
- [docs/architecture.md](architecture.md)
