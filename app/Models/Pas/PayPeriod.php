<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use App\ValueObjects\PayPeriodInput;
use Carbon\CarbonImmutable;
use Database\Factories\PayPeriodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named pay period — the calendar window admins generate payroll against.
 *
 * One row per cutoff. The engine itself takes a {@see PayPeriodInput} value
 * object, not a model row — keeping the Week 6 engine deliberately decoupled
 * from this Phase 3 table. Use {@see self::toPayPeriodInput()} to bridge.
 *
 * @property int $id
 * @property string $code
 * @property string $frequency
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property CarbonImmutable|null $cutoff_date
 * @property string $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PayPeriod extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasFactory;

    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCY_SEMI_MONTHLY = 'semi_monthly';

    /** @var list<string> */
    public const FREQUENCIES = [
        self::FREQUENCY_MONTHLY,
        self::FREQUENCY_SEMI_MONTHLY,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_OPEN,
        self::STATUS_CLOSED,
    ];

    protected $table = 'pas_pay_periods';

    protected $fillable = [
        'school_id',
        'code',
        'frequency',
        'start_date',
        'end_date',
        'cutoff_date',
        'status',
    ];

    /**
     * Factory lives at Database\Factories\PayPeriodFactory, off Laravel's
     * default resolver path (App\Models\Pas namespace). Same pattern as
     * EmployeeProfile / StatutoryContribution.
     */
    protected static function newFactory(): Factory
    {
        return PayPeriodFactory::new();
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'cutoff_date' => 'immutable_date',
        ];
    }

    /** @return HasMany<PayrollRun> */
    public function payrollRuns(): HasMany
    {
        return $this->hasMany(PayrollRun::class);
    }

    /**
     * Bridge to the engine's {@see PayPeriodInput} value object. Reuses the
     * existing factories on PayPeriodInput so chronology guards (28..31 day
     * monthly, 13..16 day semi-monthly) apply uniformly.
     */
    public function toPayPeriodInput(): PayPeriodInput
    {
        return PayPeriodInput::custom(
            CarbonImmutable::parse($this->start_date->toDateString()),
            CarbonImmutable::parse($this->end_date->toDateString()),
            $this->frequency,
        );
    }

    /** @param Builder<PayPeriod> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /** @param Builder<PayPeriod> $query */
    public function scopeForFrequency(Builder $query, string $frequency): Builder
    {
        return $query->where('frequency', $frequency);
    }
}
