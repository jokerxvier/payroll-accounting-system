<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use Database\Factories\Pas\AllowanceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catalog row for an allowance type — rice subsidy, transportation, meal,
 * communication, etc. Per-employee subscriptions live on
 * `pas_employee_allowances`.
 *
 * `is_de_minimis` is independent of `is_taxable`: a de-minimis allowance is
 * non-taxable up to a BIR-defined cap; above the cap the excess becomes
 * taxable. The `de_minimis_cap_centavos` column is nullable — NULL means
 * "no cap configured yet" pending the BIR cap specification (Q3 in
 * rules/PLAN.md). Super-admin updates the cap once the client supplies it.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_taxable
 * @property bool $is_de_minimis
 * @property ?int $de_minimis_cap_centavos
 * @property ?int $default_amount_centavos
 * @property bool $is_active
 * @property ?string $notes
 */
final class Allowance extends Model
{
    use Auditable;

    /** @use HasFactory<AllowanceFactory> */
    use HasFactory;

    protected $table = 'pas_allowances';

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return AllowanceFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'is_taxable',
        'is_de_minimis',
        'de_minimis_cap_centavos',
        'default_amount_centavos',
        'is_active',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_taxable' => 'boolean',
            'is_de_minimis' => 'boolean',
            'de_minimis_cap_centavos' => 'integer',
            'default_amount_centavos' => 'integer',
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
     * Per-employee subscriptions to this allowance type.
     *
     * @return HasMany<EmployeeAllowance, $this>
     */
    public function employeeAllowances(): HasMany
    {
        return $this->hasMany(EmployeeAllowance::class);
    }
}
