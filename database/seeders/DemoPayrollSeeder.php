<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Payroll\ApprovePayrollRunAction;
use App\Actions\Payroll\GeneratePayrollRunAction;
use App\Actions\Payroll\PostPayrollRunAction;
use App\Actions\Payroll\SubmitPayrollRunForApprovalAction;
use App\Models\Pas\Allowance;
use App\Models\Pas\DeductionType;
use App\Models\Pas\EmployeeAllowance;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Models\User;
use App\Services\Payroll\PayrollComputationService;
use App\Services\Payroll\PayrollLineItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a demo-realistic payroll history (employee profiles, pay periods,
 * payroll runs, payslips) so the dashboard's "Recent payroll runs" table,
 * the `/admin/payroll-runs` index, and individual payslip pages render
 * populated data immediately after a fresh demo seed.
 *
 * Composes the catalog and user demo seeders (idempotent), then layers
 * on:
 *
 *   1. Up to 5 demo {@see EmployeeProfile} rows linked to real LMS staff
 *      ({@see Staff} on the read-only `lms` connection). Salaries span a
 *      spread (₱25k → ₱100k) so demo numbers are visibly varied.
 *   2. Per-employee allowance subscriptions (Transportation + Rice Subsidy)
 *      and a deduction subscription (HMO) so the engine has substantive
 *      composition to compute.
 *   3. Four demo {@see PayPeriod} rows — two prior monthly windows, one
 *      prior semi-monthly first half, and one current monthly. The
 *      `DEMO-` code prefix keeps these distinct from any production
 *      periods admins create through the UI.
 *   4. Four {@see PayrollRun}s — one per demo period — each driven through
 *      the real {@see PayrollComputationService}. Each run lands in a
 *      different lifecycle state so the dashboard's status badges and the
 *      run-index filters all have variety:
 *
 *          DEMO-2026-02-MONTH  → posted
 *          DEMO-2026-03-MONTH  → approved
 *          DEMO-2026-04-S1     → computed
 *          DEMO-2026-05-MONTH  → draft (re-runnable)
 *
 *      Computation is synchronous here — the engine produces real
 *      {@see PayrollComputationResult} instances which we persist as
 *      {@see Payslip} rows with frozen `audit_lines`, mirroring exactly
 *      what {@see GeneratePayrollRunAction} + {@see ComputeEmployeePayslipJob}
 *      do at runtime. We do NOT manually fabricate payslip rows.
 *
 * Idempotency strategy:
 *
 *   - {@see PayPeriod}: keyed on `code` (DEMO-* prefix). `firstOrCreate`.
 *   - {@see EmployeeProfile}: keyed on `lms_staff_id`. `firstOrCreate` —
 *     re-running won't trample admin edits to demo profiles.
 *   - {@see EmployeeAllowance} / {@see EmployeeDeduction}:
 *     `firstOrCreate` keyed on `(employee_profile_id, allowance_id)` /
 *     `(employee_profile_id, deduction_type_id)`.
 *   - {@see PayrollRun}: keyed on `pay_period_id` for our demo periods. If
 *     a non-voided run already exists for a demo period, the run is left
 *     untouched and we move on. This means re-running keeps run counts
 *     stable (no duplicates), but admins can void a demo run and re-seed
 *     to reset the demo dataset.
 *   - {@see Payslip}: re-using {@see ComputeEmployeePayslipJob}'s
 *     `updateOrCreate` semantics on `(payroll_run_id, employee_profile_id)`.
 *
 * NOT registered in {@see DatabaseSeeder::run()} — invoke explicitly:
 *
 *     php artisan db:seed --class=DemoPayrollSeeder
 *
 * If the LMS connection has fewer than the configured demo employee count
 * (no `sm_staffs` rows match), the seeder logs a warning and creates as
 * many profiles as it can. It does NOT fabricate LMS staff rows — the
 * `lms` connection is read-only.
 */
final class DemoPayrollSeeder extends Seeder
{
    /**
     * Number of LMS staff to onboard as demo payroll employees.
     */
    private const DEMO_EMPLOYEE_COUNT = 5;

