<?php

namespace Rastographer\IgDownloader\Strategies;

use Illuminate\Support\Facades\Log;
use Rastographer\IgDownloader\Contracts\ExtractionStrategy;
use Rastographer\IgDownloader\DTO\ExtractionContext;
use Rastographer\IgDownloader\DTO\MediaItem;
use Rastographer\IgDownloader\DTO\MediaResult;
use Rastographer\IgDownloader\Enums\MediaKind;
use Rastographer\IgDownloader\Exceptions\RateLimited;
use Rastographer\IgDownloader\Services\UpstreamHttpClient;

class CanonicalStrategy implements ExtractionStrategy
{
    public function __construct(
        private UpstreamHttpClient $upstreamHttpClient,
    ) {}

    public function try(string $shortcode, ExtractionContext $context): ?MediaResult
    {
        $paths = ['p', 'reel', 'tv', 'reels'];
        $jsonLdImage = null;
        $preferred = $context->preferredKind;

        if ($preferred !== null && ! in_array($preferred, $paths, true)) {
            return null;
        }

        if ($preferred !== null && in_array($preferred, $paths, true)) {
            usort($paths, fn (string $left, string $right): int => $left === $preferred ? -1 : ($right === $preferred ? 1 : 0));
        }

        $html = null;
        $hitPath = null;
        $lastResponse = null;

        foreach ($paths as $path) {
            $response = $this->upstreamHttpClient->request(
                'GET',
                "https://www.instagram.com/{$path}/{$shortcode}/",
                headers: [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Referer' => 'https://www.instagram.com/',
                    'Sec-Fetch-Site' => 'none',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Dest' => 'document',
                    'Upgrade-Insecure-Requests' => '1',
                    'Sec-CH-UA' => '"Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
                    'Sec-CH-UA-Mobile' => '?0',
                    'Sec-CH-UA-Platform' => '"Windows"',
                ],
                options: ['allow_redirects' => true]
            );

            $lastResponse = $response;

            if ($response->status() === 429) {
                throw new RateLimited('Instagram returned 429 for canonical extraction.');
            }

            $body = $response->body();

            if ($response->successful() && $body !== '' && ! str_contains($body, '"/accounts/login/"')) {
                $html = $body;
                $hitPath = $path;
                break;
            }
        }

        if (! is_string($html) || $html === '') {
            Log::channel((string) config('igdownloader.logging.channel', config('logging.default')))
                ->warning('ig.canonical.nohtml', [
                    'shortcode' => $shortcode,
                    'preferred' => $preferred,
                    'status' => $lastResponse?->status(),
                    'location' => $lastResponse?->header('Location'),
                    'rid' => $context->requestId,
                ]);

            return null;
        }

        if (preg_match('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches) === 1) {
            $decoded = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);
            $nodes = isset($decoded['@context']) ? [$decoded] : (is_array($decoded) ? $decoded : []);

            foreach ($nodes as $node) {
                $video = data_get($node, 'video.contentUrl');
                $image = data_get($node, 'image');

                if (is_string($video)) {
                    return new MediaResult(
                        $shortcode,
                        [new MediaItem(MediaKind::video, $video, preview: is_string($image) ? $image : null)],
                        type: 'video',
                    );
                }

                if (is_string($image)) {
                    $jsonLdImage ??= $image;
                }
            }
        }

        if (preg_match_all('#<script[^>]+type=["\']application/json["\'][^>]*data-sjs[^>]*>(.*?)</script>#is', $html, $matches) && ! empty($matches[1])) {
            foreach ($matches[1] as $rawPayload) {
                $payload = json_decode(html_entity_decode($rawPayload, ENT_QUOTES), true);

                if (! is_array($payload)) {
                    continue;
                }

                $relayMedia = $this->extractRelayMedia($payload, $shortcode);

                if (! is_array($relayMedia)) {
                    continue;
                }

                $built = $this->buildFromRelayItem($shortcode, $relayMedia);

                if ($built !== null) {
                    return $built;
                }
            }
        }

        if (preg_match('#<meta[^>]+property=["\']og:video(?::secure_url)?["\'][^>]+content=["\']([^"\']+)#i', $html, $matches) === 1) {
            return new MediaResult(
                $shortcode,
                [new MediaItem(MediaKind::video, html_entity_decode($matches[1], ENT_QUOTES))],
                type: 'video',
            );
        }

        if (is_string($jsonLdImage)) {
            return new MediaResult(
                $shortcode,
                [new MediaItem(MediaKind::image, $jsonLdImage, preview: $jsonLdImage)],
                type: 'image',
            );
        }

        if (preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)#i', $html, $matches) === 1) {
            $image = html_entity_decode($matches[1], ENT_QUOTES);

            return new MediaResult(
                $shortcode,
                [new MediaItem(MediaKind::image, $image, preview: $image)],
                type: 'image',
            );
        }

