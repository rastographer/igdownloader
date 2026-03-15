<?php

uses(Rastographer\IgDownloader\Tests\TestCase::class);

use Rastographer\IgDownloader\DTO\MediaItem;
use Rastographer\IgDownloader\Enums\MediaKind;
use Rastographer\IgDownloader\Services\SignedMediaUrlFactory;

it('builds signed preview urls using the configured package route name', function () {
    config()->set('igdownloader.routes.name', 'igdownloader.');

    $url = app(SignedMediaUrlFactory::class)->makePreviewUrl('https://cdninstagram.com/media/thumb.jpg');
    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $query);

    expect($parts['path'] ?? null)->toEndWith('/img')
        ->and($query)->toHaveKeys(['expires', 'signature', 'key']);
});

it('builds signed download urls with media metadata', function () {
    config()->set('igdownloader.routes.name', 'igdownloader.');

    $item = new MediaItem(
        kind: MediaKind::video,
        url: 'https://cdninstagram.com/media/video-720.mp4',
        quality: '720p',
    );

    $url = app(SignedMediaUrlFactory::class)->makeDownloadUrl($item, 'ABC123XYZ90');
    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $query);

    expect($parts['path'] ?? null)->toEndWith('/dl')
        ->and($query['sc'] ?? null)->toBe('ABC123XYZ90')
        ->and($query['kind'] ?? null)->toBe('video')
        ->and($query['q'] ?? null)->toBe('720p')
        ->and($query)->toHaveKeys(['expires', 'signature', 'u', 'h']);
});
