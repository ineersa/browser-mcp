<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Domain\Read\ReadDocument;
use App\Domain\Read\ReadRequest;
use App\Service\Backend\BackendInterface;

final readonly class LegacyBackendReaderAdapter implements ReaderInterface
{
    public function __construct(
        private BackendInterface $backend,
        private string $provider = 'legacy',
    ) {
    }

    public function read(ReadRequest $request): ReadDocument
    {
        $fetchUrl = '' !== $request->canonicalUrl ? $request->canonicalUrl : $request->url;
        $page = $this->backend->fetch($fetchUrl);

        return new ReadDocument(
            url: $request->url,
            canonicalUrl: '' !== $request->canonicalUrl ? $request->canonicalUrl : $page->url,
            title: $page->title,
            markdown: $page->text,
            references: $page->urls,
            provider: $request->provider ?? $this->provider,
            fetchedAt: new \DateTimeImmutable(),
        );
    }
}
