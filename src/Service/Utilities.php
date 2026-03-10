<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\DTO\PageContents;
use Yethee\Tiktoken\EncoderProvider;

final readonly class Utilities
{
    private const FALLBACK_CHARS_PER_TOKEN = 3.37;

    public static function canonicalizeUrl(string $url): string
    {
        $trimmed = trim($url);
        if ('' === $trimmed) {
            return '';
        }

        $decoded = html_entity_decode($trimmed, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $decoded = trim($decoded);

        if (!str_contains($decoded, '://')) {
            $decoded = 'https://'.$decoded;
        }

        $parts = parse_url($decoded);
        if (false === $parts || !isset($parts['host']) || '' === (string) $parts['host']) {
            return $decoded;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) && '' !== $parts['query'] ? '?'.$parts['query'] : '';

        if ('' === $path) {
            $path = '/';
        } else {
            $path = self::normalizeUrlPath($path);
        }

        return $scheme.'://'.$host.$port.$path.$query;
    }

    public static function maybeTruncate(string $text, int $numChars = 1024): string
    {
        if (mb_strlen($text) > $numChars) {
            return mb_substr($text, 0, $numChars - 3).'...';
        }

        return $text;
    }

    public static function getDomain(string $url): string
    {
        if ('' === $url) {
            return '';
        }
        if (!str_contains($url, 'http')) {
            $url = 'http://'.$url;
        }
        $parts = parse_url($url);

        return (string) ($parts['host'] ?? '');
    }

    public static function normalizeSummary(string $summary): string
    {
        $summary = trim($summary);
        if ('' === $summary) {
            return '';
        }

        $summary = html_entity_decode(strip_tags($summary), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $summary = preg_replace('/\s+/u', ' ', $summary) ?? $summary;

        return trim($summary);
    }

    public static function getEnv(string $key, string $default): string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
        if (false === $value) {
            return $default;
        }

        $str = trim((string) $value);

        return '' !== $str ? $str : $default;
    }

    public static function ensureUtf8(string $html): string
    {
        if (!mb_detect_encoding($html, 'UTF-8', true)) {
            $html = mb_convert_encoding($html, 'UTF-8');
        }

        return $html;
    }

    /**
     * @param string[] $lines
     */
    public static function joinLines(array $lines, bool $addLineNumbers = false, int $offset = 0): string
    {
        if ($addLineNumbers) {
            $out = [];
            foreach ($lines as $i => $line) {
                $out[] = \sprintf('L%d: %s', $i + $offset, $line);
            }

            return implode("\n", $out);
        }

        return implode("\n", $lines);
    }

    /**
     * Python textwrap-like wrapping to mirror SimpleBrowserTool.wrap_lines:
     * replace_whitespace=False, drop_whitespace=False, break_long_words=True, break_on_hyphens=True.
     * Preserves empty lines.
     *
     * @return string[]
     */
    public static function wrapLines(string $text, int $width = 80): array
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if ('' === $line) {
                $out[] = '';
                continue;
            }

            $tokens = preg_split('/(\s+)/u', $line, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);
            if (!\is_array($tokens)) {
                $out[] = $line;
                continue;
            }

            $current = '';
            $i = 0;
            $n = \count($tokens);
            while ($i < $n) {
                $t = (string) $tokens[$i];
                $candidate = $current.$t;
                if (mb_strlen($candidate) <= $width) {
                    $current = $candidate;
                    ++$i;
                    continue;
                }

                if ('' !== $current) {
                    // Try to split the token at a hyphen so that the left part fits into the remaining space
                    $remaining = $width - mb_strlen($current);
                    if ($remaining > 0) {
                        $sliceFit = mb_substr($t, 0, $remaining);
                        $hpos = self::mb_strrpos($sliceFit, '-');
                        if (false !== $hpos) {
                            $head = mb_substr($t, 0, $hpos + 1);
                            $tail = mb_substr($t, $hpos + 1);
                            $out[] = $current.$head;
                            $current = '';
                            if ('' !== $tail) {
                                $tokens[$i] = $tail;
                            } else {
                                ++$i;
                            }
                            continue;
                        }
                    }
                    // Otherwise, flush the current line and re-evaluate this token on a new line
                    $out[] = $current;
                    $current = '';
                    continue;
                }

                // current is empty and token itself exceeds width
                if (1 === preg_match('/^\s+$/u', $t)) {
                    // break long whitespace tokens across lines
                    $out[] = mb_substr($t, 0, $width);
                    $rest = mb_substr($t, $width);
                    if ('' !== $rest) {
                        $tokens[$i] = $rest;
                        $n = \count($tokens);
                    } else {
                        ++$i;
                    }
                    continue;
                }

                // Break long words; prefer hyphen breaks inside the width
                $slice = mb_substr($t, 0, $width);
                $pos = self::mb_strrpos($slice, '-');
                $breakAt = (false === $pos) ? $width : ($pos + 1);
                $out[] = mb_substr($t, 0, $breakAt);
                $rest = mb_substr($t, $breakAt);
                if ('' !== $rest) {
                    $tokens[$i] = $rest;
                } else {
                    ++$i;
                }
            }

            $out[] = $current;
        }

        return $out;
    }

    public static function stripLinks(string $text): string
    {
        $partialInitial = '/^[^【】]*】/u';
        $partialFinal = '/【\d*(?:†(?P<content>[^†】]*)(?:†[^†】]*)?)?$/u';
        $full = '/【\d+†(?P<content>[^†】]+)(?:†[^†】]+)?】/u';

        $text = (string) preg_replace($partialInitial, '', $text);
        $text = (string) preg_replace_callback($partialFinal, static fn ($m) => $m['content'], $text);
        $text = (string) preg_replace_callback($full, static fn ($m) => $m['content'], $text);

        return $text;
    }

    /**
     * Compute token-based end location using tiktoken-php to mirror Python behavior.
     *
     * @param string[] $lines
     */
    public static function getEndLoc(int $loc, int $numLines, int $totalLines, array $lines, int $viewTokens, string $encodingName): int
    {
        if ($numLines <= 0) {
            $txt = self::joinLines(\array_slice($lines, $loc), true, $loc);
            if (mb_strlen($txt) > $viewTokens) {
                try {
                    $provider = new EncoderProvider();
                    $encoder = $provider->get($encodingName);
                    // Tokenize the text (we can pass the whole string; provider caches vocab)
                    $tokens = $encoder->encode($txt);
                    if (\count($tokens) > $viewTokens) {
                        // Build char-offsets per token by decoding single-token chunks
                        $tok2idx = [0];
                        $sum = 0;
                        $limit = min(\count($tokens), $viewTokens + 1);
                        for ($i = 0; $i < $limit; ++$i) {
                            $piece = $encoder->decode([$tokens[$i]]);
                            $sum += mb_strlen($piece);
                            $tok2idx[] = $sum;
                        }
                        $endIdx = $tok2idx[$viewTokens] ?? $sum;
                        $sub = mb_substr($txt, 0, $endIdx);
                        $numLines = substr_count($sub, "\n") + 1; // round up
                    } else {
                        $numLines = $totalLines;
                    }
                } catch (\Throwable $e) {
                    // Fallback: estimate using a chars/token heuristic when we cannot load vocab data.
                    $numLines = self::estimateNumLinesFallback($txt, $totalLines, $viewTokens);
                }
            } else {
                $numLines = $totalLines;
            }
        }

        return min($loc + $numLines, $totalLines);
    }

    public static function makeDisplay(PageContents $page, string $body, string $scrollbar): string
    {
        $domain = self::getDomain($page->url);
        $header = $page->title;
        if ('' !== $domain) {
            $header .= \sprintf(' (%s)', $domain);
        }
        $canonicalUrl = self::canonicalizeUrl($page->url);
        $textStartsWithUrl = str_starts_with(ltrim($page->text), 'URL:');
        if ('' !== $canonicalUrl && !$textStartsWithUrl) {
            $header .= \sprintf("\nURL: %s", $canonicalUrl);
        }
        $header .= \sprintf("\n**%s**\n\n", $scrollbar);

        $result = $header.$body;

        $displayUrls = self::filterVisibleUrls($page->urls, $body, $page->url);
        $references = self::formatReferences($displayUrls);
        if ('' !== $references) {
            $result .= "\n\n".$references;
        }

        return $result;
    }

    /**
     * @param array<string,string> $urls
     *
     * @return array<string,string>
     */
    private static function filterVisibleUrls(array $urls, string $body, string $pageUrl): array
    {
        if (empty($urls)) {
            return [];
        }

        $matches = [];
        preg_match_all('/【(?P<id>\d+)†/u', $body, $matches);
        /** @var string[] $ids */
        $ids = array_map(static fn (string $id): string => $id, array_unique($matches['id']));

        if (!empty($ids)) {
            return array_intersect_key($urls, array_flip($ids));
        }

        if ('' === $pageUrl) {
            return $urls;
        }

        return [];
    }

    /**
     * @param array<string,string> $urls
     */
    private static function formatReferences(array $urls): string
    {
        if (empty($urls)) {
            return '';
        }

        $lines = ['References:'];
        foreach ($urls as $id => $url) {
            $canonical = self::canonicalizeUrl($url);
            $lines[] = \sprintf('[%s] %s', $id, '' !== $canonical ? $canonical : $url);
        }

        return implode("\n", $lines);
    }

    private static function estimateNumLinesFallback(string $text, int $totalLines, int $viewTokens): int
    {
        $maxChars = (int) ceil($viewTokens * self::FALLBACK_CHARS_PER_TOKEN);
        if (mb_strlen($text) <= $maxChars) {
            return $totalLines;
        }

        $sub = mb_substr($text, 0, $maxChars);

        return min(substr_count($sub, "\n") + 1, $totalLines);
    }

    private static function mb_strrpos(string $haystack, string $needle): int|false
    {
        $pos = false;
        $offset = 0;
        while (true) {
            $p = mb_strpos($haystack, $needle, $offset);
            if (false === $p) {
                break;
            }
            $pos = $p;
            $offset = $p + 1;
        }

        return $pos;
    }

    private static function normalizeUrlPath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }
}
