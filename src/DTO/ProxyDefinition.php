<?php

namespace Rastographer\IgDownloader\DTO;

readonly class ProxyDefinition
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $uri,
        public ?string $id = null,
        public array $meta = [],
    ) {}
}
