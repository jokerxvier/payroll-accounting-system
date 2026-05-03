<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use Database\Factories\Pas\DeductionTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catalog row for a custom (non-statutory) deduction.
 *
 * Statutory contributions (BIR/SSS/PhilHealth/Pag-IBIG) live on
 * `pas_statutory_contributions` and are NOT modelled here. This catalog
 * covers anything the company chooses to deduct outside the statutory set —
 * HMO premium, uniform, salary deductions, etc.
 *
 * `calc_method` flags whether the per-employee subscription stores a fixed
 * amount or a basis-point percent. `source` flags whether the cost is borne
 * by the employee, the employer, or both.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_taxable
 * @property string $calc_method
 * @property string $source
 * @property bool $is_active
 * @property ?string $notes
 */
final class DeductionType extends Model
{
    use Auditable;

    /** @use HasFactory<DeductionTypeFactory> */
    use HasFactory;

    public const CALC_FIXED = 'fixed';

    public const CALC_PERCENT = 'percent';

    public const CALC_METHODS = [
        self::CALC_FIXED,
        self::CALC_PERCENT,
    ];

    public const SOURCE_EMPLOYEE = 'employee';

    public const SOURCE_EMPLOYER = 'employer';

    public const SOURCE_BOTH = 'both';

    public const SOURCES = [
        self::SOURCE_EMPLOYEE,
        self::SOURCE_EMPLOYER,
        self::SOURCE_BOTH,
    ];

    protected $table = 'pas_deduction_types';

    /**
     * Factory lives at Database\Factories\Pas\DeductionTypeFactory, which
     * doesn't match Laravel's default resolver path for a model under the
     * App\Models\Pas namespace. Same pattern as EmployeeProfile.
     *
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return DeductionTypeFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'is_taxable',
        'calc_method',
        'source',
        'is_active',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_taxable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Restrict to active catalog rows.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Per-employee subscriptions to this deduction type.
     *
     * @return HasMany<EmployeeDeduction, $this>
     */
    public function employeeDeductions(): HasMany
    {
        return $this->hasMany(EmployeeDeduction::class);
    }
}
