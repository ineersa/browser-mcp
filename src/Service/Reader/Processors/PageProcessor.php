<?php

declare(strict_types=1);

namespace App\Service\Reader\Processors;

use App\Service\DTO\PageContents;
use App\Service\Utilities;
use Ineersa\Html2text\Config;
use Ineersa\Html2text\HTML2Markdown;

final class PageProcessor
{
    private const HTML_SUP_RE = '/<sup( [^>]*)?>([\w\-]+)<\/sup>/u';
    private const HTML_SUB_RE = '/<sub( [^>]*)?>([\w\-]+)<\/sub>/u';
    private const HTML_TAGS_SEQUENCE_RE = '/(?<=\w)((<[^>]*>)+)(?=\w)/u';
    private const MIN_CONTENT_TEXT_LEN = 120;
    private const POSITIVE_HINT_RE = '/\b(article|content|entry|main|post|markdown|doc|documentation|readme|page-body|prose)\b/i';
    private const NEGATIVE_HINT_RE = '/\b(nav|navbar|menu|footer|header|sidebar|aside|related|share|social|cookie|banner|popup|modal|breadcrumb|pagination|ads?|advert|promo|subscribe)\b/i';
    /** @var string[] */
    private const DEFAULT_NOISE_CLASS_TOKENS = ['codeblock-lines', 'linenos', 'line-numbers', 'gutter'];

    /** Create a PageContents from HTML. */
    /**
     * @param string[] $noiseClassTokens
     */
    public static function processHtml(string $html, string $url, ?string $title, bool $displayUrls = false, array $noiseClassTokens = []): PageContents
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
            $finalTitle = $title ?? ('' !== $url ? Utilities::getDomain($url) : '');

