<?php

use App\Http\Middleware\ApplyTenantOverride;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\Middleware\AuthenticateSession;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Inertia\Inertia;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;
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
            // Phase E preview — re-pivots the tenant from the super-admin's
            // session-stored override (the in-app switcher). Must run BEFORE
            // HandleInertiaRequests because Inertia calls share() at the
            // start of its handle() (before $next), so the currentTenant
            // prop is captured at THAT point. Spatie resolves the tenant
            // at service-provider boot (before session is available), so
            // this middleware re-resolves once session + auth are ready.
            //
            // Execution order vs SubstituteBindings is enforced explicitly
            // in priority() below — without it, ApplyTenantOverride runs
            // AFTER SubstituteBindings (by virtue of being appended), so
            // route-model-binding queries fire while Tenant::current() is
            // still the boot-time default tenant. Any model that uses
            // BelongsToTenant (PayrollRun, PayPeriod, Payslip…) would 404
            // when the platform admin's session override pivots them to a
            // non-default school.
            ApplyTenantOverride::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Phase C.1 — resolves the active tenant via SchoolTenantFinder
            // and runs the SwitchLmsConnection task. Appended so it runs
            // after session/cookie middleware in the framework's web group.
            NeedsTenant::class,
        ]);

        // Middleware execution priority: ApplyTenantOverride MUST run
        // before SubstituteBindings so route-model-binding queries see the
        // correct Tenant::current(). Mirrors Laravel's default priority
        // list with ApplyTenantOverride inserted between AuthenticateSession
        // and SubstituteBindings — every other entry is the framework
        // default; reordering them would break unrelated middleware.
        $middleware->priority([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            HandlePrecognitiveRequests::class,
            AuthenticateSession::class,
            ApplyTenantOverride::class,
            SubstituteBindings::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
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
