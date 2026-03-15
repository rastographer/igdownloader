<?php

namespace Rastographer\IgDownloader\Strategies;

use Rastographer\IgDownloader\Contracts\ExtractionStrategy;
use Rastographer\IgDownloader\DTO\ExtractionContext;
use Rastographer\IgDownloader\DTO\MediaResult;
use Rastographer\IgDownloader\Services\InstagramHtmlMediaExtractor;

class ProfileStrategy implements ExtractionStrategy
{
    public function __construct(
        private InstagramHtmlMediaExtractor $instagramHtmlMediaExtractor,
    ) {}

    public function try(string $shortcode, ExtractionContext $context): ?MediaResult
    {
        if ($context->preferredKind !== 'profile') {
            return null;
        }

        $username = is_string($context->meta['username'] ?? null) ? (string) $context->meta['username'] : $shortcode;
        $html = $this->instagramHtmlMediaExtractor->fetchHtml("https://www.instagram.com/{$username}/");

        return is_string($html)
            ? $this->instagramHtmlMediaExtractor->extractProfileResult($html, $username)
            : null;
    }

    public function name(): string
    {
        return 'profile';
    }

    public function version(): int
    {
        return 1;
    }
}
