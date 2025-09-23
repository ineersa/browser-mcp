<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BrowserState;
use App\Service\DTO\PageContents;
use App\Service\FindService;
use App\Service\PageDisplayService;
use PHPUnit\Framework\TestCase;

final class FindServiceTest extends TestCase
{
    public function testFindProducesExpectedResult(): void
    {
        $openFixture = $this->loadJson('new_page_contents.json');
        $pageData = $openFixture['new_page'] ?? [];
        $page = new PageContents(
            url: (string) ($pageData['url'] ?? ''),
            text: (string) ($pageData['text'] ?? ''),
            title: (string) ($pageData['title'] ?? ''),
            urls: (array) ($pageData['urls'] ?? []),
        );

        $state = new BrowserState();
        $state->addPage(new PageContents(url: '', text: 'Search page', title: 'Search', urls: []));
        $state->addPage(new PageContents(url: 'https://example.com/prev1', text: 'Prev 1', title: 'Prev 1', urls: []));
        $state->addPage(new PageContents(url: 'https://example.com/prev2', text: 'Prev 2', title: 'Prev 2', urls: []));
        $state->addPage($page);

        $pageDisplay = new PageDisplayService();
        $service = new FindService($state, $pageDisplay);

        $result = $service->__invoke('configure');

        $expected = (string) ($this->loadJson('find_result.json')['result'] ?? '');
        $this->assertSame($expected, $result);
    }

    private function loadJson(string $filename): array
    {
        $path = __DIR__.'/../dumps/SearxNG/'.$filename;
        $raw = file_get_contents($path);
        if (false === $raw) {
            self::fail('Failed to read fixture '.$filename);
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            self::fail('Fixture is not valid JSON: '.$filename);
        }

        return $decoded;
    }
}
