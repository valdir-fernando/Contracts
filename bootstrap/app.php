<?php

use App\Application\Exceptions\AccessDenied;
use App\Application\Exceptions\ConcurrencyConflict;
use App\Application\Exceptions\InputRejected;
use App\Application\Exceptions\RecordNotFound;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrimInputStrings;
use App\Http\Middleware\TrustedReverseProxies;
use App\Infrastructure\Http\ApplicationErrors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Middleware\TrustProxies;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(TrustProxies::class, TrustedReverseProxies::class);
        $middleware->replace(TrimStrings::class, TrimInputStrings::class);
        $middleware->web(append: [SecurityHeaders::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        foreach ([AccessDenied::class, ConcurrencyConflict::class, InputRejected::class, RecordNotFound::class] as $failure) {
            $exceptions->map($failure, fn (Throwable $error) => ApplicationErrors::toHttp($error));
        }
    })->create();
