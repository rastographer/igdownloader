<?php

uses(Rastographer\IgDownloader\Tests\TestCase::class);

use Rastographer\IgDownloader\Services\InstagramUrlParser;

it('parses supported instagram urls', function (string $url, string $kind, string $shortcode) {
    $parsed = app(InstagramUrlParser::class)->parse($url);

    expect($parsed)
        ->toBeArray()
        ->and($parsed['kind'])->toBe($kind)
        ->and($parsed['shortcode'])->toBe($shortcode);
})->with([
    ['https://www.instagram.com/p/ABC123XYZ90/', 'p', 'ABC123XYZ90'],
    ['instagram.com/reel/SHORTCODE12/', 'reel', 'SHORTCODE12'],
    ['https://m.instagram.com/tv/TVSHORTCODE1/?utm_source=test', 'tv', 'TVSHORTCODE1'],
    ['https://www.instagram.com/reels/REELS1234567/', 'reels', 'REELS1234567'],
]);

it('rejects unsupported or malformed urls', function (string $url) {
    expect(app(InstagramUrlParser::class)->parse($url))->toBeNull();
})->with([
    'https://example.com/not-instagram',
    'https://www.instagram.com/',
    'not-a-url',
]);
