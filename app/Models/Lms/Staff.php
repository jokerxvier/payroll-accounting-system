<?php

declare(strict_types=1);

namespace App\Models\Lms;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Staff extends ReadOnlyModel
{
    protected $table = 'sm_staffs';

    public $timestamps = true;

    protected $casts = [
        'active_status' => 'boolean',
        'show_public' => 'boolean',
        'date_of_birth' => 'date',
        'date_of_joining' => 'date',
        'driving_license_ex_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'designation_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active_status', 1);
    }
}
