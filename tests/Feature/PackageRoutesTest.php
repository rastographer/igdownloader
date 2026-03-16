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

it('fetches a profile picture from a profile url', function () {
    $html = <<<'HTML'
<html>
    <head>
        <meta property="og:image" content="https://cdninstagram.com/profile/avatar.jpg">
    </head>
    <body></body>
</html>
HTML;

    Http::fake([
        'https://www.instagram.com/rastographer/' => Http::response($html, 200, ['Content-Type' => 'text/html']),
    ]);

    $response = $this->post('/fetch', [
        'url' => 'https://www.instagram.com/rastographer/',
        'expect' => 'image',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('shortcode', 'rastographer')
        ->assertJsonPath('items.0.kind', 'image')
        ->assertJsonCount(1, 'items');
});

it('fetches a story video from a story url', function () {
    $storyId = '3853356834774348296';
    $html = <<<'HTML'
<html>
    <head></head>
    <body>
        <script type="application/json" data-sjs>
            {"story":{"id":"3853356834774348296","media_type":2,"video_versions":[{"url":"https://cdninstagram.com/stories/story-video.mp4","width":720,"height":1280}],"image_versions2":{"candidates":[{"url":"https://cdninstagram.com/stories/story-preview.jpg","width":720,"height":1280}]}}}
        </script>
    </body>
</html>
HTML;

    Http::fake([
        'https://www.instagram.com/stories/rastographer/*' => Http::response($html, 200, ['Content-Type' => 'text/html']),
    ]);

    $response = $this->post('/fetch', [
        'url' => "https://www.instagram.com/stories/rastographer/{$storyId}/",
        'expect' => 'video',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('shortcode', $storyId)
        ->assertJsonPath('items.0.kind', 'video')
        ->assertJsonCount(1, 'items');
});

it('fetches highlight media from a highlight url', function () {
    $highlightId = '17893893367425927';
    $html = <<<'HTML'
<html>
    <head></head>
    <body>
        <script type="application/json" data-sjs>
            {"highlight":{"id":"17893893367425927","items":[{"id":"item-1","media_type":1,"image_versions2":{"candidates":[{"url":"https://cdninstagram.com/highlights/highlight-image.jpg","width":720,"height":1280}]}},{"id":"item-2","media_type":2,"video_versions":[{"url":"https://cdninstagram.com/highlights/highlight-video.mp4","width":720,"height":1280}],"image_versions2":{"candidates":[{"url":"https://cdninstagram.com/highlights/highlight-preview.jpg","width":720,"height":1280}]}}]}}
        </script>
    </body>
</html>
HTML;

    Http::fake([
        'https://www.instagram.com/stories/highlights/*' => Http::response($html, 200, ['Content-Type' => 'text/html']),
    ]);

    $response = $this->post('/fetch', [
        'url' => "https://www.instagram.com/stories/highlights/{$highlightId}/",
        'expect' => 'any',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('shortcode', $highlightId)
        ->assertJsonCount(2, 'items');

    expect(collect($response->json('items'))->pluck('kind')->all())->toBe(['image', 'video']);
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
