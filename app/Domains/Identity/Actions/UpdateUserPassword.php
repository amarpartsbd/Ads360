<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Rules\PasswordRules;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

final class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordRules;

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, string>  $input
     */
    public function update(Authenticatable $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => 'The provided password does not match your current password.',
        ])->validateWithBag('updatePassword');

        $user->forceFill(['password' => $input['password']])->save();

        if ($user instanceof User) {
            // The new password is never included — only the fact of the change.
            $this->audit->record(action: AuditAction::PasswordChanged, resource: $user, actor: $user);
        }
    }
}
