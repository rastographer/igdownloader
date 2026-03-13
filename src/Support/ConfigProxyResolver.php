<?php

namespace Rastographer\IgDownloader\Support;

use Rastographer\IgDownloader\Contracts\ProxyResolver;
use Rastographer\IgDownloader\DTO\ProxyDefinition;

class ConfigProxyResolver implements ProxyResolver
{
    /**
     * @param  array<int, array<string, mixed>>  $pool
     */
    public function __construct(
        private array $pool,
    ) {}

    public function resolve(): ?ProxyDefinition
    {
        if ($this->pool === []) {
            return null;
        }

        $weighted = [];

        foreach ($this->pool as $entry) {
            $uri = trim((string) ($entry['uri'] ?? ''));

            if ($uri === '') {
                continue;
            }

            $weight = max(1, (int) ($entry['weight'] ?? 1));

            for ($index = 0; $index < $weight; $index++) {
                $weighted[] = new ProxyDefinition(
                    uri: $uri,
                    id: isset($entry['id']) ? (string) $entry['id'] : null,
                    meta: $entry,
                );
            }
        }

        if ($weighted === []) {
            return null;
        }

        return $weighted[array_rand($weighted)];
    }

    public function reportSuccess(?ProxyDefinition $proxy): void {}

    public function reportFailure(?ProxyDefinition $proxy, ?string $reason = null): void {}
}
