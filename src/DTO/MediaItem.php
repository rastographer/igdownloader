<?php

namespace Rastographer\IgDownloader\DTO;

use Rastographer\IgDownloader\Enums\MediaKind;

readonly class MediaItem
{
    public function __construct(
        public MediaKind $kind,
        public string $url,
        public ?string $preview = null,
        public ?int $sizeBytes = null,
        public ?string $quality = null,
        public ?string $contentType = null,
    ) {}
}
