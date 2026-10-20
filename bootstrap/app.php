<?php

use App\Http\Middleware\EnsureBusinessMember;
use App\Http\Middleware\EnsureTwoFactorWhenRequired;
use App\Http\Middleware\EnsureUnlocked;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveCurrentBusiness;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Tenancy\TenancyViolation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Render terminates TLS and forwards the request over plain HTTP, so the
        // forwarded headers are what tell Laravel the visitor is on HTTPS.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            SecurityHeaders::class,
            HandleAppearance::class,
            ResolveCurrentBusiness::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The tenant must be known before route model binding runs, so bound
        // records (e.g. /staff/{membership}) are looked up inside the right business.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveCurrentBusiness::class,
        );

        $middleware->alias([
            'member' => EnsureBusinessMember::class,
            'two-factor.required' => EnsureTwoFactorWhenRequired::class,
            'unlocked' => EnsureUnlocked::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A tenancy violation is a bug; never reveal details, and treat it like a missing record.
        $exceptions->render(fn (TenancyViolation $e) => abort(404));
    })->create();
