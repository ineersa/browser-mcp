<?php
// Simple Browser Tool (PHP translation)
// Focused on SearxNG backend only. Mirrors the Python logic and structure.
// No MCP server glue here; just the core logic translated.

namespace GptOss\Tools\SimpleBrowser;

use DOMDocument;
use DOMXPath;

// -----------------------------
// Errors
// -----------------------------
class ToolUsageError extends \Exception {}
class BackendError extends \Exception {}

// -----------------------------
// Data classes
// -----------------------------
class Extract
{
    public string $url;
    public string $text;
    public string $title;
    public ?int $line_idx;

    public function __construct(string $url, string $text, string $title, ?int $line_idx = null)
    {
        $this->url = $url;
        $this->text = $text;
        $this->title = $title;
        $this->line_idx = $line_idx;
    }
}

class PageContents
{
    public string $url;
    public string $text;
    public string $title;
    /** @var array<string,string> */
    public array $urls;
    /** @var array<string,Extract>|null */
    public ?array $snippets;
    public ?string $error_message;

    public function __construct(string $url, string $text, string $title, array $urls = [], ?array $snippets = null, ?string $error_message = null)
    {
        $this->url = $url;
        $this->text = $text;
        $this->title = $title;
        $this->urls = $urls;
        $this->snippets = $snippets;
        $this->error_message = $error_message;
    }
}

// -----------------------------
// Constants and regex patterns
// -----------------------------
const VIEW_SOURCE_PREFIX = 'view-source:';
const ENC_NAME = 'o200k_base'; // placeholder; PHP version approximates token sizing

// Same link formats/patterns as Python code
const FIND_PAGE_LINK_FORMAT = '# 【%s†%s】';

// Patterns for stripping links
const PARTIAL_INITIAL_LINK_PATTERN = '/^[^【】]*】/u';
const PARTIAL_FINAL_LINK_PATTERN = '/【\d*(?:†(?P<content>[^†】]*)(?:†[^†】]*)?)?$/u';
const LINK_PATTERN = '/【\d+†(?P<content>[^†】]+)(?:†[^†】]+)?】/u';

// Patterns for html processing akin to page_contents.py
const HTML_SUP_RE = '/<sup( [^>]*)?>([\w\-]+)<\/sup>/i';
const HTML_SUB_RE = '/<sub( [^>]*)?>([\w\-]+)<\/sub>/i';
const HTML_TAGS_SEQ_RE = '/(?<=\w)((<[^>]*>)+)(?=\w)/u';
const WHITESPACE_ANCHOR_RE = '/(【\@[^】]+】)(\s+)/u';
const EMPTY_LINE_RE = '/^\s+$/m';
const EXTRA_NEWLINE_RE = '/\n(\s*\n)+/';

// -----------------------------
// Utility functions matching Python behavior
// -----------------------------
function maybe_truncate(string $text, int $num_chars = 1024): string {
    if (mb_strlen($text, 'UTF-8') > $num_chars) {
        $text = mb_substr($text, 0, $num_chars - 3, 'UTF-8') . '...';
    }
    return $text;
}

function join_lines(array $lines, bool $add_line_numbers = false, int $offset = 0): string {
    if ($add_line_numbers) {
        $out = [];
        foreach ($lines as $i => $line) {
            $out[] = 'L' . ($i + $offset) . ': ' . $line;
        }
        return implode("\n", $out);
    }
    return implode("\n", $lines);
}

function wrap_lines(string $text, int $width = 80): array {
    $lines = explode("\n", $text);
    $wrapped = [];
    foreach ($lines as $line) {
        if ($line === '') {
            $wrapped[] = '';
            continue;
        }
        $len = mb_strlen($line, 'UTF-8');
        $start = 0;
        while ($start < $len) {
            $wrapped[] = mb_substr($line, $start, $width, 'UTF-8');
            $start += $width;
        }
        if ($len === 0) {
            $wrapped[] = '';
        }
    }
    return $wrapped;
}

