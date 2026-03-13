<?php

namespace Rastographer\IgDownloader\Strategies;

use Rastographer\IgDownloader\Contracts\ExtractionStrategy;
use Rastographer\IgDownloader\DTO\ExtractionContext;
use Rastographer\IgDownloader\DTO\MediaResult;

class GraphQLStrategy implements ExtractionStrategy
{
    public function try(string $shortcode, ExtractionContext $context): ?MediaResult
    {
        return null;
    }

    public function name(): string
    {
        return 'graphql';
    }

    public function version(): int
    {
        return 1;
    }
}
