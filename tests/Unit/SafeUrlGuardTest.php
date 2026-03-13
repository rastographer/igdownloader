<?php

uses(Rastographer\IgDownloader\Tests\TestCase::class);

use Rastographer\IgDownloader\Services\SafeUrlGuard;

it('allows configured hosts and subdomains', function () {
    config()->set('igdownloader.security.allowed_hosts', ['cdninstagram.com', 'fbcdn.net']);

    $guard = app(SafeUrlGuard::class);

    expect($guard->allows('https://cdninstagram.com/media/file.jpg'))->toBeTrue()
        ->and($guard->allows('https://video.fbcdn.net/media/file.mp4'))->toBeTrue();
});

it('rejects disallowed hosts and invalid schemes', function () {
    config()->set('igdownloader.security.allowed_hosts', ['cdninstagram.com']);

    $guard = app(SafeUrlGuard::class);

    expect($guard->allows('https://example.com/media/file.jpg'))->toBeFalse()
        ->and($guard->allows('ftp://cdninstagram.com/media/file.jpg'))->toBeFalse();
});
