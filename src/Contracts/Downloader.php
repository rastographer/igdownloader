<?php

namespace Rastographer\IgDownloader\Contracts;

use Rastographer\IgDownloader\DTO\ExtractionContext;
use Rastographer\IgDownloader\DTO\MediaResult;

interface Downloader
{
    public function fetch(string $shortcode, ?ExtractionContext $context = null): MediaResult;

    public function parseUrl(string $url): ?array;
}
