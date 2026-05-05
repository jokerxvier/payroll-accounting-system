<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Payroll\SeedDemoSalariesAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Dev-only admin actions for seeding demo data quickly during development
 * and demos. Every endpoint:
 *   - is gated to super-admin via the `dev.seed-demo-salaries` Gate, and
 *   - refuses to run when `app.env === production` (defense in depth).
 *
 * The controller is thin; canonical logic lives in dedicated Action
 * classes (e.g. {@see SeedDemoSalariesAction}) shared with the
 * matching artisan command.
 */
final class DevSeedController extends Controller
{
    public function seedDemoSalaries(SeedDemoSalariesAction $action): RedirectResponse
    {
        Gate::authorize('dev.seed-demo-salaries');

        if (app()->environment('production')) {
            abort(403, 'Demo seeding is disabled in production.');
        }

        $touched = $action->execute();

        return back()->with(
            'success',
            sprintf('Seeded demo salaries on %d profile%s.', $touched, $touched === 1 ? '' : 's'),
        );
    }
}
