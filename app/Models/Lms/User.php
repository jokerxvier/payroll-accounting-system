<?php

declare(strict_types=1);

namespace App\Models\Lms;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class User extends ReadOnlyModel
{
    protected $table = 'users';

    public $timestamps = true;

    protected $casts = [
        'active_status' => 'boolean',
        'is_registered' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'trial_ends_at' => 'datetime',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function staff(): HasOne
    {
        return $this->hasOne(Staff::class, 'user_id');
    }
}