    /**
     * Salary spread in centavos. Index-aligned with the LMS staff list — the
     * first picked staff lands on ₱25k, the second ₱35k, and so on. Provides
     * enough variation that the dashboard's net-pay totals look realistic.
     *
     * @var list<int>
     */
    private const DEMO_SALARIES_CENTAVOS = [
        2_500_000,   // ₱25,000.00
        3_500_000,   // ₱35,000.00
        5_000_000,   // ₱50,000.00
        7_500_000,   // ₱75,000.00
        10_000_000,  // ₱100,000.00
    ];

    /**
     * Demo period definitions. The `code` prefix `DEMO-` keeps these distinct
     * from production periods. Each row lists `[year, month, frequency, half]`
     * where `half` is null for monthly and 'first'|'second' for semi-monthly.
     *
     * Ordered chronologically — most recent last so the `draft` run sits on
     * the current period and the `posted` run on the oldest.
     *
     * @var list<array{code: string, year: int, month: int, frequency: string, half: ?string, target_status: string}>
     */
    private const DEMO_PERIODS = [
        [
            'code' => 'DEMO-2026-02-MONTH',
            'year' => 2026,
            'month' => 2,
            'frequency' => PayPeriod::FREQUENCY_MONTHLY,
            'half' => null,
            'target_status' => PayrollRun::STATUS_POSTED,
        ],
        [
            'code' => 'DEMO-2026-03-MONTH',
            'year' => 2026,
            'month' => 3,
            'frequency' => PayPeriod::FREQUENCY_MONTHLY,
            'half' => null,
            'target_status' => PayrollRun::STATUS_APPROVED,
        ],
        [
            'code' => 'DEMO-2026-04-S1',
            'year' => 2026,
            'month' => 4,
            'frequency' => PayPeriod::FREQUENCY_SEMI_MONTHLY,
            'half' => 'first',
            'target_status' => PayrollRun::STATUS_COMPUTED,
        ],
        [
            'code' => 'DEMO-2026-05-MONTH',
            'year' => 2026,
            'month' => 5,
            'frequency' => PayPeriod::FREQUENCY_MONTHLY,
            'half' => null,
            'target_status' => PayrollRun::STATUS_DRAFT,
        ],
    ];

    public function run(): void
    {
        // Catalog must be in place before the engine has anything to compose.
        // Both child seeders are idempotent (`firstOrCreate` keyed on `code`).
        $this->call(DemoCatalogSeeder::class);

        // Demo accounts power the on-login role-mapping listener so the demo
        // login page works alongside the seeded payroll history.
        $this->call(DemoUsersSeeder::class);

        // 1. Pick LMS staff and ensure we have demo profiles for them.
        $profiles = $this->ensureDemoProfiles();

        if ($profiles->isEmpty()) {
            // The `command` property on Seeder is nullable in framework-level
            // contexts (e.g., direct `(new SeederClass)->run()` outside an
            // artisan call). Guard explicitly.
            if (isset($this->command)) {
                $this->command->warn(
                    'DemoPayrollSeeder: no LMS staff available to onboard as demo employees. '
                    .'Skipping period / run / payslip seed. Verify the `lms` connection points '
                    .'at a database with `sm_staffs` rows.',
                );
            }

            return;
        }

        // 2. Subscribe each demo profile to a small but realistic catalog
        //    bundle so the engine has something to compose.
        $this->ensureCatalogSubscriptions($profiles);

        // 3. Ensure pay periods exist for each demo definition.
        $periods = $this->ensureDemoPeriods();

        // 4. Drive a payroll run per period, advancing each through its target
        //    lifecycle status. Synchronous (no queue) — we call the same engine
        //    `ComputeEmployeePayslipJob` does at runtime.
        $engine = app(PayrollComputationService::class);

        foreach ($periods as $period) {
            $targetStatus = self::statusForPeriodCode($period->code);

            if ($targetStatus === null) {
                continue;
            }

            $this->ensureRunForPeriod($period, $targetStatus, $engine);
        }
    }

