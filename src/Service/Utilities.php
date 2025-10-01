<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\DTO\Extract;
use App\Service\DTO\PageContents;
use Yethee\Tiktoken\EncoderProvider;

final readonly class Utilities
{
    private const FIND_PAGE_LINK_FORMAT = '# 【%s†%s】';
    private const FALLBACK_CHARS_PER_TOKEN = 3.37;
    // Tighter PCRE limits for regex-based find to avoid runaway backtracking.
    private const FIND_REGEX_BACKTRACK_LIMIT = 100000;
    private const FIND_REGEX_RECURSION_LIMIT = 1000;

    public static function maybeTruncate(string $text, int $numChars = 1024): string
    {
        if (mb_strlen($text) > $numChars) {
            return mb_substr($text, 0, $numChars - 3).'...';
        }

        return $text;
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
        $text = (string) preg_replace_callback($partialFinal, fn ($m) => $m['content'], $text);
        $text = (string) preg_replace_callback($full, fn ($m) => $m['content'], $text);

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

    public static function makeDisplay(PageContents $page, int $cursor, string $body, string $scrollbar): string
    {
        $domain = self::maybeTruncate(urldecode($page->url), 256);
        $header = $page->title;
        if ('' !== $domain) {
            $header .= \sprintf(' (%s)', $domain);
        }
        $header .= \sprintf("\n**%s**\n\n", $scrollbar);

        $result = $header;
        $result .= $body;

        return \sprintf('[CURSOR:#%d] %s', $cursor, $result);
    }

    /**
     * Build a find results PageContents by scanning the page text for either an exact pattern or a regex match.
     */
    public static function runFindInPage(
        PageContents $page,
        ?string $pattern = null,
        ?string $regex = null,
        int $maxResults = 50,
        int $numShowLines = 4,
    ): PageContents {
        $lines = self::wrapLines($page->text);
        $txt = self::joinLines($lines);
        $withoutLinks = self::stripLinks($txt);
        $lines = explode("\n", $withoutLinks);

        $isRegexSearch = null !== $regex;
        $query = $isRegexSearch ? (string) $regex : (string) $pattern;

        $regexPattern = $isRegexSearch ? $query : null;
        $needle = $isRegexSearch ? null : mb_strtolower($query);
        $regexError = null;

        $resultChunks = [];
        $snippets = [];
        $lineIdx = 0;
        $matchIdx = 0;
        while ($lineIdx < \count($lines)) {
            $line = $lines[$lineIdx];
            $matched = false;
            if (null !== $regexPattern) {
                $matched = self::regexMatches($regexPattern, $line, $regexError);
                if (null !== $regexError) {
                    break;
                }
            } elseif (null !== $needle) {
                $matched = str_contains(mb_strtolower($line), $needle);
            }

            if (!$matched) {
                ++$lineIdx;
                continue;
            }
            $snippet = implode("\n", \array_slice($lines, $lineIdx, $numShowLines));
            $linkTitle = \sprintf(self::FIND_PAGE_LINK_FORMAT, (string) $matchIdx, \sprintf('match at L%d', $lineIdx));
            $resultChunks[] = $linkTitle."\n".$snippet;
            $snippets[(string) $matchIdx] = new Extract($page->url, $snippet, \sprintf('#%d', $matchIdx), $lineIdx);
            if (\count($resultChunks) === $maxResults) {
                break;
            }
            ++$matchIdx;
            $lineIdx += $numShowLines;
        }

        $urlsMap = [];
        for ($i = 0; $i < \count($resultChunks); ++$i) {
            $urlsMap[(string) $i] = $page->url;
        }

        if (null !== $regexError) {
            $displayText = \sprintf('Regex error for regex `%s`: %s', $query, $regexError);
            $resultChunks = [];
            $urlsMap = [];
            $snippets = [];
        } elseif (!empty($resultChunks)) {
            $displayText = implode("\n\n", $resultChunks);
        } else {
            $displayText = \sprintf('No `find` results for %s: `%s`', $isRegexSearch ? 'regex' : 'pattern', $query);
        }

        return new PageContents(
            url: $page->url.'/find?'.($isRegexSearch ? 'regex=' : 'pattern=').rawurlencode($query),
            text: $displayText,
            title: \sprintf('Find results for %s: `%s` in `%s`', $isRegexSearch ? 'regex' : 'text', $query, $page->title),
            urls: $urlsMap,
            snippets: $snippets,
        );
    }

    private static function regexMatches(string $pattern, string $subject, ?string &$error = null): bool
    {
        $error = null;
        $result = self::withPcreLimits(static function () use ($pattern, $subject, &$error) {
            $match = @preg_match($pattern, $subject);
            if (false === $match) {
                $error = self::describePregError(preg_last_error());

                return false;
            }

            return 1 === $match;
        });

        return (bool) $result;
    }

    private static function withPcreLimits(callable $callback): mixed
    {
        $backtrack = \ini_get('pcre.backtrack_limit');
        $recursion = \ini_get('pcre.recursion_limit');

        try {
            ini_set('pcre.backtrack_limit', (string) self::FIND_REGEX_BACKTRACK_LIMIT);
            ini_set('pcre.recursion_limit', (string) self::FIND_REGEX_RECURSION_LIMIT);

            return $callback();
        } finally {
            if (false !== $backtrack) {
                ini_set('pcre.backtrack_limit', (string) $backtrack);
            } else {
                ini_restore('pcre.backtrack_limit');
            }

            if (false !== $recursion) {
                ini_set('pcre.recursion_limit', (string) $recursion);
            } else {
                ini_restore('pcre.recursion_limit');
            }
        }
    }

    private static function describePregError(int $code): string
    {
        return match ($code) {
            \PREG_BACKTRACK_LIMIT_ERROR => 'PCRE backtrack limit reached',
            \PREG_RECURSION_LIMIT_ERROR => 'PCRE recursion limit reached',
            \PREG_BAD_UTF8_ERROR, \PREG_BAD_UTF8_OFFSET_ERROR => 'Invalid UTF-8 data in subject',
            \PREG_INTERNAL_ERROR => 'Invalid regex pattern or internal PCRE error',
            \PREG_JIT_STACKLIMIT_ERROR => 'PCRE JIT stack limit reached',
            default => 'Unknown PCRE error (code '.$code.')',
        };
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
}
