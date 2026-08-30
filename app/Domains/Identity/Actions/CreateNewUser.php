<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Rules\PasswordRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Fortify's registration entry point.
 *
 * Validation lives here; the workspace is built by RegisterClient so the same
 * provisioning logic is reachable from seeders, tests and admin tooling.
 */
final class CreateNewUser implements CreatesNewUsers
{
    use PasswordRules;

    public function __construct(private readonly RegisterClient $registerClient) {}

    /**
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc,strict', 'max:255', Rule::unique(User::class)],
            'mobile_number' => ['required', 'string', 'max:32', 'regex:/^[0-9+\-\s()]+$/'],
            'password' => $this->passwordRules(),
            'company_name' => ['required', 'string', 'max:255'],
            'business_type' => ['required', 'string', 'max:64'],
            'country' => ['required', 'string', 'size:2'],
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => 'You must accept the terms of service to continue.',
        ])->validate();

        return $this->registerClient->handle($input);
    }
}
