<?php

namespace Rastographer\IgDownloader\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Rastographer\IgDownloader\IgDownloaderServiceProvider;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $basePath = $this->resolveBasePath();
        $storagePath = dirname(__DIR__).'/tests-output/storage';

        foreach ([
            $storagePath,
            $storagePath.'/app',
            $storagePath.'/framework',
            $storagePath.'/framework/cache',
            $storagePath.'/framework/sessions',
            $storagePath.'/framework/testing',
            $storagePath.'/framework/views',
            $storagePath.'/logs',
            dirname(__DIR__).'/fixtures/views',
        ] as $directory) {
            if (! is_dir($directory)) {
                @mkdir($directory, 0777, true);
            }
        }

        putenv("VIEW_COMPILED_PATH={$storagePath}/framework/views");
        $_ENV['VIEW_COMPILED_PATH'] = $storagePath.'/framework/views';
        $_SERVER['VIEW_COMPILED_PATH'] = $storagePath.'/framework/views';

        $app = require $basePath.'/bootstrap/app.php';
        $app->useStoragePath($storagePath);
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('view.compiled', $storagePath.'/framework/views');
        $app['config']->set('view.paths', [dirname(__DIR__).'/fixtures/views']);
        $app['config']->set('igdownloader', require dirname(__DIR__).'/config/igdownloader.php');
        $app->register(IgDownloaderServiceProvider::class);
        require dirname(__DIR__).'/routes/web.php';

        return $app;
    }

    private function resolveBasePath(): string
    {
        $configured = getenv('IGDL_TEST_BASE_PATH');

        if (is_string($configured) && $configured !== '' && is_file($configured.'/bootstrap/app.php')) {
            return $configured;
        }

        $guesses = [
            dirname(__DIR__, 4),
            dirname(__DIR__, 3).'/snapigdownloader',
        ];

        foreach ($guesses as $guess) {
            if (is_file($guess.'/bootstrap/app.php')) {
                return $guess;
            }
        }

        throw new \RuntimeException('Unable to resolve the Laravel application base path for package tests.');
    }
}
