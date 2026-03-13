<?php

namespace Rastographer\IgDownloader\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Rastographer\IgDownloader\Services\SafeUrlGuard;
use Rastographer\IgDownloader\Services\SourceUrlStore;
use Rastographer\IgDownloader\Services\UpstreamHttpClient;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PreviewMediaController extends Controller
{
    public function __invoke(
        Request $request,
        SourceUrlStore $sourceUrlStore,
        SafeUrlGuard $safeUrlGuard,
        UpstreamHttpClient $upstreamHttpClient,
    ): StreamedResponse {
        $key = (string) $request->query('key');
        $sourceUrl = $sourceUrlStore->get($key);

        abort_unless(is_string($sourceUrl) && $safeUrlGuard->allows($sourceUrl), 404);

        $path = (string) parse_url($sourceUrl, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $guessedType = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            default => 'image/jpeg',
        };

        $contentType = null;
        $contentLength = null;

        try {
            $head = $upstreamHttpClient->request('HEAD', $sourceUrl);

            if ($head->successful()) {
                $contentType = $head->header('Content-Type');
                $contentLength = $head->header('Content-Length');
            }
        } catch (\Throwable) {
        }

        $response = $upstreamHttpClient->request('GET', $sourceUrl, options: ['stream' => true]);
        abort_unless($response->successful(), 502);

        return response()->stream(
            function () use ($response): void {
                $body = $response->toPsrResponse()->getBody();

                while (! $body->eof()) {
                    echo $body->read(8192);
                }
            },
            200,
            array_filter([
                'Content-Type' => $contentType ?: $guessedType,
                'Content-Length' => $contentLength,
                'Cache-Control' => 'public, max-age=86400',
                'X-Accel-Buffering' => 'no',
            ])
        );
    }
}
