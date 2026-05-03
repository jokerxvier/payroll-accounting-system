<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PayrollPreviewComputeRequest;
use App\Models\Lms\Staff;
use App\Models\Pas\EmployeeProfile;
use App\Policies\PayrollPreviewPolicy;
use App\Services\Payroll\PayrollComputationService;
use App\ValueObjects\PayPeriodInput;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Real-time gross-to-net payroll preview (Phase 2, Week 8 — Stage A backend).
 *
 * Both endpoints render the same Inertia page (`payroll/preview`); the
 * `compute()` POST round-trips the same component with the computed result
 * appended to its props (no JSON endpoint per Stage A Decision 4 — Inertia
 * partial reloads are how the UI re-fetches selectively).
 *
 * The preview is read-only: it never persists, never mutates a loan balance,
 * never decrements an outstanding adjustment. The compute path delegates to
 * {@see PayrollComputationService}, which the Week-7 test pack already
 * locks in via 15 reference cases.
 *
 * Authorization is centralised on the `payroll.preview` Gate
 * (see {@see PayrollPreviewPolicy}). The FormRequest re-checks
 * via `authorize()`; the controller enforces it again at the top of each
 * action so a rogue caller wiring a route directly cannot bypass the gate.
 */
final class PayrollPreviewController extends Controller
{
    /**
     * The number of employee options surfaced in the typeahead dropdown.
     *
     * Stage A Decision 6: cap at 50 server-side; the frontend filters
     * inline. Browsers handle 50 rows of `<option>`s without measurable
     * lag, and the UX is "search-as-you-type" rather than "scroll a list".
     * If the org grows past ~200 employees and inline filtering feels slow,
     * promote this to a Wayfinder-typed search endpoint at that point.
     */
    private const EMPLOYEE_OPTION_LIMIT = 50;

    public function show(Request $request): Response
    {
        Gate::authorize('payroll.preview');

        return Inertia::render('payroll/preview', $this->showProps());
    }

