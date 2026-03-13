<?php

namespace Rastographer\IgDownloader\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Rastographer\IgDownloader\Services\SafeUrlGuard;
use Rastographer\IgDownloader\Services\UpstreamHttpClient;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadMediaController extends Controller
{
    public function __invoke(
        Request $request,
        SafeUrlGuard $safeUrlGuard,
        UpstreamHttpClient $upstreamHttpClient,
    ): StreamedResponse {
        $sourceUrl = base64_decode((string) $request->query('u'), true);
        $hash = (string) $request->query('h');

        abort_unless(is_string($sourceUrl) && $sourceUrl !== '' && hash('sha256', $sourceUrl) === $hash, 403);
        abort_unless($safeUrlGuard->allows($sourceUrl), 403);

        $shortcode = (string) ($request->query('sc') ?? 'instagram');
        $kind = (string) ($request->query('kind') ?? 'file');
        $quality = (string) ($request->query('q') ?? 'orig');
        $path = (string) parse_url($sourceUrl, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === '') {
            $extension = $kind === 'video' ? 'mp4' : ($kind === 'image' ? 'jpg' : 'bin');
        }

        $filename = sprintf(
            '%s-%s-%s-%s.%s',
            $shortcode,
            $kind,
            $quality,
            substr(hash('sha256', $sourceUrl), 0, 2),
            $extension
        );

        $contentType = null;
        $contentLength = null;

        try {
            $head = $upstreamHttpClient->request('HEAD', $sourceUrl, headers: ['Range' => 'bytes=0-0']);

            if ($head->successful()) {
                $contentType = $head->header('Content-Type');
                $contentLength = $head->header('Content-Length');
            }
        } catch (\Throwable) {
        }

        if (! is_string($contentType) || $contentType === '') {
            $contentType = match ($extension) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                'mp4' => 'video/mp4',
                default => 'application/octet-stream',
            };
        }

        $response = $upstreamHttpClient->request('GET', $sourceUrl, options: ['stream' => true]);
        abort_unless($response->successful(), 502);

        return response()->streamDownload(
            function () use ($response): void {
                $body = $response->toPsrResponse()->getBody();

                while (! $body->eof()) {
                    echo $body->read(8192);
                }
            },
            $filename,
            array_filter([
                'Content-Type' => $contentType,
                'Content-Length' => $contentLength,
                'X-Accel-Buffering' => 'no',
            ])
        );
    }
}
