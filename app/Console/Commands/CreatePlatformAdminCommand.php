<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Identity\Enums\RoleScope;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Rules\PasswordRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Creates the first platform administrator (spec §9, §83).
 *
 * A production deployment seeds permissions, roles and pricing, but no people:
 * `DemoDataSeeder` creates users and is deliberately development-only, because
 * a known password shipped with the application is a known password on every
 * installation of it. That leaves a working platform nobody can sign in to,
 * which is what this command is for.
 *
 * The password is prompted for, never accepted as an option. An option lands in
 * shell history and in the process list, where the next person with an account
 * on the box can read it; asking for it costs one interactive step and closes
 * both. It is checked against the same policy the registration form uses, so an
 * administrator cannot hold a weaker password than the clients they oversee.
 */
final class CreatePlatformAdminCommand extends Command
{
    use PasswordRules;

    protected $signature = 'ads:create-admin
                            {--name= : The administrator\'s full name}
                            {--email= : The address they sign in with}
                            {--role=super-admin : A platform-scoped role slug}';

    protected $description = 'Create a platform administrator and grant them a platform role';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Full name');
        $email = $this->option('email') ?: $this->ask('Email address');
        $roleSlug = (string) $this->option('role');

        $role = $this->platformRole($roleSlug);

        if ($role === null) {
            $this->error("[{$roleSlug}] is not a platform role. Run `php artisan db:seed` first, or pick one of:");
            $this->line('  '.$this->availableRoles());

            return self::FAILURE;
        }

        $password = $this->secret('Password (hidden)');
        $confirmation = $this->secret('Confirm password');

        try {
            $this->validate([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $confirmation,
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        /*
         * One transaction: an administrator who exists but holds no role can
         * sign in and see nothing, which reads as a broken deployment rather
         * than a half-finished command.
         */
        $user = DB::transaction(function () use ($name, $email, $password, $role): User {
            $user = new User;

            $user->fill([
                'name' => $name,
                'email' => $email,
                // Platform staff belong to no tenant: they operate the platform
                // rather than living inside one of its workspaces.
                'tenant_id' => null,
                'status' => UserStatus::Active,
                'timezone' => (string) config('platform.default_timezone'),
            ]);

            // Hashed by the model's cast, so the plaintext is never assigned to
            // a column and never reaches the query log.
            $user->password = $password;
            $user->is_platform_user = true;
            // Created by someone with shell access to the server, so there is
            // nobody left for an emailed link to prove anything to.
            $user->email_verified_at = now();
            $user->save();

            $user->roles()->attach($role->getKey(), [
                'organization_id' => null,
                'tenant_id' => null,
            ]);

            return $user;
        });

        $user->forgetCachedPermissions();

        $this->info("Created {$user->email} as {$role->name}.");

        if (config('platform.security.admin_requires_two_factor')) {
            $this->line('Two-factor enrolment is required: the first sign-in will stop at the enrolment page.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function validate(array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => $this->passwordRules(),
        ], [
            'email.unique' => 'An account already exists with that address.',
        ])->validate();
    }

    private function platformRole(string $slug): ?Role
    {
        return Role::query()
            ->whereNull('tenant_id')
            ->where('scope', RoleScope::Platform->value)
            ->where('slug', $slug)
            ->first();
    }

    private function availableRoles(): string
    {
        $slugs = Role::query()
            ->whereNull('tenant_id')
            ->where('scope', RoleScope::Platform->value)
            ->orderBy('slug')
            ->pluck('slug');

        return $slugs->isEmpty()
            ? '(none — the roles have not been seeded)'
            : $slugs->implode(', ');
    }
}
