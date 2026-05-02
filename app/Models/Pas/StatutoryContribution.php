<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\StatutoryContributionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Versioned, effective-dated statutory contribution rule set.
 *
 * One row per (contribution_code, effective period). The `algorithm` column
 * names a strategy class that knows how to read the `rules` JSON payload and
 * produce a StatutoryContributionResult. Adding a new contribution type or
 * jurisdiction is a new row, not a schema change.
 *
 * Boundary semantics: `effective_to` is **exclusive** — a row is active for
 * dates `effective_from <= d < effective_to` (or open-ended when null). This
 * matches the supersession invariant: when a new version's effective_from is
 * set to the prior row's effective_to, both never overlap.
 *
 * @phpstan-type RulesShape array<string, mixed>
 *
 * @property int $id
 * @property string $contribution_code
 * @property string $label
 * @property string $algorithm
 * @property CarbonImmutable $effective_from
 * @property ?CarbonImmutable $effective_to
 * @property RulesShape $rules
 * @property ?string $notes
 */
final class StatutoryContribution extends Model
{
    use Auditable;

    /** @use HasFactory<StatutoryContributionFactory> */
    use HasFactory;

    public const ALGORITHM_BRACKET_TABLE = 'bracket_table';

    public const ALGORITHM_SALARY_BAND = 'salary_band';

    public const ALGORITHM_PERCENTAGE_WITH_CAP = 'percentage_with_cap';

    public const ALGORITHM_TIERED_PERCENTAGE = 'tiered_percentage';

    public const ALGORITHMS = [
        self::ALGORITHM_BRACKET_TABLE,
        self::ALGORITHM_SALARY_BAND,
        self::ALGORITHM_PERCENTAGE_WITH_CAP,
        self::ALGORITHM_TIERED_PERCENTAGE,
    ];

    public const CODE_BIR = 'BIR_WITHHOLDING';

    public const CODE_SSS = 'SSS';

    public const CODE_PHILHEALTH = 'PHILHEALTH';

    public const CODE_PAGIBIG = 'PAGIBIG';

    public const CODES = [
        self::CODE_BIR,
        self::CODE_SSS,
        self::CODE_PHILHEALTH,
        self::CODE_PAGIBIG,
    ];

    protected $table = 'pas_statutory_contributions';

    /**
     * Factory lives at Database\Factories\StatutoryContributionFactory, which
     * doesn't match Laravel's default resolver path for a model under the
     * App\Models\Pas namespace. Same pattern as EmployeeProfile.
     */
    protected static function newFactory(): Factory
    {
        return StatutoryContributionFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'contribution_code',
        'label',
        'algorithm',
        'effective_from',
        'effective_to',
        'rules',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'rules' => 'array',
        ];
    }

    /**
     * Filter rows whose effective range covers the given date. Optionally
     * narrow to a single contribution_code.
     *
     * Boundary: `effective_to` is exclusive — a row with effective_to=2024-12-31
     * is NOT active on 2024-12-31; the next version (effective_from=2025-01-01)
     * is. This pairs with the supersession invariant in the admin UI.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForDate(Builder $query, CarbonInterface $date, ?string $contributionCode = null): Builder
    {
        $query->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>', $date),
            );

        if ($contributionCode !== null) {
            $query->where('contribution_code', $contributionCode);
        }

        return $query;
    }

    /**
     * Return the single row currently active for the given contribution code +
     * date, or null if none exist. Convenience wrapper around scopeForDate.
     */
    public static function current(string $contributionCode, CarbonInterface $date): ?self
    {
        return self::query()->forDate($date, $contributionCode)->first();
    }
}
