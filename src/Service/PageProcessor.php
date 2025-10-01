<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\DTO\PageContents;
use Ineersa\Html2text\Config;
use Ineersa\Html2text\HTML2Markdown;

final class PageProcessor
{
    private const HTML_SUP_RE = '/<sup( [^>]*)?>([\w\-]+)<\/sup>/u';
    private const HTML_SUB_RE = '/<sub( [^>]*)?>([\w\-]+)<\/sub>/u';
    private const HTML_TAGS_SEQUENCE_RE = '/(?<=\w)((<[^>]*>)+)(?=\w)/u';

    /** Create a PageContents from HTML. */
    public static function processHtml(string $html, string $url, ?string $title, bool $displayUrls = false): PageContents
    {
        $html = self::removeUnicodeSmp($html);
        $html = self::replaceSpecialChars($html);
        $html = (string) preg_replace(self::HTML_SUP_RE, '^{\\2}', $html);
        $html = (string) preg_replace(self::HTML_SUB_RE, '_{\\2}', $html);
        $html = (string) preg_replace(self::HTML_TAGS_SEQUENCE_RE, ' \1', $html);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // Hint input encoding to libxml to avoid mojibake on UTF-8 content
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.Utilities::ensureUtf8($html));
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            // Fallback: strip tags if HTML is invalid
            $text = self::normalizeText(self::htmlToText($html));
            $finalTitle = $title ?? ('' !== $url ? self::getDomain($url) : '');

            return new PageContents(url: $url, text: ($displayUrls ? "\nURL: $url\n" : '').$text, title: $finalTitle, urls: []);
        }

        $xpath = new \DOMXPath($dom);
        $finalTitle = $title ?? self::extractTitle($xpath) ?? ('' !== $url ? self::getDomain($url) : '');

        $urls = self::cleanLinks($dom, $xpath, $url);
        self::replaceImages($dom, $xpath);
        self::removeMath($dom, $xpath);

        $cleanHtml = $dom->saveHTML() ?: '';
        $text = self::normalizeText(self::htmlToText($cleanHtml));

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
            if ('' === trim(preg_replace('/【@([^】]+)】/u', '', $text) ?? '')) {
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
        $config = new Config(
            unicodeSnob: true,
            bodyWidth: 0,
            ignoreAnchors: true,
            ignoreImages: true,
            ignoreEmphasis: true,
            ignoreTables: true,
        );

        try {
            $converter = new HTML2Markdown($config);
            $text = $converter->convert(Utilities::ensureUtf8($html));
        } catch (\Throwable) {
            $text = strip_tags($html);
        }

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

    private static function normalizeText(string $text): string
    {
        $text = (string) preg_replace('/(【@[^】]+】)(\s+)/u', '$2$1', $text);
        $text = self::removeEmptyLines($text);
        $text = self::collapseExtraNewlines($text);
        $text = self::normalizeTrailingWhitespace($text);
        $text = self::unescapeMarkdownArtifacts($text);

        return trim($text);
    }

    private static function normalizeTrailingWhitespace(string $text): string
    {
        $lines = explode("\n", $text);
        $lastIdx = \count($lines) - 1;
        for ($i = 0; $i <= $lastIdx; ++$i) {
            if (!preg_match('/[ \t]+$/', $lines[$i])) {
                continue;
            }

            $trimmed = rtrim($lines[$i], " \t");
            if (str_ends_with($trimmed, '>')) {
                continue;
            }

            $currentIndent = strspn($lines[$i], " \t");
            $nextIndent = null;
            for ($j = $i + 1; $j <= $lastIdx; ++$j) {
                if ('' === $lines[$j]) {
                    continue;
                }
                $nextIndent = strspn($lines[$j], " \t");
                break;
            }

            if (null !== $nextIndent && $nextIndent > $currentIndent) {
                continue;
            }

            $lines[$i] = rtrim($lines[$i], " \t");
        }

        return implode("\n", $lines);
    }

    private static function unescapeMarkdownArtifacts(string $text): string
    {
        // html2text escapes ordered-list markers (e.g. "1.") to keep Markdown literal; undo for parity
        return (string) preg_replace('/(?<=\d)\\\./', '.', $text);
    }

    private static function replaceSpecialChars(string $text): string
    {
        $replacements = [
            '【' => '〖',
            '】' => '〗',
            '◼' => '◾',
            "\u{200B}" => '', // zero width space
            "\u{00A0}" => ' ',
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
