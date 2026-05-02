<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\User;

class EmployeeProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'payroll-officer', 'hr', 'auditor']);
    }
}
