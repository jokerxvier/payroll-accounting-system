<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Orphan: Features::registration() is intentionally disabled in
 * config/fortify.php (LMS is identity master). Retained for eventual cleanup.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        // Phase A.2: pas_users uses `name`, matching the form field. Created
        // rows are not LMS-backed (lms_user_id stays null), so the
        // AssignPayrollRoleOnLogin listener will log a warning and assign no
        // role for the immediate post-registration Login event. Registration
        // is dev-only in this build (config/fortify.php Features::registration);
        // production onboarding goes through the LMS identity master.
        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
