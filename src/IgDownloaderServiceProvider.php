<?php

namespace Rastographer\IgDownloader;

use Illuminate\Support\ServiceProvider;
use Rastographer\IgDownloader\Contracts\Downloader;
use Rastographer\IgDownloader\Contracts\ProxyResolver;
use Rastographer\IgDownloader\Services\DownloaderManager;
use Rastographer\IgDownloader\Services\FetchMediaAction;
use Rastographer\IgDownloader\Services\InstagramHtmlMediaExtractor;
use Rastographer\IgDownloader\Services\InstagramUrlParser;
use Rastographer\IgDownloader\Services\SafeUrlGuard;
use Rastographer\IgDownloader\Services\SignedMediaUrlFactory;
use Rastographer\IgDownloader\Services\SourceUrlStore;
use Rastographer\IgDownloader\Services\UpstreamHttpClient;
use Rastographer\IgDownloader\Support\ConfigProxyResolver;
use Rastographer\IgDownloader\Support\NullProxyResolver;

class IgDownloaderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/igdownloader.php', 'igdownloader');

        $this->app->bind(ProxyResolver::class, function ($app): ProxyResolver {
            $resolver = config('igdownloader.proxy.resolver');

            if (is_string($resolver) && $resolver !== '') {
                return $app->make($resolver);
            }

            if (! config('igdownloader.proxy.enabled', false)) {
                return new NullProxyResolver;
            }

            return new ConfigProxyResolver((array) config('igdownloader.proxy.pool', []));
        });

        $this->app->singleton(InstagramUrlParser::class);
        $this->app->singleton(InstagramHtmlMediaExtractor::class);
        $this->app->singleton(SourceUrlStore::class);
        $this->app->singleton(SafeUrlGuard::class);
        $this->app->singleton(UpstreamHttpClient::class);
        $this->app->singleton(SignedMediaUrlFactory::class);
        $this->app->singleton(FetchMediaAction::class);

        $this->app->singleton(Downloader::class, function ($app): Downloader {
            $defaultMap = (array) require __DIR__.'/../config/igdownloader.php';
            $map = array_merge(
                (array) ($defaultMap['strategies']['map'] ?? []),
                (array) config('igdownloader.strategies.map', [])
            );
            $order = array_values(array_unique(array_merge(
                (array) config('igdownloader.strategies.order', []),
                array_keys($map),
            )));
            $strategies = [];

            foreach ($order as $key) {
                $class = $map[$key] ?? null;

                if (is_string($class) && class_exists($class)) {
                    $strategies[] = $app->make($class);
                }
            }

            return new DownloaderManager(
                strategies: $strategies,
                cache: $app['cache']->store(),
                parser: $app->make(InstagramUrlParser::class),
            );
        });
    }

    public function boot(): void
    {
        if (config('igdownloader.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        $this->publishes([
            __DIR__.'/../config/igdownloader.php' => config_path('igdownloader.php'),
        ], 'igdownloader-config');
    }
}
