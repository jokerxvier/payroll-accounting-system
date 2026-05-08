<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Database\Factories\Pas\EmployeeLoanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lightweight employee loan ledger header.
 *
 * No interest schedule and no per-period amortization rows — Phase 2 only
 * tracks the running outstanding balance. Adding the next-amortization-due
 * field, schedules, or interest is a v2 concern (rules/PLAN.md:192).
 *
 * The compute path (Week 7's `ApplyEmployeeLoans` action) is read-only — it
 * previews the next amortization via `min(monthly_amortization, outstanding)`.
 * The Week-9 batch path is the only writer of `outstanding_balance_centavos`
 * and goes through `applyAmortization()` inside a transaction with
 * `SELECT ... FOR UPDATE`. The `lock_version` column gives the persistence
 * path an optimistic-lock fallback.
 *
 * @property int $id
 * @property int $employee_profile_id
 * @property string $code
 * @property int $principal_centavos
 * @property int $outstanding_balance_centavos
 * @property int $monthly_amortization_centavos
 * @property string $schedule
 * @property CarbonImmutable $started_on
 * @property ?CarbonImmutable $closed_on
 * @property int $lock_version
 * @property ?string $notes
 */
final class EmployeeLoan extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<EmployeeLoanFactory> */
    use HasFactory;

    protected $table = 'pas_employee_loans';

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return EmployeeLoanFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'employee_profile_id',
        'code',
        'principal_centavos',
        'outstanding_balance_centavos',
        'monthly_amortization_centavos',
        'schedule',
        'started_on',
        'closed_on',
        'lock_version',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'employee_profile_id' => 'integer',
            'principal_centavos' => 'integer',
            'outstanding_balance_centavos' => 'integer',
            'monthly_amortization_centavos' => 'integer',
            'lock_version' => 'integer',
            'started_on' => 'immutable_date',
            'closed_on' => 'immutable_date',
        ];
    }

    /**
     * Owning employee profile.
     *
     * @return BelongsTo<EmployeeProfile, $this>
     */
    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    /**
     * Filter to loans that are still open: not closed AND have a positive
     * outstanding balance. Both checks together guarantee the compute path
     * never tries to deduct against a fully-paid or stale-marked row.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->whereNull('closed_on')
            ->where('outstanding_balance_centavos', '>', 0);
    }

    /**
     * Apply a single amortization payment against the outstanding balance.
     *
     * Behavior:
     *  1. Clamps `$payment` to the current `outstanding_balance_centavos`
     *     (cannot pay more than is owed — the caller's preview gives the
     *     same answer).
     *  2. Decrements `outstanding_balance_centavos` by the clamped amount.
     *  3. Increments `lock_version` (optimistic-lock fallback for the
     *     Week-9 batch path).
     *  4. Sets `closed_on` to today's date if the new balance is zero.
     *  5. Persists the row.
     *  6. Returns the actual amount applied (the clamped Money).
     *
     * Called only at persistence time. The Week-7 `ApplyEmployeeLoans`
     * compute action only previews — it never calls this method.
     */
    public function applyAmortization(Money $payment): Money
    {
        $remaining = Money::fromCentavos($this->outstanding_balance_centavos);

        $applied = $payment->greaterThan($remaining) ? $remaining : $payment;

        $newBalance = $remaining->minus($applied);

        $this->outstanding_balance_centavos = $newBalance->centavos();
        $this->lock_version = $this->lock_version + 1;

        if ($newBalance->isZero()) {
            $this->closed_on = CarbonImmutable::now()->startOfDay();
        }

        $this->save();

        return $applied;
    }
}
