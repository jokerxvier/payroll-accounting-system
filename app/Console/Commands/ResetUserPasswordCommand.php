<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ResetUserPasswordCommand extends Command
{
    protected $signature = 'dev:reset-password
        {--password=password : The plaintext password to set}
        {--email= : Reset only this email (overrides --role)}
        {--role= : Reset all users mapped to this payroll role}
        {--all : Reset every user in the LMS users table (dangerous)}';

    protected $description = 'Reset user password(s) to a fixed value for local testing. Defaults to super-admin only.';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $query = User::query();

        if ($email = $this->option('email')) {
            $query->where('email', $email);
            $scope = "email = {$email}";
        } elseif ($this->option('all')) {
            $scope = 'ALL users (LMS-wide)';
        } else {
            $roleSlug = (string) ($this->option('role') ?: 'super-admin');
            $map = (array) config('payroll.lms_role_to_payroll_role', []);
            $lmsRoleIds = array_keys(array_filter($map, fn ($slug) => $slug === $roleSlug));

            if ($lmsRoleIds === []) {
                $this->error("No LMS role_ids map to payroll role '{$roleSlug}' in config/payroll.php.");

                return self::FAILURE;
            }

            $query->whereIn('role_id', $lmsRoleIds);
            $scope = "payroll role '{$roleSlug}' (LMS role_id IN [".implode(',', $lmsRoleIds).'])';
        }

        $count = (clone $query)->count();
        $password = (string) $this->option('password');

        if ($count === 0) {
            $this->warn("No users matched scope: {$scope}");

            return self::SUCCESS;
        }

        if (! $this->confirm("Reset password to '{$password}' for {$count} user(s) — scope: {$scope}?", false)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $hash = Hash::make($password);
        $affected = $query->update(['password' => $hash]);

        $this->info("Reset {$affected} user(s).");

        return self::SUCCESS;
    }
}
