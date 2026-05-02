<?php

declare(strict_types=1);

namespace App\Models\Lms;

use Illuminate\Database\Eloquent\Builder;

final class Designation extends ReadOnlyModel
{
    protected $table = 'sm_designations';

    public $timestamps = true;

    protected $casts = [
        'active_status' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active_status', 1);
    }
}
