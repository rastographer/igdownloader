<?php

uses(Rastographer\IgDownloader\Tests\TestCase::class);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Rastographer\IgDownloader\Services\SourceUrlStore;

it('fetches downloadable media through package routes', function () {
    $shortcode = 'ABC123XYZ90';
    $html = <<<'HTML'
<html>
    <head>
        <meta property="og:video" content="https://cdninstagram.com/media/video-1080.mp4">
        <meta property="og:image" content="https://cdninstagram.com/media/thumb.jpg">
    </head>
    <body></body>
</html>
HTML;

    Http::fake([
        'https://www.instagram.com/*' => Http::response($html, 200, ['Content-Type' => 'text/html']),
    ]);

    $response = $this->post('/fetch', [
        'url' => "https://www.instagram.com/p/{$shortcode}/",
        'expect' => 'any',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('shortcode', $shortcode)
        ->assertJsonCount(1, 'items');

    expect($response->json('items.0.preview'))->toContain('/img?')
        ->and($response->json('items.0.download'))->toContain('/dl?');
});

it('streams signed preview and download responses for allowed hosts', function () {
    $previewSource = 'https://cdninstagram.com/media/thumb.jpg';
    $downloadSource = 'https://cdninstagram.com/media/video-720.mp4';
    $sourceKey = app(SourceUrlStore::class)->put($previewSource);

    $previewUrl = URL::temporarySignedRoute('igdownloader.preview', now()->addMinutes(5), ['key' => $sourceKey]);
    $downloadUrl = URL::temporarySignedRoute('igdownloader.download', now()->addMinutes(5), [
        'u' => base64_encode($downloadSource),
        'h' => hash('sha256', $downloadSource),
        'sc' => 'ABC123XYZ90',
        'kind' => 'video',
        'q' => '720p',
    ]);

    Http::fake(function (HttpRequest $request) use ($previewSource, $downloadSource) {
        if ($request->method() === 'HEAD' && $request->url() === $previewSource) {
            return Http::response('', 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => '12']);
        }

        if ($request->method() === 'GET' && $request->url() === $previewSource) {
            return Http::response('preview-bytes', 200, ['Content-Type' => 'image/jpeg']);
        }

        if ($request->method() === 'HEAD' && $request->url() === $downloadSource) {
            return Http::response('', 200, ['Content-Type' => 'video/mp4', 'Content-Length' => '16']);
        }

        if ($request->method() === 'GET' && $request->url() === $downloadSource) {
            return Http::response('video-stream-body', 200, ['Content-Type' => 'video/mp4']);
        }

        return Http::response('', 404);
    });

    $previewResponse = $this->get($previewUrl);
    $previewResponse->assertSuccessful();
    expect($previewResponse->headers->get('content-type'))->toContain('image/jpeg');

    $downloadResponse = $this->get($downloadUrl);
    $downloadResponse->assertSuccessful();
    expect($downloadResponse->headers->get('content-type'))->toContain('video/mp4')
        ->and($downloadResponse->headers->get('content-disposition'))->toContain('ABC123XYZ90-video-720p');
});
