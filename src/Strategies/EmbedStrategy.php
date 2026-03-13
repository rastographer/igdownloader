<?php

namespace Rastographer\IgDownloader\Strategies;

use Rastographer\IgDownloader\Contracts\ExtractionStrategy;
use Rastographer\IgDownloader\DTO\ExtractionContext;
use Rastographer\IgDownloader\DTO\MediaItem;
use Rastographer\IgDownloader\DTO\MediaResult;
use Rastographer\IgDownloader\Enums\MediaKind;
use Rastographer\IgDownloader\Services\UpstreamHttpClient;

class EmbedStrategy implements ExtractionStrategy
{
    public function __construct(
        private UpstreamHttpClient $upstreamHttpClient,
    ) {}

    public function try(string $shortcode, ExtractionContext $context): ?MediaResult
    {
        $response = $this->upstreamHttpClient->request(
            'GET',
            "https://www.instagram.com/p/{$shortcode}/embed/captioned/",
            headers: ['Accept' => 'text/html,*/*']
        );

        if (! $response->successful()) {
            return null;
        }

        $html = $response->body();

        if (preg_match('#"contextJSON":"([^"]+)"#', $html, $matches) !== 1) {
            return null;
        }

        $json = json_decode(stripcslashes($matches[1]), true);

        if (! is_array($json)) {
            return null;
        }

        $media = data_get($json, 'gql_data.shortcode_media');

        if (! is_array($media)) {
            return null;
        }

        $items = [];
        $type = data_get($media, '__typename');

        if (data_get($media, 'is_video') === true && is_string(data_get($media, 'video_url'))) {
            $items[] = new MediaItem(
                MediaKind::video,
                data_get($media, 'video_url'),
                preview: is_string(data_get($media, 'display_url')) ? data_get($media, 'display_url') : null,
            );
        }

        if (data_get($media, 'is_video') === false && is_string(data_get($media, 'display_url'))) {
            $items[] = new MediaItem(
                MediaKind::image,
                data_get($media, 'display_url'),
                preview: data_get($media, 'display_url'),
            );
        }

        foreach ((array) data_get($media, 'edge_sidecar_to_children.edges', []) as $edge) {
            $node = $edge['node'] ?? null;

            if (! is_array($node)) {
                continue;
            }

            if (($node['is_video'] ?? false) && is_string($node['video_url'] ?? null)) {
                $items[] = new MediaItem(
                    MediaKind::video,
                    $node['video_url'],
                    preview: is_string($node['display_url'] ?? null) ? $node['display_url'] : null,
                );

                continue;
            }

            if (is_string($node['display_url'] ?? null)) {
                $items[] = new MediaItem(
                    MediaKind::image,
                    $node['display_url'],
                    preview: $node['display_url'],
                );
            }
        }

        if ($items === []) {
            return null;
        }

        return new MediaResult(
            shortcode: $shortcode,
            items: $items,
            caption: data_get($media, 'edge_media_to_caption.edges.0.node.text'),
            author: data_get($media, 'owner.username'),
            type: str_contains((string) $type, 'GraphVideo')
                ? 'video'
                : (str_contains((string) $type, 'Sidecar') ? 'carousel' : 'image'),
        );
    }

    public function name(): string
    {
        return 'embed';
    }

    public function version(): int
    {
        return 1;
    }
}
