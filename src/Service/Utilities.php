<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\DTO\Extract;
use App\Service\DTO\PageContents;

final readonly class Utilities
{
    private const FIND_PAGE_LINK_FORMAT = '# 【%s†%s】';

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
     * Conservative line wrapping that preserves empty lines and does not drop whitespace.
     */
    public static function wrapLines(string $text, int $width = 80): array
    {
        $result = [];
        foreach (explode("\n", $text) as $line) {
            if ('' === $line) {
                $result[] = '';
                continue;
            }
            // wordwrap preserves spaces when cut=false
            $wrapped = wordwrap($line, $width);
            foreach (explode("\n", $wrapped) as $w) {
                $result[] = $w;
            }
        }

        return $result;
    }

    public static function stripLinks(string $text): string
    {
        $partialInitial = '/^[^【】]*】/u';
        $partialFinal = '/【\d*(?:†(?P<content>[^†】]*)(?:†[^†】]*)?)?$/u';
        $full = '/【\d+†(?P<content>[^†】]+)(?:†[^†】]+)?】/u';

        $text = (string) preg_replace($partialInitial, '', $text);
        $text = (string) preg_replace_callback($partialFinal, fn ($m) => $m['content'] ?? '', $text);
        $text = (string) preg_replace_callback($full, fn ($m) => $m['content'] ?? '', $text);

        return $text;
    }

    /**
     * Approximate token-based window using character counts.
     */
    public static function getEndLoc(int $loc, int $numLines, int $totalLines, array $lines, int $viewTokens, string $encodingName): int
    {
        if ($numLines <= 0) {
            $txt = self::joinLines(\array_slice($lines, $loc), true, $loc);
            if (mb_strlen($txt) > $viewTokens) {
                $count = 0;
                $numLines = 0;
                foreach ($lines as $idx => $line) {
                    if ($idx < $loc) {
                        continue;
                    }
                    $count += mb_strlen($line) + 1; // newline
                    ++$numLines;
                    if ($count >= $viewTokens) {
                        break;
                    }
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

        return \sprintf('[%d] %s', $cursor, $result);
    }

    /**
     * Build a find results PageContents by scanning the page text for a pattern.
     */
    public static function runFindInPage(string $pattern, PageContents $page, int $maxResults = 50, int $numShowLines = 4): PageContents
    {
        $lines = self::wrapLines($page->text, 80);
        $txt = self::joinLines($lines, false);
        $withoutLinks = self::stripLinks($txt);
        $lines = explode("\n", $withoutLinks);

        $resultChunks = [];
        $snippets = [];
        $lineIdx = 0;
        $matchIdx = 0;
        while ($lineIdx < \count($lines)) {
            $line = $lines[$lineIdx];
            if (!str_contains(mb_strtolower($line), $pattern)) {
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

        $displayText = !empty($resultChunks) ? implode("\n\n", $resultChunks) : \sprintf('No `find` results for pattern: `%s`', $pattern);

        return new PageContents(
            url: $page->url.'/find?pattern='.rawurlencode($pattern),
            text: $displayText,
            title: \sprintf('Find results for text: `%s` in `%s`', $pattern, $page->title),
            urls: $urlsMap,
            snippets: $snippets,
        );
    }
}
