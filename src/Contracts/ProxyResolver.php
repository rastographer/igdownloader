<?php

namespace Rastographer\IgDownloader\Contracts;

use Rastographer\IgDownloader\DTO\ProxyDefinition;

interface ProxyResolver
{
    public function resolve(): ?ProxyDefinition;

    public function reportSuccess(?ProxyDefinition $proxy): void;

    public function reportFailure(?ProxyDefinition $proxy, ?string $reason = null): void;
}
