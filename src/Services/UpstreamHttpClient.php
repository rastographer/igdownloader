<?php

namespace Rastographer\IgDownloader\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Rastographer\IgDownloader\Contracts\ProxyResolver;
use Rastographer\IgDownloader\DTO\ProxyDefinition;
use Throwable;

class UpstreamHttpClient
{
    public function __construct(
        private ProxyResolver $proxyResolver,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $options
     */
    public function request(string $method, string $url, array $headers = [], array $options = []): Response
    {
        $proxy = $this->proxyResolver->resolve();

        $pendingRequest = Http::withHeaders(array_merge([
            'User-Agent' => (string) config('igdownloader.http.user_agent', 'Mozilla/5.0'),
        ], $headers))
            ->timeout((int) config('igdownloader.http.timeout', 12))
            ->connectTimeout((int) config('igdownloader.http.connect_timeout', 5))
            ->withOptions($this->buildOptions($proxy, $options));

        try {
            $response = $pendingRequest->send($method, $url);
        } catch (Throwable $exception) {
            $this->proxyResolver->reportFailure($proxy, $exception->getMessage());

            throw $exception;
        }

        if ($response->status() === 429 || $response->serverError()) {
            $this->proxyResolver->reportFailure($proxy, 'upstream_status:'.$response->status());
        } else {
            $this->proxyResolver->reportSuccess($proxy);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildOptions(?ProxyDefinition $proxy, array $options): array
    {
        $baseOptions = [
            'verify' => (bool) config('igdownloader.http.verify', true),
        ];

        if ($proxy !== null) {
            $baseOptions['proxy'] = $proxy->uri;
        }

        return array_merge($baseOptions, $options);
    }
}
