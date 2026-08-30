<?php

declare(strict_types=1);

namespace App\Domains\Identity\Rules;

use Illuminate\Validation\Rules\Password;

/**
 * The platform password policy (spec §83 Security).
 *
 * Kept in one place so registration, reset and change all enforce the same
 * rules, and so tightening the policy is a single edit.
 */
trait PasswordRules
{
    /**
     * @return array<int, mixed>
     */
    protected function passwordRules(): array
    {
        return [
            'required',
            'string',
            'confirmed',
            Password::min(12)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols()
                // Rejects passwords known to have appeared in a breach. The
                // check is a k-anonymity range query; the password itself never
                // leaves the server.
                ->uncompromised(),
        ];
    }
}