    public function compute(PayrollPreviewComputeRequest $request): Response
    {
        Gate::authorize('payroll.preview');

        /** @var array{lms_staff_id: int, frequency: string, year: int, month: int} $payload */
        $payload = $request->validated();

        $current = $this->buildPeriod(
            $payload['frequency'],
            $payload['year'],
            $payload['month'],
        );
        $prior = $this->previousPeriod($payload['frequency'], $payload['year'], $payload['month']);

        $profile = EmployeeProfile::query()
            ->where('lms_staff_id', $payload['lms_staff_id'])
            ->first();

        // No payroll profile yet for the selected staff: surface an empty
        // state rather than 422. The UI prompts the user to create one.
        if ($profile === null) {
            return Inertia::render('payroll/preview', [
                ...$this->showProps(),
                'selection' => $payload,
                'noProfile' => true,
                'currentPeriod' => null,
                'priorPeriod' => null,
                'computedAt' => CarbonImmutable::now()->toIso8601String(),
            ]);
        }

        $service = app(PayrollComputationService::class);
        $currentResult = $service->compute($profile, $current);
        $priorResult = $prior !== null ? $service->compute($profile, $prior) : null;

        return Inertia::render('payroll/preview', [
            ...$this->showProps(),
            'selection' => $payload,
            'noProfile' => false,
            'currentPeriod' => $currentResult->toArray(),
            'priorPeriod' => $priorResult?->toArray(),
            'computedAt' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    /**
     * Common props for both `show()` and `compute()`. Returning the full
     * shape from one place keeps the contract single-sourced.
     *
     * @return array<string, mixed>
     */
    private function showProps(): array
    {
        $now = CarbonImmutable::now();

        return [
            'employees' => $this->loadEmployeeOptions(),
            'frequencyOptions' => [
                'monthly' => 'Monthly',
                'semi_monthly_first' => 'Semi-monthly (1st half)',
                'semi_monthly_second' => 'Semi-monthly (2nd half)',
            ],
            'defaultPeriod' => [
                'frequency' => 'monthly',
                'year' => (int) $now->year,
                'month' => (int) $now->month,
            ],
            // Default-null on first load; `compute()` overwrites these.
            'selection' => null,
            'noProfile' => null,
            'currentPeriod' => null,
            'priorPeriod' => null,
            'computedAt' => null,
        ];
    }

    /**
     * Build the typeahead options surfaced to the frontend.
     *
     * `EmployeeProfile` does NOT have an Eloquent BelongsTo to `Staff` (the
     * cross-connection ban makes BelongsTo unsafe; see the
     * `EloquentEmployeeRepository` class docblock). So we use the same
     * two-query strategy as `paginate()`:
     *
     *   1. Page profiles on the default (writable) connection.
     *   2. Batch-load staff identity on the LMS connection by `whereIn(id, …)`.
     *
     * Two queries, regardless of cap. No N+1, no cross-connection JOIN.
     *
     * @return list<array{lms_staff_id: int, full_name: string, employment_type: ?string, has_profile: bool}>
     */
    private function loadEmployeeOptions(): array
    {
        $profiles = EmployeeProfile::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->limit(self::EMPLOYEE_OPTION_LIMIT)
            ->get(['id', 'lms_staff_id', 'employment_classification']);

        if ($profiles->isEmpty()) {
            return [];
        }

        $staffIds = $profiles->pluck('lms_staff_id')->all();

        /** @var array<int, string> $namesById */
        $namesById = Staff::query()
            ->whereIn('id', $staffIds)
            ->pluck('full_name', 'id')
            ->all();

        return $profiles
            ->map(static fn (EmployeeProfile $p): array => [
                'lms_staff_id' => (int) $p->lms_staff_id,
                'full_name' => $namesById[$p->lms_staff_id] ?? '(unknown staff)',
                'employment_type' => $p->employment_classification,
                'has_profile' => true,
            ])
            ->sortBy('full_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Map the validated frequency string onto the matching
     * {@see PayPeriodInput} factory.
     *
     * @throws InvalidArgumentException When `$frequency` is not one of the
     *                                  three preview-facing values. The
     *                                  FormRequest already constrains this,
     *                                  but the throw keeps the helper
     *                                  total in case it is reused.
     */
    private function buildPeriod(string $frequency, int $year, int $month): PayPeriodInput
    {
        return match ($frequency) {
            'monthly' => PayPeriodInput::monthly($year, $month),
            'semi_monthly_first' => PayPeriodInput::semiMonthlyFirst($year, $month),
            'semi_monthly_second' => PayPeriodInput::semiMonthlySecond($year, $month),
            default => throw new InvalidArgumentException(
                "PayrollPreviewController::buildPeriod received unsupported frequency '{$frequency}'."
            ),
        };
    }

    /**
     * Resolve the period that immediately precedes the selected one, used
     * for the side-by-side delta comparison (Stage A Decision 7).
     *
     *   - monthly                : previous calendar month (Jan → Dec of prior year).
     *   - semi_monthly_first     : prior month's `semiMonthlySecond`.
     *   - semi_monthly_second    : same month's `semiMonthlyFirst`.
     *
     * Returns `null` when the resulting year would fall below 2020 — the
     * lower bound of the seeded statutory rates. The UI hides the "prior"
     * column in that case.
     */
    private function previousPeriod(string $frequency, int $year, int $month): ?PayPeriodInput
    {
        return match ($frequency) {
            'monthly' => $this->previousMonthlyPeriod($year, $month),
            'semi_monthly_first' => $this->previousSemiMonthlyFirstPeriod($year, $month),
            'semi_monthly_second' => PayPeriodInput::semiMonthlyFirst($year, $month),
            default => null,
        };
    }

    private function previousMonthlyPeriod(int $year, int $month): ?PayPeriodInput
    {
        if ($month === 1) {
            $priorYear = $year - 1;
            if ($priorYear < 2020) {
                return null;
            }

            return PayPeriodInput::monthly($priorYear, 12);
        }

        return PayPeriodInput::monthly($year, $month - 1);
    }

    private function previousSemiMonthlyFirstPeriod(int $year, int $month): ?PayPeriodInput
    {
        if ($month === 1) {
            $priorYear = $year - 1;
            if ($priorYear < 2020) {
                return null;
            }

            return PayPeriodInput::semiMonthlySecond($priorYear, 12);
        }

        return PayPeriodInput::semiMonthlySecond($year, $month - 1);
    }
}
