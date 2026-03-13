<?php

namespace Rastographer\IgDownloader\Services;

use Illuminate\Support\Facades\URL;
use Rastographer\IgDownloader\DTO\MediaItem;

class SignedMediaUrlFactory
{
    public function __construct(
        private SourceUrlStore $sourceUrlStore,
    ) {}

    public function makePreviewUrl(string $sourceUrl): string
    {
        return URL::temporarySignedRoute(
            $this->routeName('preview'),
            now()->addMinutes((int) config('igdownloader.cache.signed_url_ttl_minutes', 10)),
            ['key' => $this->sourceUrlStore->put($sourceUrl)]
        );
    }

    public function makeDownloadUrl(MediaItem $item, string $shortcode): string
    {
        return URL::temporarySignedRoute(
            $this->routeName('download'),
            now()->addMinutes((int) config('igdownloader.cache.signed_url_ttl_minutes', 10)),
            [
                'u' => base64_encode($item->url),
                'h' => hash('sha256', $item->url),
                'sc' => $shortcode,
                'kind' => $item->kind->value,
                'q' => $item->quality ?? 'orig',
            ]
        );
    }

    private function routeName(string $suffix): string
    {
        return (string) config('igdownloader.routes.name', 'igdownloader.').$suffix;
    }
}
