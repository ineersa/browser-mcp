<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Processors;

use App\Service\Reader\Processors\PageProcessor;
use PHPUnit\Framework\TestCase;

final class PageProcessorTest extends TestCase
{
    public function testProcessHtmlKeepsLinksAsMarkdown(): void
    {
        $html = <<<'HTML'
<html>
  <head><title>Docs</title></head>
  <body>
    <main>
      <p>Read <a href="/docs/getting-started">the docs</a> now.</p>
    </main>
  </body>
</html>
HTML;

        $page = PageProcessor::processHtml($html, 'https://example.com/start', null, false);

        $this->assertSame('Docs', $page->title);
        $this->assertStringContainsString('[the docs](https://example.com/docs/getting-started)', $page->text);
        $this->assertStringNotContainsString('【', $page->text);
        $this->assertSame([], $page->urls);
    }

    public function testProcessHtmlPrefersMainContentAndDropsBoilerplate(): void
    {
        $html = <<<'HTML'
<html>
  <head><title>Article</title></head>
  <body>
    <header>
      <nav>Sign in Subscribe Pricing Support</nav>
    </header>
    <main>
      <article class="post-content">
        <h1>Useful article</h1>
        <p>This is the actual article body with enough detail to be considered meaningful content for extraction and rendering in the reader output.</p>
      </article>
    </main>
    <footer>Copyright 2026</footer>
  </body>
</html>
HTML;

        $page = PageProcessor::processHtml($html, 'https://example.com/article', null, false);

        $this->assertStringContainsString('Useful article', $page->text);
        $this->assertStringContainsString('actual article body', $page->text);
        $this->assertStringNotContainsString('Sign in Subscribe Pricing Support', $page->text);
        $this->assertStringNotContainsString('Copyright 2026', $page->text);
    }

    public function testProcessHtmlDoesNotDropMainContentInsideSidebarLayoutWrappers(): void
    {
        $html = <<<'HTML'
<html>
  <head><title>Docs Page</title></head>
  <body>
    <main id="main-content" class="ui-page-main-content">
      <div class="ui-page-grid-content-left-sidebar-right-sidebar">
        <aside class="sidebar">Menu links</aside>
        <article class="content">
          <h1>Mate Component</h1>
          <p>The Symfony AI mate component helps assistants understand your codebase and project constraints.</p>
          <p>It can combine docs, tools, and context for stronger answers.</p>
          <p>This is the primary content and should stay visible.</p>
        </article>
      </div>
    </main>
  </body>
</html>
HTML;

        $page = PageProcessor::processHtml($html, 'https://symfony.com/doc/current/ai/components/mate.html', null, true);

        $this->assertStringContainsString('Mate Component', $page->text);
        $this->assertStringContainsString('primary content', $page->text);
        $this->assertStringNotContainsString('Menu links', $page->text);
    }

    public function testProcessHtmlRemovesCodeLineNumberGutter(): void
    {
        $html = <<<'HTML'
<html>
  <head><title>Code Sample</title></head>
  <body>
    <main>
      <h1>Example</h1>
      <div class="highlight">
        <pre class="codeblock-lines">1
2
3</pre>
        <pre><code>echo "hello";
echo "world";</code></pre>
      </div>
    </main>
  </body>
</html>
HTML;

        $page = PageProcessor::processHtml($html, 'https://example.com/code', null, false);

        $this->assertStringContainsString('echo "hello";', $page->text);
        $this->assertStringContainsString('echo "world";', $page->text);
        $this->assertStringNotContainsString("1\n2\n3", $page->text);
    }

    public function testProcessHtmlRemovesConfiguredNoiseClassToken(): void
    {
        $html = <<<'HTML'
<html>
  <head><title>Custom Noise</title></head>
  <body>
    <main>
      <div class="my-line-gutter">1 2 3</div>
      <p>Real content stays.</p>
    </main>
  </body>
</html>
HTML;

        $page = PageProcessor::processHtml(
            html: $html,
            url: 'https://example.com/custom-noise',
            title: null,
            displayUrls: false,
            noiseClassTokens: ['my-line-gutter'],
        );

        $this->assertStringContainsString('Real content stays.', $page->text);
        $this->assertStringNotContainsString('1 2 3', $page->text);
    }
}
