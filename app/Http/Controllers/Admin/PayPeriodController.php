<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pas\PayPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin surface for pay periods (Phase 3 Week 9).
 *
 * Scope is intentionally minimal — index + create + store. Edit/show land
 * later; for now an admin creates a period in `open` status and immediately
 * uses it to generate a payroll run.
 */
final class PayPeriodController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', PayPeriod::class);

        $periods = PayPeriod::query()
            ->orderByDesc('start_date')
            ->limit(100)
            ->get()
            ->map(fn (PayPeriod $p): array => [
                'id' => $p->id,
                'code' => $p->code,
                'frequency' => $p->frequency,
                'start_date' => $p->start_date->toDateString(),
                'end_date' => $p->end_date->toDateString(),
                'cutoff_date' => $p->cutoff_date?->toDateString(),
                'status' => $p->status,
            ]);

        return Inertia::render('admin/pay-periods/index', [
            'periods' => $periods->all(),
            'can' => [
                'create' => Gate::allows('create', PayPeriod::class),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', PayPeriod::class);

        return Inertia::render('admin/pay-periods/create');
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', PayPeriod::class);

        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:32', 'unique:pas_pay_periods,code'],
            'frequency' => ['required', 'string', Rule::in(PayPeriod::FREQUENCIES)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'cutoff_date' => ['nullable', 'date'],
            'status' => ['required', 'string', Rule::in(PayPeriod::STATUSES)],
        ]);

        // Cross-field invariant: the day-span must match the chosen frequency.
        // Mirrors PayPeriodInput::custom() so a row that passes this validator
        // can always bridge to the engine's value object.
        $validator->after(function ($v) use ($request): void {
            if ($v->errors()->hasAny(['frequency', 'start_date', 'end_date'])) {
                return;
            }
            $start = CarbonImmutable::parse((string) $request->input('start_date'))->startOfDay();
            $end = CarbonImmutable::parse((string) $request->input('end_date'))->startOfDay();
            $days = (int) $start->diffInDays($end) + 1;
            $frequency = (string) $request->input('frequency');

            if ($frequency === PayPeriod::FREQUENCY_MONTHLY && ($days < 28 || $days > 31)) {
                $v->errors()->add('end_date', "A monthly pay period must span 28–31 days; got {$days}.");
            }
            if ($frequency === PayPeriod::FREQUENCY_SEMI_MONTHLY && ($days < 13 || $days > 16)) {
                $v->errors()->add('end_date', "A semi-monthly pay period must span 13–16 days; got {$days}.");
            }
        });

        $data = $validator->validate();

        // Coerce dates to start-of-day immutables for consistency with the
        // engine's PayPeriodInput value object.
        $data['start_date'] = CarbonImmutable::parse($data['start_date'])->startOfDay();
        $data['end_date'] = CarbonImmutable::parse($data['end_date'])->startOfDay();
        if (! empty($data['cutoff_date'])) {
            $data['cutoff_date'] = CarbonImmutable::parse($data['cutoff_date'])->startOfDay();
        }

        PayPeriod::query()->create($data);

        return redirect()
            ->route('admin.pay-periods.index')
            ->with('success', sprintf('Pay period %s created.', $data['code']));
    }
}