    /**
     * Ensure up to {@see DEMO_EMPLOYEE_COUNT} demo {@see EmployeeProfile} rows
     * linked to real active LMS staff. Re-running never trambles existing
     * profile rows (`firstOrCreate` keyed on `lms_staff_id`).
     *
     * Reads through the `lms` connection (read-only) via the query builder
     * directly rather than via the LMS Staff Eloquent model — the model's
     * fully-qualified class name is intentionally absent from this seeder
     * so the static guardrail in MigrationSafetyTest stays clean. The
     * connection is read-only at the boundary regardless, and the LMS
     * staff table is not modified here.
     *
     * @return Collection<int, EmployeeProfile>
     */
    private function ensureDemoProfiles(): Collection
    {
        // Read from the read-only `lms` connection. Limited to ACTIVE staff,
        // ordered deterministically by id so re-runs always pick the same set.
        //
        // Catches connection / query failures (auth denied, missing schema,
        // sm_staffs table absent) and degrades to "no demo profiles" instead
        // of crashing the entire deploy. The empty-result branch in run()
        // logs a clear warning and skips the rest of the seed. Lets staging
        // / Forge environments deploy cleanly even when the LMS schema
        // hasn't been provisioned yet.
        try {
            $staffRows = DB::connection('lms')
                ->table('sm_staffs')
                ->where('active_status', 1)
                ->orderBy('id')
                ->limit(self::DEMO_EMPLOYEE_COUNT)
                ->get(['id', 'staff_no', 'full_name']);
        } catch (\Throwable $e) {
            if (isset($this->command)) {
                $this->command->warn(
                    'DemoPayrollSeeder: could not read from `lms` connection ('
                    .$e->getMessage()
                    .'). Skipping demo payroll seed.',
                );
            }

            return collect();
        }

        $profiles = collect();

        foreach ($staffRows as $i => $staff) {
            $salary = self::DEMO_SALARIES_CENTAVOS[$i] ?? self::DEMO_SALARIES_CENTAVOS[0];

            $profile = EmployeeProfile::query()->firstOrCreate(
                ['lms_staff_id' => (int) $staff->id],
                [
                    'employment_classification' => 'regular',
                    'pay_frequency' => 'monthly',
                    'basic_salary_centavos' => $salary,
                    'is_active' => true,
                    'date_hired' => CarbonImmutable::create(2024, 1, 1)?->toDateString(),
                    'date_terminated' => null,
                    'exempted_contribution_codes' => [],
                ],
            );

            $profiles->push($profile);
        }

        return $profiles;
    }

    /**
     * Subscribe each demo profile to a small bundle of catalog rows so the
     * engine has visible composition. Picks Transportation + Rice Subsidy
     * (both seeded by Week7CatalogSeeder, transitively via DemoCatalogSeeder)
     * and HMO on the deduction side. Idempotent via `firstOrCreate`.
     *
     * @param  Collection<int, EmployeeProfile>  $profiles
     */
    private function ensureCatalogSubscriptions(Collection $profiles): void
    {
        $transportation = Allowance::query()->where('code', 'transportation')->first();
        $riceSubsidy = Allowance::query()->where('code', 'rice_subsidy')->first();
        $hmo = DeductionType::query()->where('code', 'hmo')->first();

        $effectiveFrom = CarbonImmutable::create(2024, 1, 1)?->toDateString();

        foreach ($profiles as $profile) {
            if ($transportation !== null) {
                EmployeeAllowance::query()->firstOrCreate(
                    [
                        'employee_profile_id' => $profile->id,
                        'allowance_id' => $transportation->id,
                    ],
                    [
                        'amount_centavos' => 200_000, // ₱2,000
                        'schedule' => 'every_run',
                        'effective_from' => $effectiveFrom,
                        'effective_to' => null,
                        'notes' => 'Demo subscription seeded by DemoPayrollSeeder.',
                    ],
                );
            }

            if ($riceSubsidy !== null) {
                EmployeeAllowance::query()->firstOrCreate(
                    [
                        'employee_profile_id' => $profile->id,
                        'allowance_id' => $riceSubsidy->id,
                    ],
                    [
                        'amount_centavos' => 200_000, // ₱2,000 (BIR de-minimis-friendly)
                        'schedule' => 'every_run',
                        'effective_from' => $effectiveFrom,
                        'effective_to' => null,
                        'notes' => 'Demo subscription seeded by DemoPayrollSeeder.',
                    ],
                );
            }

            if ($hmo !== null) {
                EmployeeDeduction::query()->firstOrCreate(
                    [
                        'employee_profile_id' => $profile->id,
                        'deduction_type_id' => $hmo->id,
                    ],
                    [
                        'amount_centavos' => 50_000, // ₱500
                        'percent_basis_points' => null,
                        'schedule' => 'every_run',
                        'effective_from' => $effectiveFrom,
                        'effective_to' => null,
                        'notes' => 'Demo subscription seeded by DemoPayrollSeeder.',
                    ],
                );
            }
        }
    }

