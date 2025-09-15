<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\DTO\PageContents;

final class PageProcessor
{
    private const HTML_SUP_RE = '/<sup( [^>]*)?>([\w\-]+)<\/sup>/u';
    private const HTML_SUB_RE = '/<sub( [^>]*)?>([\w\-]+)<\/sub>/u';

    /** Create a PageContents from HTML. */
    public static function processHtml(string $html, string $url, ?string $title, bool $displayUrls = false): PageContents
    {
        $html = self::removeUnicodeSmp($html);
        $html = self::replaceSpecialChars($html);
        $html = (string) preg_replace(self::HTML_SUP_RE, '^{\\2}', $html);
        $html = (string) preg_replace(self::HTML_SUB_RE, '_{\\2}', $html);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // Hint input encoding to libxml to avoid mojibake on UTF-8 content
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.Utilities::ensureUtf8($html));
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            // Fallback: strip tags if HTML is invalid
            $text = self::htmlToText($html);
            $finalTitle = $title ?? ('' !== $url ? self::getDomain($url) : '');

            return new PageContents(url: $url, text: ($displayUrls ? "\nURL: $url\n" : '').$text, title: $finalTitle, urls: []);
        }

        $xpath = new \DOMXPath($dom);
        $finalTitle = $title ?? self::extractTitle($xpath) ?? ('' !== $url ? self::getDomain($url) : '');

        $urls = self::cleanLinks($dom, $xpath, $url);
        self::replaceImages($dom, $xpath);
        self::removeMath($dom, $xpath);

        $cleanHtml = $dom->saveHTML() ?: '';
        $text = self::htmlToText($cleanHtml);
        // Preserve Markdown-like bullet prefix used for <li> items while normalizing spaces
        $text = (string) preg_replace('/^\s+\*\s/m', '<<BULLET>> ', $text);
        // Move anchors to the right through whitespace to avoid extra spaces
        // Ensure a single space follows an anchor token when immediately adjoining text
        $text = (string) preg_replace('/】(?=\S)/u', '】 ', $text);
        // Normalize non-breaking spaces to regular spaces
        $text = str_replace("\u{00A0}", ' ', $text);
        // Collapse runs of spaces/tabs but keep newlines intact
        $text = (string) preg_replace('/[^\S\r\n]+/', ' ', $text);
        // Trim trailing spaces at line ends
        $text = (string) preg_replace('/[ \t]+$/m', '', $text);
        // Restore bullet prefix
        $text = str_replace('<<BULLET>> ', '  * ', $text);
        // Ensure a blank line after headings like '# Title' for readability/parity
        $text = (string) preg_replace('/^(# .*?)\n(?!\n)/m', "$1\n\n", $text);
        $text = self::removeEmptyLines($text);
        $text = self::collapseExtraNewlines($text);

        $top = $displayUrls ? "\nURL: $url\n" : '';

        return new PageContents(url: $url, text: $top.$text, title: $finalTitle, urls: $urls);
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

    private static function extractTitle(\DOMXPath $xpath): ?string
    {
        $nodeList = $xpath->query('//title');
        if ($nodeList && $nodeList->length > 0) {
            return trim((string) $nodeList->item(0)?->textContent);
        }

        return null;
    }

    /** @return array<string,string> */
    private static function cleanLinks(\DOMDocument $dom, \DOMXPath $xpath, string $curUrl): array
    {
        $curDomain = self::getDomain($curUrl);
        $urls = [];
        $urlsRev = [];
        $nodes = $xpath->query('//a[@href]');
        if (!$nodes) {
            return [];
        }
        foreach ($nodes as $a) {
            if (!$a instanceof \DOMElement || !$a->hasAttribute('href')) {
                continue;
            }
            $href = (string) $a->getAttribute('href');
            if (str_starts_with($href, 'mailto:') || str_starts_with($href, 'javascript:')) {
                continue;
            }
            $text = self::mergeWhitespace($a->textContent ?? '');
            $text = str_replace('†', '‡', $text);
            if ('' === trim(preg_replace('/【\@([^】]+)】/u', '', $text) ?? '')) {
                continue; // likely an image-only link
            }
            if (str_starts_with($href, '#')) {
                self::replaceNodeWithText($dom, $a, $text);
                continue;
            }
            $link = self::urlJoin($curUrl, $href);
            $domain = self::getDomain($link);
            if ('' === $domain) {
                self::replaceNodeWithText($dom, $a, $text);
                continue;
            }
            $link = self::arxivToAr5iv($link);
            $linkId = $urlsRev[$link] ?? null;
            if (null === $linkId) {
                $linkId = (string) \count($urls);
                $urls[$linkId] = $link;
                $urlsRev[$link] = $linkId;
            }
            $replacement = $domain === $curDomain
                ? \sprintf('【%s†%s】', $linkId, $text)
                : \sprintf('【%s†%s†%s】', $linkId, $text, $domain);
            self::replaceNodeWithText($dom, $a, $replacement);
        }

        return $urls;
    }

    private static function replaceImages(\DOMDocument $dom, \DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//img');
        if (!$nodes) {
            return;
        }
        $i = 0;
        foreach ($nodes as $img) {
            if (!$img instanceof \DOMElement) {
                continue;
            }
            $name = $img->getAttribute('alt') ?: $img->getAttribute('title');
            $replacement = '' !== $name ? \sprintf('[Image %d: %s]', $i, $name) : \sprintf('[Image %d]', $i);
            self::replaceNodeWithText($dom, $img, $replacement);
            ++$i;
        }
    }

    private static function removeMath(\DOMDocument $dom, \DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//*[local-name()="math"]');
        if (!$nodes) {
            return;
        }
        foreach ($nodes as $n) {
            if ($n instanceof \DOMElement) {
                $n->parentNode?->removeChild($n);
            }
        }
    }

    private static function replaceNodeWithText(\DOMDocument $dom, \DOMNode $node, string $text): void
    {
        $textNode = $dom->createTextNode($text);
        $node->parentNode?->insertBefore($textNode, $node);
        $node->parentNode?->removeChild($node);
    }

    private static function htmlToText(string $html): string
    {
        // Basic normalization before stripping tags
        // - Convert headings to markdown-style prefixes like '#', '##', etc.
        $html = preg_replace('/<h1[^>]*>/i', '# ', $html) ?? $html;
        $html = preg_replace('/<h2[^>]*>/i', '## ', $html) ?? $html;
        $html = preg_replace('/<h3[^>]*>/i', '### ', $html) ?? $html;
        $html = preg_replace('/<h4[^>]*>/i', '#### ', $html) ?? $html;
        $html = preg_replace('/<h5[^>]*>/i', '##### ', $html) ?? $html;
        $html = preg_replace('/<h6[^>]*>/i', '###### ', $html) ?? $html;
        $html = preg_replace('/<\/h[1-6]\s*>/i', "\n\n", $html) ?? $html;

        // - Convert list items to bullets to match python html2text behavior
        $html = preg_replace('/<li[^>]*>/i', '  * ', $html) ?? $html;

        // Insert newlines for block-level boundaries
        $patterns = [
            '/<\s*br\s*\/?\s*>/i' => "\n",
            '/<\/(p|div|li|ul|ol|tr|table|blockquote|pre)\s*>/i' => "\n",
        ];
        $html = preg_replace(array_keys($patterns), array_values($patterns), $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return trim($text);
    }

    private static function removeEmptyLines(string $text): string
    {
        return (string) preg_replace('/^\s+$/m', '', $text);
    }

    private static function collapseExtraNewlines(string $text): string
    {
        return (string) preg_replace("/\n(\s*\n)+/", "\n\n", $text);
    }

    private static function moveAnchorsThroughWhitespace(string $text): string
    {
        // Swap sequences like "【...】\s+" to "\s+【...】"
        // This mirrors the Python behavior to prevent anchors from introducing extra spaces
        return (string) preg_replace('/(【[^】]+】)(\s+)/u', '$2$1', $text);
    }

    private static function replaceSpecialChars(string $text): string
    {
        $replacements = [
            '【' => '〖',
            '】' => '〗',
            '◼' => '◾',
            "\u{200B}" => '', // zero width space
        ];

        return strtr($text, $replacements);
    }

    private static function mergeWhitespace(string $text): string
    {
        $text = str_replace("\n", ' ', $text);
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    private static function arxivToAr5iv(string $url): string
    {
        return preg_replace('/arxiv\.org/i', 'ar5iv.org', $url) ?? $url;
    }

    private static function removeUnicodeSmp(string $text): string
    {
        // Remove code points above U+FFFF (SMP)
        return (string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);
    }

    private static function urlJoin(string $base, string $rel): string
    {
        // Absolute
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\\//', $rel)) {
            return $rel;
        }
        if ('' === $base) {
            return $rel;
        }
        $p = parse_url($base);
        $scheme = $p['scheme'] ?? 'http';
        $host = $p['host'] ?? '';
        $port = isset($p['port']) ? (':'.$p['port']) : '';
        $path = $p['path'] ?? '/';
        // Resolve relative path
        if (str_starts_with($rel, '/')) {
            $newPath = self::normalizePath($rel);
        } else {
            $baseDir = rtrim(substr($path, 0, strrpos($path.'/', '/') + 1), '/');
            $newPath = self::normalizePath($baseDir.'/'.$rel);
        }

        return $scheme.'://'.$host.$port.$newPath;
    }

    private static function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ('' === $seg || '.' === $seg) {
                continue;
            }
            if ('..' === $seg) {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }

        return '/'.implode('/', $parts);
    }
}
