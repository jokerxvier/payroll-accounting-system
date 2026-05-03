<?php

declare(strict_types=1);

namespace App\Models\Pas;

use Database\Factories\Pas\DeductionTypeFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Stub model — placeholder so the Chunk-1 factory can resolve.
 *
 * Chunk 2 of the Week 7 plan replaces this with the real model:
 * fillable, calc_method/source enum constants, casts, scopes, policy
 * binding, and the `Auditable` trait.
 */
final class DeductionType extends Model
{
    use HasFactory;

    protected $table = 'pas_deduction_types';

    /** @var list<string>|bool */
    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return DeductionTypeFactory::new();
    }
}
