<?php

namespace Rastographer\IgDownloader\DTO;

readonly class MediaResult
{
    /**
     * @param  array<int, MediaItem>  $items
     */
    public function __construct(
        public string $shortcode,
        public array $items,
        public ?string $caption = null,
        public ?string $author = null,
        public ?string $type = null,
    ) {}
}