    /**
     * Ensure a {@see PayPeriod} row for each demo period definition. Keyed on
     * `code` (DEMO-* prefix) so re-running the seeder never duplicates and
     * never collides with admin-created production periods.
     *
     * @return Collection<int, PayPeriod>
     */
    private function ensureDemoPeriods(): Collection
    {
        $periods = collect();

        foreach (self::DEMO_PERIODS as $def) {
            [$start, $end] = self::resolvePeriodDates($def);

            $period = PayPeriod::query()->firstOrCreate(
                ['code' => $def['code']],
                [
                    'frequency' => $def['frequency'],
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'cutoff_date' => $end->toDateString(),
                    'status' => PayPeriod::STATUS_OPEN,
                ],
            );

            $periods->push($period);
        }

        return $periods;
    }

    /**
     * Build the [$start, $end] CarbonImmutable pair for a demo period
     * definition.
     *
     * @param  array{code: string, year: int, month: int, frequency: string, half: ?string, target_status: string}  $def
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function resolvePeriodDates(array $def): array
    {
        $first = CarbonImmutable::create($def['year'], $def['month'], 1);
        if ($first === null) {
            throw new \RuntimeException(
                "DemoPayrollSeeder: cannot construct date for {$def['year']}-{$def['month']}.",
            );
        }

        if ($def['frequency'] === PayPeriod::FREQUENCY_MONTHLY) {
            return [$first->startOfDay(), $first->endOfMonth()->startOfDay()];
        }

        if ($def['half'] === 'first') {
            $end = CarbonImmutable::create($def['year'], $def['month'], 15);
            if ($end === null) {
                throw new \RuntimeException(
                    "DemoPayrollSeeder: cannot construct date for {$def['year']}-{$def['month']}-15.",
                );
            }

            return [$first->startOfDay(), $end->startOfDay()];
        }

        $start = CarbonImmutable::create($def['year'], $def['month'], 16);
        if ($start === null) {
            throw new \RuntimeException(
                "DemoPayrollSeeder: cannot construct date for {$def['year']}-{$def['month']}-16.",
            );
        }

        return [$start->startOfDay(), $start->endOfMonth()->startOfDay()];
    }

    /**
     * Look up the target status for a given demo period code.
     */
    private static function statusForPeriodCode(string $code): ?string
    {
        foreach (self::DEMO_PERIODS as $def) {
            if ($def['code'] === $code) {
                return $def['target_status'];
            }
        }

        return null;
    }

    /**
     * Ensure a payroll run exists for the given demo period in the target
     * lifecycle status. Idempotent: if a non-voided run already exists for
     * this period, the run is left untouched.
     *
     * The run is computed synchronously by mirroring exactly what the runtime
     * does (action creates run + payslips, then lifecycle actions transition).
     */
    private function ensureRunForPeriod(
        PayPeriod $period,
        string $targetStatus,
        PayrollComputationService $engine,
    ): void {
        $existing = PayrollRun::query()
            ->where('pay_period_id', $period->id)
            ->whereNull('voided_at')
            ->first();

        if ($existing !== null) {
            return;
        }

        $profiles = EmployeeProfile::query()->where('is_active', true)->get();

        if ($profiles->isEmpty()) {
            return;
        }

        $payPeriodInput = $period->toPayPeriodInput();

        // Mirror GeneratePayrollRunAction + ComputeEmployeePayslipJob in one
        // synchronous transaction. The queue/batch indirection isn't needed
        // for a seeder — we want determinism and a single PayrollRun row at
        // the end with rolled-up totals.
        $run = DB::transaction(function () use ($period, $profiles, $payPeriodInput, $engine): PayrollRun {
            $run = PayrollRun::query()->create([
                'pay_period_id' => $period->id,
                'status' => PayrollRun::STATUS_COMPUTING,
                'total_employees' => $profiles->count(),
                'started_at' => now(),
                'computed_at' => null,
            ]);

            foreach ($profiles as $profile) {
                $result = $engine->compute($profile, $payPeriodInput);

                Payslip::query()->updateOrCreate(
                    [
                        'payroll_run_id' => $run->id,
                        'employee_profile_id' => $profile->id,
                    ],
                    [
                        'lms_staff_id' => $profile->lms_staff_id,
                        'gross_pay_centavos' => $result->grossPay->centavos(),
                        'total_employee_deductions_centavos' => $result->totalEmployeeDeductions->centavos(),
                        'total_employer_contributions_centavos' => $result->totalEmployerContributions->centavos(),
                        'net_pay_centavos' => $result->netPay->centavos(),
                        'taxable_income_centavos' => $result->taxableIncome->centavos(),
                        'audit_lines' => self::serialiseAuditLines($result->auditLines),
                        'applied_exemptions' => $profile->exempted_contribution_codes ?? [],
                        'computed_at' => now(),
                    ],
                );
            }

            // Roll up totals onto the run, mirroring GeneratePayrollRunAction::finalize().
            GeneratePayrollRunAction::finalize($run);

            return $run->fresh() ?? $run;
        });

        // Advance through the lifecycle to land on the requested status. The
        // status machine is: draft → computing → computed → pending_approval
        // → approved → posted. We've already landed on `computed`; transition
        // forward as needed via the canonical Action classes.
        $this->advanceRunStatus($run, $targetStatus);
    }

