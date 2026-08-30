<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Rules\PasswordRules;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

final class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordRules;

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, string>  $input
     */
    public function reset(Authenticatable $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
            // A reset clears any brute-force lockout: the account has been
            // recovered through a verified email address.
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        if ($user instanceof User) {
            $this->audit->record(action: AuditAction::PasswordReset, resource: $user, actor: $user);
        }
    }
}
