<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render branded Inertia error pages for HTML requests on the codes
        // we have a dedicated React page for. JSON / API responses keep
        // Laravel's default behavior. Three bypass conditions:
        //   1. `local` / `testing` env — keeps Whoops + framework error
        //      pages for development; existing feature tests can still
        //      assert assertForbidden() etc.
        //   2. APP_DEBUG=true — operators flipping debug on staging /
        //      production to troubleshoot a 500 need to see the stack
        //      trace, not the friendly page.
        //   3. expectsJson() — APIs / Inertia partial reloads expect
        //      structured responses, not a rendered Inertia page.
        $exceptions->respond(function (Response $response, Throwable $exception, $request) {
            if (app()->environment('local', 'testing')) {
                return $response;
            }

            if (config('app.debug')) {
                return $response;
            }

            if ($request->expectsJson()) {
                return $response;
            }

            $status = $response->getStatusCode();

            if (in_array($status, [403, 404, 419, 500, 503], true)) {
                return Inertia::render("errors/{$status}", [
                    'status' => $status,
                ])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