            return new PageContents(url: $url, text: ($displayUrls ? "\nURL: $url\n" : '').$text, title: $finalTitle, urls: []);
        }

        $xpath = new \DOMXPath($dom);
        $finalTitle = $title ?? self::extractTitle($xpath) ?? ('' !== $url ? Utilities::getDomain($url) : '');

        $root = self::pickContentRoot($xpath);
        if ($root instanceof \DOMElement) {
            self::removeBoilerplate($xpath, $root);
            self::removeKnownNoise($xpath, $root, $noiseClassTokens);
            self::absolutizeLinks($xpath, $root, $url);
        }

        self::replaceImages($dom, $xpath);
        self::removeMath($dom, $xpath);

        $cleanHtml = $root instanceof \DOMElement
            ? (string) ($dom->saveHTML($root) ?: '')
            : (string) ($dom->saveHTML() ?: '');
        $text = self::normalizeText(self::htmlToText($cleanHtml));

        $top = $displayUrls ? "\nURL: $url\n" : '';

        return new PageContents(url: $url, text: $top.$text, title: $finalTitle, urls: []);
    }

    private static function extractTitle(\DOMXPath $xpath): ?string
    {
        $nodeList = $xpath->query('//title');
        if ($nodeList && $nodeList->length > 0) {
            return trim((string) $nodeList->item(0)?->textContent);
        }

        return null;
    }

    private static function pickContentRoot(\DOMXPath $xpath): ?\DOMElement
    {
        $body = $xpath->query('//body')->item(0);
        if (!$body instanceof \DOMElement) {
            return null;
        }

        $candidates = $xpath->query('//main | //article | //section | //div');
        if (!$candidates) {
            return $body;
        }

        $bestNode = null;
        $bestScore = 0.0;
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof \DOMElement) {
                continue;
            }

            $score = self::scoreCandidate($xpath, $candidate);
            if ($score > $bestScore) {
                $bestNode = $candidate;
                $bestScore = $score;
            }
        }

        return $bestNode ?? $body;
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

    private static function removeBoilerplate(\DOMXPath $xpath, \DOMElement $root): void
    {
        $nodes = $xpath->query('.//*', $root);
        if (!$nodes) {
            return;
        }

        $toRemove = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            if (self::isBoilerplateNode($xpath, $node)) {
                $toRemove[] = $node;
            }
        }

        foreach ($toRemove as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * @param string[] $noiseClassTokens
     */
    private static function removeKnownNoise(\DOMXPath $xpath, \DOMElement $root, array $noiseClassTokens): void
    {
        $tokens = self::normalizeNoiseClassTokens($noiseClassTokens);
        if ([] === $tokens) {
            return;
        }

        $conditions = array_map(
            static fn (string $token): string => sprintf('contains(concat(" ", normalize-space(@class), " "), " %s ")', $token),
            $tokens,
        );
        $query = './/*[self::pre or self::div or self::span]['.implode(' or ', $conditions).']';
        $nodes = $xpath->query($query, $root);
        if (!$nodes) {
            return;
        }

        $toRemove = [];
        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement) {
                $toRemove[] = $node;
            }
        }

        foreach ($toRemove as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * @param string[] $noiseClassTokens
     *
     * @return string[]
     */
    private static function normalizeNoiseClassTokens(array $noiseClassTokens): array
    {
        $tokens = [];
        foreach (array_merge(self::DEFAULT_NOISE_CLASS_TOKENS, $noiseClassTokens) as $token) {
            if (!is_string($token)) {
                continue;
            }

            $normalized = strtolower(trim($token));
            if ('' === $normalized) {
                continue;
            }

            $tokens[] = $normalized;
        }

        return array_values(array_unique($tokens));
    }

    private static function isBoilerplateNode(\DOMXPath $xpath, \DOMElement $node): bool
    {
        $tagName = strtolower($node->tagName);
        if (in_array($tagName, ['script', 'style', 'noscript', 'template', 'iframe', 'svg', 'canvas', 'nav', 'footer', 'header', 'aside', 'form', 'button'], true)) {
            return true;
        }

        $className = strtolower(trim((string) $node->getAttribute('class')));
        $id = strtolower(trim((string) $node->getAttribute('id')));
        $role = strtolower(trim((string) $node->getAttribute('role')));
        $ariaLabel = strtolower(trim((string) $node->getAttribute('aria-label')));
        $hints = trim(implode(' ', array_filter([$className, $id, $role, $ariaLabel], static fn (string $value): bool => '' !== $value)));
        if ('' === $hints || 1 !== preg_match(self::NEGATIVE_HINT_RE, $hints)) {
            return false;
        }

        if (!in_array($tagName, ['div', 'section', 'ul', 'ol', 'li'], true)) {
            return false;
        }

        if (self::containsLikelyMainContent($xpath, $node)) {
            return false;
        }

        return true;
    }

    private static function containsLikelyMainContent(\DOMXPath $xpath, \DOMElement $node): bool
    {
        $mainNodes = $xpath->query('.//main | .//article', $node);
        if ($mainNodes && $mainNodes->length > 0) {
            return true;
        }

        $textLen = mb_strlen(self::mergeWhitespace($node->textContent ?? ''));
        if ($textLen < 600) {
            return false;
        }

        $paragraphs = $xpath->query('.//p', $node);
        $headings = $xpath->query('.//h1 | .//h2 | .//h3', $node);

        return ($paragraphs?->length ?? 0) >= 3 || ($headings?->length ?? 0) >= 1;
    }

    private static function scoreCandidate(\DOMXPath $xpath, \DOMElement $candidate): float
    {
        $text = self::mergeWhitespace($candidate->textContent ?? '');
        $textLen = mb_strlen($text);
        if ($textLen < self::MIN_CONTENT_TEXT_LEN) {
            return 0.0;
        }

        $tagName = strtolower($candidate->tagName);
        $score = (float) $textLen;
        if ('main' === $tagName || 'article' === $tagName) {
            $score += 450.0;
        }

        $className = strtolower(trim((string) $candidate->getAttribute('class')));
        $id = strtolower(trim((string) $candidate->getAttribute('id')));
        $hints = trim($className.' '.$id);
        if ('' !== $hints && 1 === preg_match(self::POSITIVE_HINT_RE, $hints)) {
            $score += 300.0;
        }
        if ('' !== $hints && 1 === preg_match(self::NEGATIVE_HINT_RE, $hints)) {
            $score -= 400.0;
        }

        $links = $xpath->query('.//a', $candidate);
        $linkTextLen = 0;
        if ($links) {
            foreach ($links as $link) {
                if (!$link instanceof \DOMElement) {
                    continue;
                }
                $linkTextLen += mb_strlen(self::mergeWhitespace($link->textContent ?? ''));
            }
        }

        if ($textLen > 0) {
            $linkDensity = $linkTextLen / $textLen;
            $score -= min(0.9, $linkDensity) * 500.0;
        }

        return $score;
    }

    private static function absolutizeLinks(\DOMXPath $xpath, \DOMElement $root, string $baseUrl): void
    {
        $nodes = $xpath->query('.//a[@href]', $root);
        if (!$nodes) {
            return;
        }

        foreach ($nodes as $a) {
            if (!$a instanceof \DOMElement || !$a->hasAttribute('href')) {
                continue;
            }

            $href = trim((string) $a->getAttribute('href'));
            if (
                '' === $href
                || str_starts_with($href, '#')
                || str_starts_with($href, 'mailto:')
                || str_starts_with($href, 'javascript:')
                || str_starts_with($href, 'tel:')
                || str_starts_with($href, 'data:')
            ) {
                continue;
            }

            $a->setAttribute('href', self::urlJoin($baseUrl, $href));
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
            ignoreAnchors: false,
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
