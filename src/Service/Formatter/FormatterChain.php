<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Format\FormatContext;
use Psr\Container\ContainerInterface;

final class FormatterChain implements FormatterPipelineInterface
{
    /**
     * @var list<FormatterInterface>
     */
    private array $formatters = [];

    public function __construct(
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    public function addFormatter(string|FormatterInterface $formatter): self
    {
        $resolved = $this->resolveFormatter($formatter);
        $this->formatters[] = $resolved;

        return $this;
    }

    public function format(FormatContext $context): FormatContext
    {
        foreach ($this->formatters as $formatter) {
            $context = $formatter->format($context);
        }

        return $context;
    }

    private function resolveFormatter(string|FormatterInterface $formatter): FormatterInterface
    {
        if ($formatter instanceof FormatterInterface) {
            return $formatter;
        }

        if (null === $this->container) {
            throw new \InvalidArgumentException(sprintf('Cannot resolve formatter `%s` without container.', $formatter));
        }

        $resolved = $this->container->get($formatter);
        if (!$resolved instanceof FormatterInterface) {
            throw new \InvalidArgumentException(sprintf('Resolved formatter `%s` must implement FormatterInterface.', $formatter));
        }

        return $resolved;
    }
}
