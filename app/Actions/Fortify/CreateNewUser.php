<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

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

        [$firstName, $lastName] = array_pad(
            preg_split('/\s+/', trim($input['name']), 2) ?: [],
            2,
            '',
        );

        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