        Log::channel((string) config('igdownloader.logging.channel', config('logging.default')))
            ->warning('ig.canonical.noparse', [
                'shortcode' => $shortcode,
                'hit_path' => $hitPath,
                'status' => $lastResponse?->status(),
                'rid' => $context->requestId,
            ]);

        return null;
    }

    public function name(): string
    {
        return 'canonical';
    }

    public function version(): int
    {
        return 1;
    }

    private function normalizeUrl(string $url): string
    {
        return str_replace('\\/', '/', html_entity_decode($url, ENT_QUOTES));
    }

    private function deepFind(mixed $node, string $key): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        if (array_key_exists($key, $node) && is_array($node[$key])) {
            return $node[$key];
        }

        foreach ($node as $value) {
            $found = $this->deepFind($value, $key);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function extractRelayMedia(array $payload, string $shortcode): ?array
    {
        $node = $this->deepFind($payload, 'xdt_api__v1__media__shortcode__web_info');
        $firstItem = data_get($node, 'items.0');

        if (is_array($firstItem)) {
            return $firstItem;
        }

        return $this->findMediaByShortcode($payload, $shortcode);
    }

    private function findMediaByShortcode(mixed $node, string $shortcode): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        if (($node['__typename'] ?? null) === 'XDTMediaDict' && ($node['code'] ?? null) === $shortcode) {
            return $node;
        }

        if (isset($node['media']) && is_array($node['media']) && ($node['media']['code'] ?? null) === $shortcode) {
            return $node['media'];
        }

        foreach ($node as $value) {
            $found = $this->findMediaByShortcode($value, $shortcode);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function buildFromRelayItem(string $shortcode, array $media): ?MediaResult
    {
        $items = $this->isCarousel($media)
            ? collect((array) data_get($media, 'carousel_media', []))
                ->flatMap(fn (array $child): array => $this->buildMediaItems($child, ! $this->hasVideoSources($child), false))
                ->values()
                ->all()
            : $this->buildMediaItems($media, ! $this->hasVideoSources($media), true);

        if ($items === []) {
            return null;
        }

        return new MediaResult(
            shortcode: $shortcode,
            items: $items,
            type: $this->isCarousel($media)
                ? 'carousel'
                : ($this->hasVideoSources($media) ? 'video' : 'image'),
        );
    }

    /**
     * @return array<int, MediaItem>
     */
    private function buildMediaItems(array $media, bool $emitImageItem, bool $expandVideoVariants): array
    {
        $items = [];
        $preview = $this->bestImageUrl($media);
        $versions = $this->progressiveVersions($media);

        if (! $expandVideoVariants && $versions !== []) {
            $versions = [$versions[0]];
        }

        foreach ($versions as $version) {
            $items[] = new MediaItem(
                MediaKind::video,
                $version['u'],
                preview: $preview,
                quality: $this->qualityLabel($version['w'], $version['h']),
                contentType: 'video/mp4',
            );
        }

        if ($items === []) {
            $dashRepresentation = $this->bestDashRepresentation((string) data_get($media, 'video_dash_manifest', ''));

            if ($dashRepresentation !== null) {
                $items[] = new MediaItem(
                    MediaKind::video,
                    $dashRepresentation['u'],
                    preview: $preview,
                    quality: $this->qualityLabel($dashRepresentation['w'], $dashRepresentation['h']),
                    contentType: 'video/mp4',
                );
            }
        }

        if ($emitImageItem && is_string($preview)) {
            $items[] = new MediaItem(
                MediaKind::image,
                $preview,
                preview: $preview,
                contentType: 'image/jpeg',
            );
        }

        return $items;
    }

    /**
     * @return array<int, array{u: string, w: int, h: int}>
     */
    private function progressiveVersions(array $media): array
    {
        return collect((array) data_get($media, 'video_versions', []))
            ->map(function (array $version): array {
                return [
                    'u' => $this->normalizeUrl((string) ($version['url'] ?? '')),
                    'w' => (int) ($version['width'] ?? 0),
                    'h' => (int) ($version['height'] ?? 0),
                ];
            })
            ->filter(fn (array $version): bool => $version['u'] !== '')
            ->unique('u')
            ->sortByDesc(fn (array $version): int => $version['w'] * $version['h'])
            ->values()
            ->all();
    }

    /**
     * @return array{u: string, w: int, h: int}|null
     */
    private function bestDashRepresentation(string $mpd): ?array
    {
        if ($mpd === '') {
            return null;
        }

        $mpd = $this->normalizeUrl($mpd);

        if (! preg_match_all('#<Representation[^>]*?(?:width="(?P<w>\d+)".*?height="(?P<h>\d+)")?[^>]*>.*?<BaseURL>(?P<u>[^<]+)</BaseURL>#is', $mpd, $matches, PREG_SET_ORDER)) {
            return null;
        }

        usort(
            $matches,
            fn (array $left, array $right): int => ((int) ($left['w'] ?? 0) * (int) ($left['h'] ?? 0))
                <=> ((int) ($right['w'] ?? 0) * (int) ($right['h'] ?? 0))
        );

        $best = end($matches);

        if (! is_array($best) || empty($best['u'])) {
            return null;
        }

        return [
            'u' => $this->normalizeUrl($best['u']),
            'w' => (int) ($best['w'] ?? 0),
            'h' => (int) ($best['h'] ?? 0),
        ];
    }

    private function bestImageUrl(array $media): ?string
    {
        $candidate = collect((array) data_get($media, 'image_versions2.candidates', []))
            ->map(fn (array $image): array => [
                'u' => $this->normalizeUrl((string) ($image['url'] ?? '')),
                'w' => (int) ($image['width'] ?? 0),
                'h' => (int) ($image['height'] ?? 0),
            ])
            ->filter(fn (array $image): bool => $image['u'] !== '')
            ->sortBy(fn (array $image): int => $image['w'] * $image['h'])
            ->last();

        return is_array($candidate) ? $candidate['u'] : null;
    }

    private function qualityLabel(int $width, int $height): string
    {
        $largestDimension = max($width, $height);

        return match (true) {
            $largestDimension >= 1080 => '1080p',
            $largestDimension >= 720 => '720p',
            $largestDimension >= 540 => '540p',
            $largestDimension >= 480 => '480p',
            $largestDimension >= 360 => '360p',
            default => "{$width}x{$height}",
        };
    }

    private function hasVideoSources(array $media): bool
    {
        return (array) data_get($media, 'video_versions', []) !== []
            || filled(data_get($media, 'video_dash_manifest'))
            || (int) ($media['media_type'] ?? 0) === 2;
    }

    private function isCarousel(array $media): bool
    {
        return (int) ($media['media_type'] ?? 0) === 8
            && (array) data_get($media, 'carousel_media', []) !== [];
    }
}
