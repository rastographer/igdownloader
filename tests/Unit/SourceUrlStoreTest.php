<?php

uses(Rastographer\IgDownloader\Tests\TestCase::class);

use Rastographer\IgDownloader\Services\SourceUrlStore;

it('stores and retrieves source urls through the configured cache store', function () {
    config()->set('cache.default', 'array');

    $store = app(SourceUrlStore::class);
    $url = 'https://cdninstagram.com/media/thumb.jpg';
    $key = $store->put($url);

    expect($key)->toStartWith('igdl:src:')
        ->and($store->get($key))->toBe($url);
});