function strip_links(string $text): string {
    $text = preg_replace(PARTIAL_INITIAL_LINK_PATTERN, '', $text) ?? $text;
    $text = preg_replace_callback(PARTIAL_FINAL_LINK_PATTERN, function ($m) {
        return $m['content'] ?? '';
    }, $text) ?? $text;
    $text = preg_replace_callback(LINK_PATTERN, function ($m) {
        return $m['content'] ?? '';
    }, $text) ?? $text;
    return $text;
}

// Approximate token-based end location computation (no tiktoken in PHP)
function max_chars_per_token(string $enc_name): int {
    // Use a safe upper bound; Python uses tiktoken vocab max length; set to 128
    return 128;
}

function get_end_loc(int $loc, int $num_lines, int $total_lines, array $lines, int $view_tokens, string $encoding_name): int {
    if ($num_lines <= 0) {
        $txt = join_lines(array_slice($lines, $loc), true, $loc);
        if (mb_strlen($txt, 'UTF-8') > $view_tokens) {
            $upper_bound = max_chars_per_token($encoding_name);
            $slice = mb_substr($txt, 0, ($view_tokens + 1) * $upper_bound, 'UTF-8');
            // crude: assume 1 char per token and count newlines up to view_tokens chars
            $end_idx = min(mb_strlen($slice, 'UTF-8'), $view_tokens * $upper_bound);
            $consider = mb_substr($txt, 0, $end_idx, 'UTF-8');
            $num_lines = substr_count($consider, "\n") + 1;
        } else {
            $num_lines = $total_lines;
        }
    }
    return min($loc + $num_lines, $total_lines);
}

function get_domain(string $url): string {
    if (stripos($url, 'http') === false) {
        $url = 'http://' . $url;
    }
    $parts = parse_url($url);
    return $parts['host'] ?? '';
}

