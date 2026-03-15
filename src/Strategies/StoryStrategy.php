<?php

namespace Rastographer\IgDownloader\Strategies;

use Rastographer\IgDownloader\Contracts\ExtractionStrategy;
use Rastographer\IgDownloader\DTO\ExtractionContext;
use Rastographer\IgDownloader\DTO\MediaResult;
use Rastographer\IgDownloader\Services\InstagramHtmlMediaExtractor;

class StoryStrategy implements ExtractionStrategy
{
    public function __construct(
        private InstagramHtmlMediaExtractor $instagramHtmlMediaExtractor,
    ) {}

    public function try(string $shortcode, ExtractionContext $context): ?MediaResult
    {
        return match ($context->preferredKind) {
            'story' => $this->extractStory($shortcode, $context),
            'highlight' => $this->extractHighlight($shortcode, $context),
            default => null,
        };
    }

    public function name(): string
    {
        return 'story';
    }

    public function version(): int
    {
        return 1;
    }

    private function extractStory(string $shortcode, ExtractionContext $context): ?MediaResult
    {
        $username = is_string($context->meta['username'] ?? null) ? (string) $context->meta['username'] : null;

        if ($username === null || $username === '') {
            return null;
        }

        $html = $this->instagramHtmlMediaExtractor->fetchHtml(
            "https://www.instagram.com/stories/{$username}/{$shortcode}/"
        );

        return is_string($html)
            ? $this->instagramHtmlMediaExtractor->extractStoryResult($html, $shortcode)
            : null;
    }

    private function extractHighlight(string $shortcode, ExtractionContext $context): ?MediaResult
    {
        $html = $this->instagramHtmlMediaExtractor->fetchHtml(
            "https://www.instagram.com/stories/highlights/{$shortcode}/"
        );

        return is_string($html)
            ? $this->instagramHtmlMediaExtractor->extractHighlightResult($html, $shortcode)
            : null;
    }
}
