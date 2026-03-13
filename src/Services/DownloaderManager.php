<?php

namespace Rastographer\IgDownloader\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Rastographer\IgDownloader\Contracts\Downloader;
use Rastographer\IgDownloader\Contracts\ExtractionStrategy;
use Rastographer\IgDownloader\DTO\ExtractionContext;
use Rastographer\IgDownloader\DTO\MediaResult;
use Rastographer\IgDownloader\Exceptions\NotFound;
use Rastographer\IgDownloader\Exceptions\RateLimited;
use Throwable;

class DownloaderManager implements Downloader
{
    /**
     * @param  array<int, ExtractionStrategy>  $strategies
     */
    public function __construct(
        private array $strategies,
        private CacheRepository $cache,
        private InstagramUrlParser $parser,
    ) {}

    public function fetch(string $shortcode, ?ExtractionContext $context = null): MediaResult
    {
        $context ??= new ExtractionContext;

        return $this->cache->remember(
            $this->cacheKey($shortcode, $context),
            (int) config('igdownloader.cache.ttl', 86400),
            function () use ($shortcode, $context): MediaResult {
                $rateLimited = false;

                foreach ($this->strategies as $strategy) {
                    $this->log('info', 'ig.strategy.try', [
                        'strategy' => $strategy->name(),
                        'shortcode' => $shortcode,
                        'preferred' => $context->preferredKind,
                        'rid' => $context->requestId,
                    ]);

                    $startedAt = microtime(true);

                    try {
                        $result = $strategy->try($shortcode, $context);
                    } catch (RateLimited $exception) {
                        $rateLimited = true;

                        $this->log('warning', 'ig.strategy.rate_limited', [
                            'strategy' => $strategy->name(),
                            'shortcode' => $shortcode,
                            'rid' => $context->requestId,
                        ]);

                        continue;
                    } catch (Throwable $exception) {
                        $this->log('error', 'ig.strategy.exception', [
                            'strategy' => $strategy->name(),
                            'shortcode' => $shortcode,
                            'rid' => $context->requestId,
                            'exception' => $exception::class,
                            'message' => $exception->getMessage(),
                        ]);

                        continue;
                    }

                    $elapsedMs = number_format((microtime(true) - $startedAt) * 1000, 1);

                    if ($result !== null) {
                        $this->log('info', 'ig.strategy.hit', [
                            'strategy' => $strategy->name(),
                            'shortcode' => $shortcode,
                            'rid' => $context->requestId,
                            'ms' => $elapsedMs,
                        ]);

                        return $result;
                    }

                    $this->log('warning', 'ig.strategy.miss', [
                        'strategy' => $strategy->name(),
                        'shortcode' => $shortcode,
                        'rid' => $context->requestId,
                        'ms' => $elapsedMs,
                    ]);
                }

                if ($rateLimited) {
                    throw new RateLimited('Instagram temporarily rate-limited the extraction flow.');
                }

                throw new NotFound("No media found for {$shortcode}.");
            }
        );
    }

    public function parseUrl(string $url): ?array
    {
        return $this->parser->parse($url);
    }

    private function cacheKey(string $shortcode, ExtractionContext $context): string
    {
        $versions = array_map(
            fn (ExtractionStrategy $strategy): string => $strategy->name().':'.$strategy->version(),
            $this->strategies
        );

        return 'igdl:shortcode:'
            .$shortcode
            .':kind:'.($context->preferredKind ?? 'any')
            .':v'.substr(md5(implode('|', $versions)), 0, 8);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $level, string $message, array $context): void
    {
        Log::channel((string) config('igdownloader.logging.channel', config('logging.default')))
            ->log($level, $message, $context);
    }
}
