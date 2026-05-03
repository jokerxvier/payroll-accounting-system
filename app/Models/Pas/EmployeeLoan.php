<?php

declare(strict_types=1);

namespace App\Models\Pas;

use Database\Factories\Pas\EmployeeLoanFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Stub model — placeholder so the Chunk-1 factory can resolve.
 *
 * Chunk 2 of the Week 7 plan replaces this with the real model:
 * fillable, casts, `scopeOpen`, `applyAmortization()` returning new
 * balance, lock_version increment, policy binding, and the
 * `Auditable` trait.
 */
final class EmployeeLoan extends Model
{
    use HasFactory;

    protected $table = 'pas_employee_loans';

    /** @var list<string>|bool */
    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return EmployeeLoanFactory::new();
    }
}
