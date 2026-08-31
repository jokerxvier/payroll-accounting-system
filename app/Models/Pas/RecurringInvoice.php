<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\Pas\RecurringInvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A standing instruction to raise the same invoice on a cadence.
 *
 * Holds template lines rather than pointing at a fee structure, because the
 * LMS fee tables are empty and who owns a fee schedule is still an open
 * question. Editing the template changes future invoices only — the ones
 * already raised are documents and are not retrospectively rewritten.
 *
 * `day_of_month` is stored **intent**, 1–31, and clamped where it is used. A
 * schedule set to the 31st must still bill the 31st in March after February
 * clamped it to the 28th, which is exactly what iterating with `addMonth()`
 * gets wrong: the day sticks once clamped.
 *
 * @property int $id
 * @property int $school_id
 * @property string $name
 * @property string $type
 * @property int $contact_id
 * @property ?int $lms_student_id
 * @property ?string $student_name
 * @property ?string $reference
 * @property bool $is_vat_inclusive
 * @property ?string $notes
 * @property ?string $terms
 * @property string $frequency
 * @property int $day_of_month
 * @property CarbonImmutable $starts_on
 * @property ?CarbonImmutable $ends_on
 * @property CarbonImmutable $next_run_on
 * @property ?int $due_days
 * @property bool $is_active
 * @property ?CarbonImmutable $last_generated_on
 * @property int $generated_count
 * @property ?string $last_error
 * @property ?CarbonImmutable $last_error_at
 * @property-read Collection<int, RecurringInvoiceLine> $lines
 * @property-read ?Contact $contact
 */
final class RecurringInvoice extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<RecurringInvoiceFactory> */
    use HasFactory;

    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCY_QUARTERLY = 'quarterly';

    public const FREQUENCY_ANNUALLY = 'annually';

    /** @var list<string> */
    public const FREQUENCIES = [
        self::FREQUENCY_MONTHLY,
        self::FREQUENCY_QUARTERLY,
        self::FREQUENCY_ANNUALLY,
    ];

    /** How many months each cadence advances. */
    private const MONTHS_PER_PERIOD = [
        self::FREQUENCY_MONTHLY => 1,
        self::FREQUENCY_QUARTERLY => 3,
        self::FREQUENCY_ANNUALLY => 12,
    ];

    protected $table = 'pas_recurring_invoices';

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'name',
        'type',
        'contact_id',
        'lms_student_id',
        'student_name',
        'reference',
        'is_vat_inclusive',
        'notes',
        'terms',
        'frequency',
        'day_of_month',
        'starts_on',
        'ends_on',
        'next_run_on',
        'due_days',
        'is_active',
    ];

    /**
     * Kept out of the audit payload. A schedule that fails every night would
     * otherwise write an audit row every night, into a table auditors export.
     *
     * @return list<string>
     */
    public function auditExclude(): array
    {
        return ['last_error', 'last_error_at'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lms_student_id' => 'integer',
            'contact_id' => 'integer',
            'is_vat_inclusive' => 'boolean',
            'day_of_month' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'next_run_on' => 'immutable_date',
            'due_days' => 'integer',
            'is_active' => 'boolean',
            'last_generated_on' => 'immutable_date',
            'generated_count' => 'integer',
            'last_error_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<RecurringInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(RecurringInvoiceLine::class)->orderBy('line_number');
    }

    /** @return HasMany<RecurringInvoicePeriod, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(RecurringInvoicePeriod::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Schedules that owe an invoice as at `$on`.
     *
     * Deliberately does NOT filter on `ends_on`. A schedule that ended in
     * August still owes its August invoice when the run happens on 1
     * September, and excluding it here would lose that invoice for good. The
     * per-period loop stops at `ends_on` instead, and a schedule with nothing
     * left to bill retires itself.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDueOn(Builder $query, CarbonImmutable $on): void
    {
        $query->where('is_active', true)
            ->whereDate('next_run_on', '<=', $on->toDateString())
            ->whereDate('starts_on', '<=', $on->toDateString());
    }

    /** Whether `$issueDate` falls after this schedule was due to stop. */
    public function endsBefore(CarbonImmutable $issueDate): bool
    {
        return $this->ends_on !== null && $issueDate->greaterThan($this->ends_on);
    }

    /**
     * The issue date for the n-th period after `starts_on`.
     *
     * Computed from the start plus a period count, never by repeatedly adding
     * a month to the previous date: `addMonth()` on 31 January gives 28
     * February, and again gives 28 *March*, because the day sticks once it has
     * been clamped. Recomputing from the stored intent each time keeps a
     * 31st-of-the-month schedule on the 31st.
     */
    public function issueDateForPeriod(int $periodIndex): CarbonImmutable
    {
        $months = self::MONTHS_PER_PERIOD[$this->frequency] ?? 1;

        return $this->onDayOf(
            $this->firstIssueDate()->startOfMonth()->addMonths($periodIndex * $months),
        );
    }

    /**
     * The first cadence date on or after `starts_on`.
     *
     * Not simply "the chosen day in the starting month": a schedule created on
     * the 30th to bill on the 1st means *next* month's 1st, not a backdated
     * invoice for a month that had already gone by when the schedule was
     * written. Getting this wrong bills a family for the past.
     */
    private function firstIssueDate(): CarbonImmutable
    {
        $months = self::MONTHS_PER_PERIOD[$this->frequency] ?? 1;
        $candidate = $this->onDayOf($this->starts_on);

        return $candidate->lessThan($this->starts_on)
            ? $this->onDayOf($this->starts_on->startOfMonth()->addMonths($months))
            : $candidate;
    }

    /**
     * `$month` on this schedule's chosen day, clamped to the month's length.
     *
     * `setDay(31)` on a 30-day month would be fine, but building the date with
     * `Carbon::create(y, m, 31)` silently overflows into the next month — so
     * the clamp is explicit rather than left to the library.
     */
    private function onDayOf(CarbonImmutable $month): CarbonImmutable
    {
        return $month->setDay(min($this->day_of_month, $month->daysInMonth));
    }

    /**
     * The idempotency key for that period.
     *
     * 'YYYY-MM' for monthly and quarterly, 'YYYY' for annual. A contract:
     * changing the format re-opens every schedule to double-billing for one
     * cycle.
     */
    public function periodKeyFor(CarbonImmutable $issueDate): string
    {
        return $this->frequency === self::FREQUENCY_ANNUALLY
            ? $issueDate->format('Y')
            : $issueDate->format('Y-m');
    }

    public function isSales(): bool
    {
        return $this->type === Invoice::TYPE_SALES;
    }
}