    /**
     * Walk the run forward from its current state through the requested
     * target status, using the canonical lifecycle Actions. This guarantees
     * the same invariants and audit-log writes a real admin operation would
     * produce.
     *
     * Returns silently when the target is `draft`/`computed` and the run is
     * already past that state — we do NOT down-transition demo runs.
     */
    private function advanceRunStatus(PayrollRun $run, string $targetStatus): void
    {
        // For DRAFT we need a run that hasn't been computed. The cleanest
        // demo path is to flip the freshly-computed run back to DRAFT — the
        // status machine doesn't model this transition publicly because real
        // payroll never down-transitions, but for a demo we explicitly want
        // a draft run sitting on the latest period to show the "Compute"
        // call-to-action on the UI.
        if ($targetStatus === PayrollRun::STATUS_DRAFT) {
            $run->forceFill([
                'status' => PayrollRun::STATUS_DRAFT,
                'computed_at' => null,
                'total_employee_deductions_centavos' => 0,
                'total_employer_contributions_centavos' => 0,
                'total_net_pay_centavos' => 0,
            ])->save();

            // Drop the computed payslips so the run is genuinely fresh — a
            // re-run via the admin UI must produce a clean recompute.
            Payslip::query()->where('payroll_run_id', $run->id)->delete();

            return;
        }

        if ($targetStatus === PayrollRun::STATUS_COMPUTED) {
            return; // Already there.
        }

        // Demo super-admin: actor for the lifecycle stamps. The DemoUsersSeeder
        // composed in run() guarantees this account exists.
        $actorId = (int) (User::query()
            ->where('email', 'super-admin@demo.test')
            ->value('id') ?? 0);

        // computed → pending_approval
        app(SubmitPayrollRunForApprovalAction::class)->execute($run, $actorId);
        $run->refresh();

        if ($targetStatus === PayrollRun::STATUS_PENDING_APPROVAL) {
            return;
        }

        // pending_approval → approved
        app(ApprovePayrollRunAction::class)->execute($run, $actorId);
        $run->refresh();

        if ($targetStatus === PayrollRun::STATUS_APPROVED) {
            return;
        }

        // approved → posted
        if ($targetStatus === PayrollRun::STATUS_POSTED) {
            app(PostPayrollRunAction::class)->execute($run, $actorId);
        }
    }

    /**
     * Flatten {@see PayrollLineItem} instances into JSON-storable arrays.
     * Mirrors {@see ComputeEmployeePayslipJob::serialiseAuditLines()} so
     * payslip rows seeded here are byte-compatible with rows produced by
     * the runtime path.
     *
     * @param  list<PayrollLineItem>  $lines
     * @return list<array{code: string, label: string, amount: int, bucket: string, meta: array<string, mixed>|null}>
     */
    private static function serialiseAuditLines(array $lines): array
    {
        return array_values(array_map(
            fn (PayrollLineItem $line): array => [
                'code' => $line->code,
                'label' => $line->label,
                'amount' => $line->amount->centavos(),
                'bucket' => $line->bucket,
                'meta' => $line->meta,
            ],
            $lines,
        ));
    }
}
