<?php

namespace Rastographer\IgDownloader\DTO;

readonly class ExtractionContext
{
    public function __construct(
        public ?string $preferredKind = null,
        public ?string $requestId = null,
    ) {}
}
