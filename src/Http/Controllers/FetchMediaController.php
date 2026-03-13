<?php

namespace Rastographer\IgDownloader\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Rastographer\IgDownloader\Exceptions\InvalidInstagramUrl;
use Rastographer\IgDownloader\Exceptions\NotFound;
use Rastographer\IgDownloader\Exceptions\RateLimited;
use Rastographer\IgDownloader\Services\FetchMediaAction;
use Throwable;

class FetchMediaController extends Controller
{
    public function __invoke(Request $request, FetchMediaAction $action): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url'],
            'expect' => ['nullable', 'in:image,video,any'],
        ]);

        $requestId = $request->attributes->get('request_id');

        try {
            return response()->json($action->handle((string) $data['url'], is_string($requestId) ? $requestId : null));
        } catch (InvalidInstagramUrl $exception) {
            return $this->error(
                code: 'INVALID_URL',
                message: 'That does not look like a valid Instagram link. Try copying the full URL from your browser.'
            );
        } catch (RateLimited $exception) {
            return $this->error(
                code: 'RATE_LIMIT',
                message: 'We are being rate-limited by Instagram. Please wait a minute and try again.'
            );
        } catch (NotFound $exception) {
            return $this->error(
                code: 'NO_MEDIA',
                message: 'We could not find downloadable media for this link. The post may be private or removed.'
            );
        } catch (Throwable $exception) {
            Log::channel((string) config('igdownloader.logging.channel', config('logging.default')))
                ->error('ig.fetch.exception', [
                    'rid' => $requestId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

            return $this->error(
                code: 'NETWORK',
                message: 'Something went wrong while fetching. Please try again.',
                status: 500
            );
        }
    }

    private function error(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => [
                'code' => $code,
                'title' => match ($code) {
                    'INVALID_URL' => 'Invalid link',
                    'NO_MEDIA' => 'No downloadable media',
                    'RATE_LIMIT' => 'Rate limit reached',
                    default => 'Could not fetch media',
                },
                'message' => $message,
                'help' => match ($code) {
                    'INVALID_URL' => 'Example: https://www.instagram.com/p/XXXXXXXXXX/',
                    'NO_MEDIA' => 'Make sure the post is public and the link opens in an incognito tab.',
                    'RATE_LIMIT' => 'Wait a minute and retry, or try a different link.',
                    default => 'Please retry. If the issue persists, try a different link.',
                },
            ],
        ], $status);
    }
}
