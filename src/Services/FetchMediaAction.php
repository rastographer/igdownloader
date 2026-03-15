<?php

namespace Rastographer\IgDownloader\Services;

use Illuminate\Support\Facades\Log;
use Rastographer\IgDownloader\Contracts\Downloader;
use Rastographer\IgDownloader\DTO\ExtractionContext;
use Rastographer\IgDownloader\DTO\MediaItem;
use Rastographer\IgDownloader\Exceptions\InvalidInstagramUrl;
use Rastographer\IgDownloader\Exceptions\NotFound;

class FetchMediaAction
{
    public function __construct(
        private Downloader $downloader,
        private SignedMediaUrlFactory $signedMediaUrlFactory,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $url, ?string $requestId = null): array
    {
        $parsed = $this->downloader->parseUrl($url);

        if ($parsed === null) {
            throw new InvalidInstagramUrl('That does not look like a valid Instagram link.');
        }

        $context = new ExtractionContext(
            preferredKind: $parsed['kind'],
            requestId: $requestId,
            meta: [
                'url' => $url,
                'username' => $parsed['username'] ?? null,
            ],
        );

        $result = $this->downloader->fetch($parsed['shortcode'], $context);
        $coverSource = $this->coverSource($result->items);

        $items = [];
        $seen = [];

        foreach ($result->items as $index => $item) {
            if (isset($seen[$item->url])) {
                continue;
            }

            $seen[$item->url] = true;
            $previewSource = $item->preview ?? $item->url;

            $items[] = [
                'position' => $index + 1,
                'kind' => $item->kind->value,
                'preview' => $this->signedMediaUrlFactory->makePreviewUrl($previewSource),
                'download' => $this->signedMediaUrlFactory->makeDownloadUrl($item, $parsed['shortcode']),
                'url' => $item->url,
                'size' => $item->sizeBytes,
                'quality' => $item->quality,
            ];
        }

        if ($items === []) {
            throw new NotFound('No downloadable media was produced.');
        }

        Log::channel((string) config('igdownloader.logging.channel', config('logging.default')))
            ->info('ig.fetch.ok', [
                'rid' => $requestId,
                'shortcode' => $parsed['shortcode'],
                'preferred' => $parsed['kind'],
                'counts' => [
                    'all' => count($items),
                    'images' => count(array_filter($items, fn (array $item): bool => $item['kind'] === 'image')),
                    'videos' => count(array_filter($items, fn (array $item): bool => $item['kind'] === 'video')),
                ],
            ]);

        return [
            'ok' => true,
            'shortcode' => $parsed['shortcode'],
            'cover' => $coverSource !== null ? $this->signedMediaUrlFactory->makePreviewUrl($coverSource) : null,
            'items' => $items,
            'schema_version' => 2,
        ];
    }

    /**
     * @param  array<int, MediaItem>  $items
     */
    private function coverSource(array $items): ?string
    {
        if ($items === []) {
            return null;
        }

        foreach ($items as $item) {
            if ($item->kind->value === 'image') {
                return $item->preview ?? $item->url;
            }
        }

        return $items[0]->preview ?? $items[0]->url;
    }
}
