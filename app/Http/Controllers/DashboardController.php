<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard MVP — replaces the Phase 1 closure route.
 *
 * The controller is deliberately thin: validation is moot (no inputs other
 * than the authenticated user), authorization is the route's `auth + verified`
 * middleware (every authenticated user sees a dashboard), and the rendered
 * shape is owned by {@see DashboardService}.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $service,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        // The middleware stack guarantees an authenticated user, but a defensive
        // 401 is preferable to a 500 if a future refactor opens this route up.
        abort_if($user === null, 401);

        $data = $this->service->assemble($user);

        return Inertia::render('dashboard', $data->toArray());
    }
}