function merge_whitespace(string $text): string {
    $text = str_replace("\n", ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return $text;
}

function arxiv_to_ar5iv(string $url): string {
    return preg_replace('/arxiv\.org/i', 'ar5iv.org', $url) ?? $url;
}

function remove_unicode_smp(string $text): string {
    // Remove code points U+10000–U+1FFFF (PHP PCRE supports \x{...})
    return preg_replace('/[\x{10000}-\x{1FFFF}]/u', '', $text) ?? $text;
}

// -----------------------------
// HTML processing that mirrors process_html behavior
// -----------------------------
function replace_node_with_text_dom(\DOMNode $node, string $text): void {
    $doc = $node->ownerDocument;
    if (!$doc) return;
    $textNode = $doc->createTextNode($text . ($node->nextSibling && $node->nextSibling->nodeType === XML_TEXT_NODE ? $node->nextSibling->nodeValue : ''));
    $parent = $node->parentNode;
    if (!$parent) return;
    $parent->insertBefore($textNode, $node->nextSibling);
    $parent->removeChild($node);
}

function clean_links(DOMDocument $doc, string $cur_url): array {
    $xpath = new DOMXPath($doc);
    $cur_domain = get_domain($cur_url);
    $urls = [];
    $urls_rev = [];
    foreach ($xpath->query('//a[@href]') as $a) {
        if (!($a instanceof \DOMElement)) continue;
        $href = $a->getAttribute('href');
        if (stripos($href, 'mailto:') === 0 || stripos($href, 'javascript:') === 0) {
            continue;
        }
        $text = merge_whitespace($a->textContent);
        // skip probably-image-only links
        if (preg_replace('/【\@([^】]+)】/u', '', $text) === '') continue;
        if (strlen($href) > 0 && $href[0] === '#') {
            replace_node_with_text_dom($a, $text);
            continue;
        }
        // Resolve relative URLs
        if (parse_url($href, PHP_URL_SCHEME) === null) {
            // naive resolver
            $href = rtrim($cur_url, '/') . 'simple_browser_tool.php/' . ltrim($href, '/');
        }
        $domain = get_domain($href);
        if (!$domain) {
            replace_node_with_text_dom($a, $text);
            continue;
        }
        $href = arxiv_to_ar5iv($href);
        $link_id = $urls_rev[$href] ?? null;
        if ($link_id === null) {
            $link_id = (string)count($urls);
            $urls[$link_id] = $href;
            $urls_rev[$href] = $link_id;
        }
        if ($domain === $cur_domain) {
            $replacement = "【{$link_id}†{$text}】";
        } else {
            $replacement = "【{$link_id}†{$text}†{$domain}】";
        }
        replace_node_with_text_dom($a, $replacement);
    }
    return $urls;
}

function replace_images_dom(DOMDocument $doc): void {
    $xpath = new DOMXPath($doc);
    $cnt = 0;
    foreach ($xpath->query('//img') as $img) {
        if (!($img instanceof \DOMElement)) continue;
        $alt = $img->getAttribute('alt');
        $title = $img->getAttribute('title');
        $name = $alt !== '' ? $alt : ($title !== '' ? $title : null);
        $replacement = $name !== null ? "[Image {$cnt}: {$name}]" : "[Image {$cnt}]";
        replace_node_with_text_dom($img, $replacement);
        $cnt += 1;
    }
}

function remove_math_dom(DOMDocument $doc): void {
    $xpath = new DOMXPath($doc);
    foreach ($xpath->query('//*[local-name()="math"]') as $math) {
        if (!($math instanceof \DOMElement)) continue;
        $parent = $math->parentNode;
        if ($parent) $parent->removeChild($math);
    }
}

function html_to_text_basic(string $html): string {
    // Lightweight HTML to text: remove scripts/styles, strip tags, collapse whitespace
    $html = preg_replace(HTML_SUP_RE, '^$2', $html) ?? $html;
    $html = preg_replace(HTML_SUB_RE, '_$2', $html) ?? $html;
    $html = preg_replace(HTML_TAGS_SEQ_RE, ' $1', $html) ?? $html;
    // remove script/style contents
    $html = preg_replace('#<script[^>]*>.*?</script>#is', '', $html) ?? $html;
    $html = preg_replace('#<style[^>]*>.*?</style>#is', '', $html) ?? $html;
    $text = trim(html_entity_decode(strip_tags($html)));
    return $text;
}

function process_html(string $html, string $url, ?string $title, bool $display_urls = false): PageContents {
    $html = remove_unicode_smp($html);
    // replace certain special chars similar to Python _replace_special_chars
    $html = str_replace(["【", "】", "◼", "\u{200B}"], ["〖", "〗", "◾", ""], $html);

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $loaded = $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    libxml_clear_errors();
    if (!$loaded) {
        // fallback to plaintext
        $text = html_to_text_basic($html);
        return new PageContents($url, $text, $title ?? ($url !== '' ? get_domain($url) : ''), []);
    }

    // Title
    $final_title = '';
    $titles = $doc->getElementsByTagName('title');
    if ($title !== null && $title !== '') {
        $final_title = $title;
    } elseif ($titles->length > 0) {
        $final_title = $titles->item(0)->textContent ?? '';
    } elseif ($url !== '' && ($domain = get_domain($url))) {
        $final_title = $domain;
    }

    $urls = clean_links($doc, $url);
    replace_images_dom($doc);
    remove_math_dom($doc);

    $html_out = $doc->saveHTML() ?: '';
    $text = html_to_text_basic($html_out);
    // Move anchors through whitespace
    $text = preg_replace_callback(WHITESPACE_ANCHOR_RE, function($m){ return ($m[2] ?? '') . ($m[1] ?? ''); }, $text) ?? $text;
    $text = preg_replace(EMPTY_LINE_RE, '', $text) ?? $text;
    $text = preg_replace(EXTRA_NEWLINE_RE, "\n\n", $text) ?? $text;

    $top = [];
    if ($display_urls) {
        $top[] = "\nURL: {$url}\n";
    }

    return new PageContents($url, simple_browser_tool . phpimplode('', $top) . $text, $final_title, $urls);
}

// -----------------------------
// State
// -----------------------------
class SimpleBrowserState
{
    /** @var array<string,PageContents> */
    public array $pages = [];
    /** @var list<string> */
    public array $page_stack = [];

    public function current_cursor(): int { return count($this->page_stack) - 1; }

    public function add_page(PageContents $page): void {
        $this->pages[$page->url] = $page;
        $this->page_stack[] = $page->url;
    }

    public function get_page(int $cursor = -1): PageContents {
        if ($this->current_cursor() < 0) throw new ToolUsageError('No pages to access!');
        if ($cursor === -1 || $cursor === $this->current_cursor()) {
            return $this->pages[$this->page_stack[count($this->page_stack)-1]];
        }
        if (!isset($this->page_stack[$cursor])) {
            throw new ToolUsageError("Cursor `{$cursor}` is out of range. Available cursor indices: [0 - " . $this->current_cursor() . "].");
        }
        $page_url = $this->page_stack[$cursor];
        return $this->pages[$page_url];
    }

    public function get_page_by_url(string $url): ?PageContents {
        return $this->pages[$url] ?? null;
    }

    public function pop_page_stack(): void {
        if ($this->current_cursor() < 0) throw new \RuntimeException('No page to pop!');
        array_pop($this->page_stack);
    }
}

// -----------------------------
// Backend (SearxNG only)
// -----------------------------
interface Backend {
    public function getSource(): string;
    public function search(string $query, int $topn): PageContents;
    public function fetch(string $url): PageContents;
}

class SearxBackend implements Backend
{
    private string $source;
    private ?string $searx_url;
    private string $user_agent;

    public function __construct(string $source, ?string $searx_url = null, string $user_agent = 'Mozilla/5.0 (compatible; gpt-oss-simple-browser/1.0)')
    {
        $this->source = $source;
        $this->searx_url = $searx_url ?? getenv('SEARXNG_URL') ?: 'http://server:8088';
        $this->searx_url = rtrim($this->searx_url, '/');
        $this->user_agent = $user_agent;
    }

    public function getSource(): string { return $this->source; }

    private function http_get_json(string $url, array $params): array {
        $qs = http_build_query($params);
        $full = $url . (str_contains($url, '?') ? '&' : '?') . $qs;
        $ch = curl_init($full);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['User-Agent: ' . $this->user_agent],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false || $status !== 200) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new BackendError("Searx error {$status}: " . ($body !== false ? maybe_truncate((string)$body, 500) : $err));
        }
        curl_close($ch);
        $data = json_decode($body, true);
        if (!is_array($data)) throw new BackendError('Invalid JSON from Searx');
        return $data;
    }

    public function search(string $query, int $topn): PageContents
    {
        $url = $this->searx_url . '/search';
        $params = [
            'q' => $query,
            'format' => 'json',
            'categories' => 'general',
        ];
        $data = $this->http_get_json($url, $params);
        $results = array_slice($data['results'] ?? [], 0, $topn);
        $items = [];
        foreach ($results as $r) {
            if (!isset($r['url'])) continue;
            $title = ($r['title'] ?? $r['url']);
            $items[] = [
                'title' => $title,
                'url' => $r['url'],
                'summary' => ($r['content'] ?? ''),
            ];
        }
        // Build a simple HTML page like Python does
        $lis = '';
        foreach ($items as $it) {
            $titleEsc = htmlspecialchars($it['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $urlEsc = htmlspecialchars($it['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $summaryEsc = htmlspecialchars($it['summary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $lis .= "<li><a href='{$urlEsc}'>{$titleEsc}</a> {$summaryEsc}</li>";
        }
        $html = "<html><body><h1>Search Results</h1><ul>{$lis}</ul></body></html>";
        return process_html($html, '', $query, true);
    }

    public function fetch(string $url): PageContents
    {
        $is_view_source = str_starts_with($url, VIEW_SOURCE_PREFIX);
        if ($is_view_source) {
            $url = substr($url, strlen(VIEW_SOURCE_PREFIX));
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['User-Agent: ' . $this->user_agent],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false || $status !== 200) {
            $err = curl_error($ch);
            $excerpt = is_string($body) ? maybe_truncate($body, 500) : $err;
            curl_close($ch);
            throw new BackendError("Fetch error {$status} for {$url}: {$excerpt}");
        }
        curl_close($ch);
        $html = (string)$body;
        return process_html($html, $url, $url, true);
    }
}

// -----------------------------
// Tool
// -----------------------------
class SimpleBrowserTool
{
    private Backend $backend;
    private SimpleBrowserState $tool_state;
    private string $encoding_name;
    private int $max_search_results;
    private int $view_tokens;
    private string $name;

    public function __construct(Backend $backend, string $encoding_name = ENC_NAME, int $max_search_results = 20, ?array $tool_state = null, int $view_tokens = 1024, string $name = 'browser')
    {
        if ($name !== 'browser') throw new \InvalidArgumentException('name must be browser');
        $this->backend = $backend;
        $this->tool_state = new SimpleBrowserState();
        if ($tool_state !== null) {
            // Optional: restore state if provided in same structure
            if (isset($tool_state['tool_state']['pages']) && isset($tool_state['tool_state']['page_stack'])) {
                foreach ($tool_state['tool_state']['pages'] as $url => $p) {
                    $this->tool_state->pages[$url] = new PageContents(
                        $p['url'], $p['text'], $p['title'], $p['urls'] ?? [], null, $p['error_message'] ?? null
                    );
                }
                $this->tool_state->page_stack = $tool_state['tool_state']['page_stack'];
            }
        }
        $this->encoding_name = $encoding_name;
        $this->max_search_results = $max_search_results;
        $this->view_tokens = $view_tokens;
        $this->name = $name;
    }

    public function get_tool_state(): array {
        // Minimal dump compatible with Python shape
        return [
            'tool_state' => [
                'pages' => array_map(function(PageContents $p){
                    return [
                        'url' => $p->url,
                        'text' => $p->text,
                        'title' => $p->title,
                        'urls' => $p->urls,
                        'snippets' => null,
                        'error_message' => $p->error_message,
                    ];
                }, $this->tool_state->pages),
                'page_stack' => $this->tool_state->page_stack,
            ]
        ];
    }

    public static function get_tool_name(): string { return 'browser'; }
    public function name(): string { return self::get_tool_name(); }

    // For parity, we expose a textual instruction string similar to Python's tool_config.description
    public function instruction(): string {
        return "Tool for browsing.\nThe `cursor` appears in brackets before each browsing display: `[{cursor}]`.\nCite information from the tool using the following format:\n`【{cursor}†L{line_start}(-L{line_end})?】`, for example: `` or ``.\nDo not quote more than 10 words directly from the tool output.\nsources=" . $this->backend->getSource();
    }

    private function render_browsing_display(int $tether_id, string $result, ?string $summary = null): string {
        $to_return = '';
        if ($summary) $to_return .= $summary;
        $to_return .= $result;
        return "[{$tether_id}] " . $to_return;
    }

    private function make_response(PageContents $page, int $cursor, string $body, string $scrollbar): array {
        $domain = urldecode($page->url);
        $header = $page->title;
        if ($domain !== '') $header .= " ({$domain})";
        $header .= "\n**{$scrollbar}**\n\n";
        $text = $this->render_browsing_display($cursor, $body, $header);
        return [
            'text' => $text,
            'metadata' => [
                'url' => $page->url,
                'title' => $page->title,
            ],
        ];
    }

    public function show_page(int $loc = 0, int $num_lines = -1): array {
        $page = $this->tool_state->get_page();
        $cursor = $this->tool_state->current_cursor();
        $lines = wrap_lines($page->text);
        $total_lines = count($lines);
        if ($loc >= $total_lines) {
            $max = $total_lines - 1;
            throw new ToolUsageError("Invalid location parameter: `{$loc}`. Cannot exceed page maximum of {$max}.");
        }
        $end_loc = get_end_loc($loc, $num_lines, $total_lines, $lines, $this->view_tokens, $this->encoding_name);
        $lines_to_show = array_slice($lines, $loc, $end_loc - $loc);
        $body = join_lines($lines_to_show, true, $loc);
        $scrollbar = "viewing lines [{$loc} - " . ($end_loc - 1) . "] of " . ($total_lines - 1);
        return $this->make_response($page, $cursor, $body, $scrollbar);
    }

    public function show_page_safely(int $loc = 0, int $num_lines = -1): array {
        try { return $this->show_page($loc, $num_lines); }
        catch (ToolUsageError $e) { $this->tool_state->pop_page_stack(); throw $e; }
    }

    private function open_url(string $url, bool $direct_url_open): PageContents {
        if (!$direct_url_open) {
            $page = $this->tool_state->get_page_by_url($url);
            if ($page !== null) return $page;
        }
        try {
            $page = $this->backend->fetch($url);
            return $page;
        } catch (\Throwable $e) {
            $msg = maybe_truncate($e->getMessage());
            throw new BackendError("Error fetching URL `" . maybe_truncate($url) . "`: {$msg}");
        }
    }

    // Public API-like methods
    public function search(string $query, int $topn = 10, int $top_n = 10, ?string $source = null): array {
        unset($topn, $top_n, $source);
        try {
            $search_page = $this->backend->search($query, $this->max_search_results);
        } catch (\Throwable $e) {
            $msg = maybe_truncate($e->getMessage());
            throw new BackendError("Error during search for `{$query}`: {$msg}");
        }
        $this->tool_state->add_page($search_page);
        return $this->show_page_safely(0);
    }

    public function open(int|string $id = -1, int $cursor = -1, int $loc = -1, int $num_lines = -1, bool $view_source = false, ?string $source = null): array {
        unset($source);
        $curr_page = null; $stay_on_current_page = false; $direct_url_open = false; $snippet = null; $url = '';
        if (is_string($id)) {
            $url = $id; $direct_url_open = true; $snippet = null;
        } else {
            $curr_page = $this->tool_state->get_page($cursor);
            if ($id >= 0) {
                if (!array_key_exists((string)$id, $curr_page->urls)) throw new ToolUsageError("Invalid link id `{$id}`.");
                $url = $curr_page->urls[(string)$id];
                $snippet = $curr_page->snippets[(string)$id] ?? null;
            } else {
                if (!$view_source) $stay_on_current_page = true;
                $url = $curr_page->url; $snippet = null;
            }
        }
        if ($view_source) { $url = VIEW_SOURCE_PREFIX . $url; $snippet = null; }
        if ($stay_on_current_page) { $new_page = $curr_page; }
        else { $new_page = $this->open_url($url, $direct_url_open); }
        $this->tool_state->add_page($new_page);
        if ($loc < 0) {
            if ($snippet instanceof Extract && $snippet->line_idx !== null) {
                $loc = $snippet->line_idx; if ($loc > 4) $loc -= 4;
            } else { $loc = 0; }
        }
        return $this->show_page_safely($loc, $num_lines);
    }

    public function find(string $pattern, int $cursor = -1): array {
        $page = $this->tool_state->get_page($cursor);
        if ($page->snippets !== null) throw new ToolUsageError('Cannot run `find` on search results page or find results page');
        $pc = $this->run_find_in_page(mb_strtolower((string)$pattern, 'UTF-8'), $page);
        $this->tool_state->add_page($pc);
        return $this->show_page_safely(0);
    }

    private function run_find_in_page(string $pattern, PageContents $page, int $max_results = 50, int $num_show_lines = 4): PageContents {
        $lines = wrap_lines($page->text);
        $txt = join_lines($lines, false);
        $without_links = strip_links($txt);
        $lines = explode("\n", $without_links);
        $result_chunks = [];
        $snippets = [];
        $line_idx = 0; $match_idx = 0;
        while ($line_idx < count($lines)) {
            $line = $lines[$line_idx];
            if (mb_strpos($line, $pattern, 0, 'UTF-8') === false) { $line_idx += 1; continue; }
            $snippet_text = implode("\n", array_slice($lines, $line_idx, $num_show_lines));
            $link_title = sprintf(FIND_PAGE_LINK_FORMAT, (string)$match_idx, 'match at L' . $line_idx);
            $result_chunks[] = $link_title . "\n" . $snippet_text;
            $snippets[(string)$match_idx] = new Extract($page->url, $snippet_text, '#' . $match_idx, $line_idx);
            if (count($result_chunks) === $max_results) break;
            $match_idx += 1; $line_idx += $num_show_lines;
        }
        $urls = [];
        foreach ($result_chunks as $_) { $urls[] = $page->url; }
        $display_text = $result_chunks ? implode("\n\n", $result_chunks) : "No `find` results for pattern: `{$pattern}`";
        return new PageContents(
            $page->url . '/find?pattern=' . rawurlencode($pattern),
            $display_text,
            'Find results for text: `' . $pattern . '` in `' . $page->title . '`',
            array_combine(array_map('strval', array_keys($urls)), $urls) ?: [],
            $snippets
        );
    }

    // Normalize citations like Python normalize_citations
    public function normalize_citations(string $old_content, bool $hide_partial_citations = false): array {
        $has_partial_citations = preg_match(PARTIAL_FINAL_LINK_PATTERN, $old_content) === 1;
        if ($hide_partial_citations && $has_partial_citations) {
            $old_content = preg_replace(PARTIAL_FINAL_LINK_PATTERN, '', $old_content) ?? $old_content;
        }
        $matches = [];
        if (preg_match_all('/【(?P<cursor>\d+)†(?P<content>[^†】]+)(?:†[^†】]+)?】/u', $old_content, $mm, PREG_OFFSET_CAPTURE)) {
            for ($i = 0; $i < count($mm['cursor']); $i++) {
                $matches[] = [
                    'cursor' => $mm['cursor'][$i][0],
                    'content' => $mm['content'][$i][0],
                    'start' => $mm[0][$i][1],
                    'end' => $mm[0][$i][1] + strlen($mm[0][$i][0]),
                ];
            }
        }
        $cursor_to_url = [];
        foreach ($this->tool_state->page_stack as $idx => $url) { $cursor_to_url[(string)$idx] = $url; }
        $new_content = '';
        $last_idx = 0;
        $annotations = [];
        foreach ($matches as $m) {
            $cursor = $m['cursor'];
            $url = $cursor_to_url[$cursor] ?? null;
            $orig_start = $m['start'];
            $orig_end = $m['end'];
            $new_content .= substr($old_content, $last_idx, $orig_start - $last_idx);
            if ($url) {
                $domain = $this->extract_domain($url);
                $replacement = " ([{$domain}]({$url})) ";
                $start_index = strlen($new_content);
                $end_index = $start_index + strlen($replacement);
                $annotations[] = [
                    'start_index' => $start_index,
                    'end_index' => $end_index,
                    'title' => $domain,
                    'url' => $url,
                    'type' => 'url_citation',
                ];
                $new_content .= $replacement;
            } else {
                $replacement = substr($old_content, $orig_start, $orig_end - $orig_start);
                $new_content .= $replacement;
            }
            $last_idx = $orig_end;
        }
        $new_content .= substr($old_content, $last_idx);
        return [$new_content, $annotations, $has_partial_citations];
    }

    private function extract_domain(string $url): string {
        try { return urldecode($url);
        } catch (\Throwable $e) { return $url; }
    }
}

?>
