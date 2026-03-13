<?php

namespace Rastographer\IgDownloader\Contracts;

use Rastographer\IgDownloader\DTO\ExtractionContext;
use Rastographer\IgDownloader\DTO\MediaResult;

interface ExtractionStrategy
{
    public function try(string $shortcode, ExtractionContext $context): ?MediaResult;

    public function name(): string;

    public function version(): int;
}
