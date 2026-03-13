<?php

use Rastographer\IgDownloader\Strategies\CanonicalStrategy;
use Rastographer\IgDownloader\Strategies\EmbedStrategy;
use Rastographer\IgDownloader\Strategies\GraphQLStrategy;

return [
    'routes' => [
        'enabled' => true,
        'prefix' => '',
        'name' => 'igdownloader.',
        'middleware' => ['web'],
        'throttle' => 'throttle:10,1',
    ],
    'cache' => [
        'ttl' => 86400,
        'signed_url_ttl_minutes' => 10,
        'source_ttl' => 86400,
    ],
    'http' => [
        'user_agent' => 'Mozilla/5.0',
        'connect_timeout' => 5,
        'timeout' => 12,
        'verify' => true,
    ],
    'security' => [
        'allowed_hosts' => ['cdninstagram.com', 'fbcdn.net'],
    ],
    'logging' => [
        'channel' => env('LOG_CHANNEL', 'stack'),
    ],
    'proxy' => [
        'enabled' => false,
        'resolver' => null,
        'pool' => [],
    ],
    'strategies' => [
        'order' => ['canonical', 'embed', 'graphql'],
        'map' => [
            'canonical' => CanonicalStrategy::class,
            'embed' => EmbedStrategy::class,
            'graphql' => GraphQLStrategy::class,
        ],
    ],
];

