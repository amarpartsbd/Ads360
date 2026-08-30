<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

final class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * @param  array<string, string>  $input
     */
    public function update(Authenticatable $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email:rfc,strict', 'max:255',
                Rule::unique(User::class)->ignore($user->getAuthIdentifier()),
            ],
            'mobile_number' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+\-\s()]+$/'],
            'timezone' => ['required', 'string', 'timezone'],
        ])->validateWithBag('updateProfileInformation');

        if ($user instanceof User
            && $input['email'] !== $user->email
            && $user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail) {
            $this->updateVerifiedUser($user, $input);

            return;
        }

        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'mobile_number' => $input['mobile_number'] ?? null,
            'timezone' => $input['timezone'],
        ])->save();
    }

    /**
     * Changing the address invalidates the verification that was tied to it.
     *
     * @param  array<string, string>  $input
     */
    private function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'mobile_number' => $input['mobile_number'] ?? null,
            'timezone' => $input['timezone'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
