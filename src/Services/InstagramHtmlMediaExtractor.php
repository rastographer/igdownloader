<?php

namespace Rastographer\IgDownloader\Services;

use Rastographer\IgDownloader\DTO\MediaItem;
use Rastographer\IgDownloader\DTO\MediaResult;
use Rastographer\IgDownloader\Enums\MediaKind;
use Rastographer\IgDownloader\Exceptions\RateLimited;

class InstagramHtmlMediaExtractor
{
    public function __construct(
        private UpstreamHttpClient $upstreamHttpClient,
    ) {}

    public function fetchHtml(string $url): ?string
    {
        $response = $this->upstreamHttpClient->request(
            'GET',
            $url,
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

        if ($response->status() === 429) {
            throw new RateLimited('Instagram returned 429 for HTML extraction.');
        }

        $body = $response->body();

        if (! $response->successful() || $body === '' || str_contains($body, '"/accounts/login/"')) {
            return null;
        }

        return $body;
    }

    public function extractProfileResult(string $html, string $username): ?MediaResult
    {
        foreach ($this->jsonPayloads($html) as $payload) {
            $profile = $this->findProfileNode($payload, $username);
            $image = $this->profileImageUrl($profile);

            if (is_string($image) && $image !== '') {
                return $this->imageResult($username, $image, author: $username, type: 'profile');
            }
        }

        $image = $this->openGraphImage($html);

        return is_string($image) ? $this->imageResult($username, $image, author: $username, type: 'profile') : null;
    }

    public function extractStoryResult(string $html, string $storyId): ?MediaResult
    {
        foreach ($this->jsonPayloads($html) as $payload) {
            $media = $this->findStoryMediaNode($payload, $storyId);

            if (is_array($media)) {
                $built = $this->buildFromMediaNode($storyId, $media, false);

                if ($built !== null) {
                    return $built;
                }
            }
        }

        return $this->fallbackFromOpenGraph($html, $storyId, 'story');
    }

    public function extractHighlightResult(string $html, string $highlightId): ?MediaResult
    {
        foreach ($this->jsonPayloads($html) as $payload) {
            $items = $this->findHighlightItems($payload, $highlightId);

            if ($items !== []) {
                $built = $this->buildFromMediaCollection($highlightId, $items, 'highlight');

                if ($built !== null) {
                    return $built;
                }
            }
        }

        return $this->fallbackFromOpenGraph($html, $highlightId, 'highlight');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function jsonPayloads(string $html): array
    {
        if (! preg_match_all('#<script[^>]+type=["\']application/json["\'][^>]*data-sjs[^>]*>(.*?)</script>#is', $html, $matches)) {
            return [];
        }

        $payloads = [];

        foreach ($matches[1] ?? [] as $rawPayload) {
            $decoded = json_decode(html_entity_decode($rawPayload, ENT_QUOTES), true);

            if (is_array($decoded)) {
                $payloads[] = $decoded;
            }
        }

        return $payloads;
    }

    private function findProfileNode(mixed $node, string $username): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        $candidateUsername = strtolower((string) ($node['username'] ?? ''));

        if ($candidateUsername === strtolower($username) && $this->profileImageUrl($node) !== null) {
            return $node;
        }

        if (isset($node['user']) && is_array($node['user'])) {
            $user = $node['user'];
            $candidateUsername = strtolower((string) ($user['username'] ?? ''));

            if ($candidateUsername === strtolower($username) && $this->profileImageUrl($user) !== null) {
                return $user;
            }
        }

        foreach ($node as $value) {
            $found = $this->findProfileNode($value, $username);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function profileImageUrl(?array $profile): ?string
    {
        if (! is_array($profile)) {
            return null;
        }

        $candidates = [
            data_get($profile, 'hd_profile_pic_url_info.url'),
            data_get($profile, 'profile_pic_url_hd'),
            data_get($profile, 'profile_pic_url'),
            data_get($profile, 'profile_pic_url_info.url'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $this->normalizeUrl($candidate);
            }
        }

        return null;
    }

    private function findStoryMediaNode(mixed $node, string $storyId): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        $candidateIds = array_filter([
            isset($node['id']) ? (string) $node['id'] : null,
            isset($node['pk']) ? (string) $node['pk'] : null,
            isset($node['story_pk']) ? (string) $node['story_pk'] : null,
        ]);

        if (in_array($storyId, $candidateIds, true) && $this->looksLikeMediaNode($node)) {
            return $node;
        }

        foreach ($node as $value) {
            $found = $this->findStoryMediaNode($value, $storyId);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findHighlightItems(mixed $node, string $highlightId): array
    {
        if (! is_array($node)) {
            return [];
        }

        $items = $this->extractHighlightItemsFromNode($node, $highlightId);

        if ($items !== []) {
            return $items;
        }

        foreach ($node as $value) {
            $found = $this->findHighlightItems($value, $highlightId);

            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractHighlightItemsFromNode(array $node, string $highlightId): array
    {
        $candidateIds = array_filter([
            isset($node['id']) ? (string) $node['id'] : null,
            isset($node['pk']) ? (string) $node['pk'] : null,
            isset($node['reel_id']) ? (string) $node['reel_id'] : null,
            isset($node['highlight_id']) ? (string) $node['highlight_id'] : null,
        ]);

        if (in_array($highlightId, $candidateIds, true)) {
            $items = $this->normalizeItemCollection($node['items'] ?? null);

            if ($items !== []) {
                return $items;
            }
        }

        foreach ((array) ($node['reels_media'] ?? []) as $reel) {
            if (! is_array($reel)) {
                continue;
            }

            $items = $this->extractHighlightItemsFromNode($reel, $highlightId);

            if ($items !== []) {
                return $items;
            }
        }

        return [];
    }

    /**
     * @param  mixed  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItemCollection(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (is_array($item) && $this->looksLikeMediaNode($item)) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    private function looksLikeMediaNode(array $node): bool
    {
        return (array) data_get($node, 'video_versions', []) !== []
            || filled(data_get($node, 'video_dash_manifest'))
            || (array) data_get($node, 'image_versions2.candidates', []) !== []
            || (int) ($node['media_type'] ?? 0) > 0;
    }

    private function fallbackFromOpenGraph(string $html, string $identifier, string $type): ?MediaResult
    {
        $video = $this->openGraphVideo($html);
        $image = $this->openGraphImage($html);

        if (is_string($video)) {
            return new MediaResult(
                shortcode: $identifier,
                items: [new MediaItem(MediaKind::video, $video, preview: $image)],
                type: $type,
            );
        }

        return is_string($image) ? $this->imageResult($identifier, $image, type: $type) : null;
    }

    private function imageResult(string $identifier, string $url, ?string $author = null, ?string $type = 'image'): MediaResult
    {
        return new MediaResult(
            shortcode: $identifier,
            items: [new MediaItem(MediaKind::image, $url, preview: $url, contentType: 'image/jpeg')],
            author: $author,
            type: $type,
        );
    }

    private function openGraphVideo(string $html): ?string
    {
        if (preg_match('#<meta[^>]+property=["\']og:video(?::secure_url)?["\'][^>]+content=["\']([^"\']+)#i', $html, $matches) !== 1) {
            return null;
        }

        return $this->normalizeUrl(html_entity_decode($matches[1], ENT_QUOTES));
    }

    private function openGraphImage(string $html): ?string
    {
        if (preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)#i', $html, $matches) !== 1) {
            return null;
        }

        return $this->normalizeUrl(html_entity_decode($matches[1], ENT_QUOTES));
    }

    private function buildFromMediaNode(string $identifier, array $media, bool $expandVideoVariants): ?MediaResult
    {
        $items = $this->buildMediaItems($media, ! $this->hasVideoSources($media), $expandVideoVariants);

        if ($items === []) {
            return null;
        }

        return new MediaResult(
            shortcode: $identifier,
            items: $items,
            type: $this->hasVideoSources($media) ? 'video' : 'image',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function buildFromMediaCollection(string $identifier, array $nodes, string $type): ?MediaResult
    {
        $items = [];

        foreach ($nodes as $node) {
            $items = array_merge($items, $this->buildMediaItems($node, ! $this->hasVideoSources($node), false));
        }

        if ($items === []) {
            return null;
        }

        return new MediaResult(
            shortcode: $identifier,
            items: $items,
            type: $type,
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
            ->map(fn (array $version): array => [
                'u' => $this->normalizeUrl((string) ($version['url'] ?? '')),
                'w' => (int) ($version['width'] ?? 0),
                'h' => (int) ($version['height'] ?? 0),
            ])
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

    private function normalizeUrl(string $url): string
    {
        return str_replace('\\/', '/', html_entity_decode($url, ENT_QUOTES));
    }
}
