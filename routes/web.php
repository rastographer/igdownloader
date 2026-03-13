<?php

use Rastographer\IgDownloader\Http\Controllers\DownloadMediaController;
use Rastographer\IgDownloader\Http\Controllers\FetchMediaController;
use Rastographer\IgDownloader\Http\Controllers\PreviewMediaController;
use Illuminate\Support\Facades\Route;

$prefix = trim((string) config('igdownloader.routes.prefix', ''), '/');
$name = (string) config('igdownloader.routes.name', 'igdownloader.');
$middleware = (array) config('igdownloader.routes.middleware', ['web']);
$throttle = config('igdownloader.routes.throttle');

Route::middleware($middleware)
    ->prefix($prefix)
    ->as($name)
    ->group(function () use ($throttle): void {
        $fetchRoute = Route::post('/fetch', FetchMediaController::class)
            ->name('fetch');

        if (is_string($throttle) && $throttle !== '') {
            $fetchRoute->middleware($throttle);
        }

        Route::get('/img', PreviewMediaController::class)
            ->middleware('signed')
            ->name('preview');

        Route::get('/dl', DownloadMediaController::class)
            ->middleware('signed')
            ->name('download');
    });

