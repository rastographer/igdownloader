<?php

namespace Rastographer\IgDownloader\Services;

class SafeUrlGuard
{
    public function allows(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($parts['host']);

        foreach ((array) config('igdownloader.security.allowed_hosts', []) as $allowedHost) {
            $allowedHost = strtolower(trim((string) $allowedHost));

            if ($allowedHost !== '' && ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost))) {
                return true;
            }
        }

        return false;
    }
}
