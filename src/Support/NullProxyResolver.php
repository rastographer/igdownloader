<?php

namespace Rastographer\IgDownloader\Support;

use Rastographer\IgDownloader\Contracts\ProxyResolver;
use Rastographer\IgDownloader\DTO\ProxyDefinition;

class NullProxyResolver implements ProxyResolver
{
    public function resolve(): ?ProxyDefinition
    {
        return null;
    }

    public function reportSuccess(?ProxyDefinition $proxy): void {}

    public function reportFailure(?ProxyDefinition $proxy, ?string $reason = null): void {}
}
