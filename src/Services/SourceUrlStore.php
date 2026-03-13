<?php

namespace Rastographer\IgDownloader\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

class SourceUrlStore
{
    public function __construct(
        private CacheRepository $cache,
    ) {}

    public function put(string $url): string
    {
        $key = 'igdl:src:'.substr(hash('sha256', $url), 0, 24);

        $this->cache->put($key, $url, (int) config('igdownloader.cache.source_ttl', 86400));

        return $key;
    }

    public function get(string $key): ?string
    {
        $value = $this->cache->get($key);

        return is_string($value) ? $value : null;
    }
}
