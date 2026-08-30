<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\MembershipStatus;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * A single hash reused across the suite. Argon2id is intentionally slow, so
     * hashing per user would dominate test runtime.
     */
    private static ?string $passwordHash = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'is_platform_user' => false,
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'mobile_number' => '+8801700000000',
            'password' => self::$passwordHash ??= Hash::make('password-that-is-long-enough'),
            'status' => UserStatus::Active,
            'timezone' => 'Asia/Dhaka',
            'locale' => 'en',
            'terms_accepted_at' => now(),
            'remember_token' => null,

            // Written explicitly so a freshly created model carries these
            // attributes. Strict attribute access, which is on outside
            // production, treats an unset column as a programming error.
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => [
            'email_verified_at' => null,
            'status' => UserStatus::PendingVerification,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => UserStatus::Suspended]);
    }

    /** Platform staff: no tenant, reaches the administration area. */
    public function platform(): static
    {
        return $this->state(fn (): array => [
            'is_platform_user' => true,
            'tenant_id' => null,
        ]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $tenant->getKey(),
            'is_platform_user' => false,
        ]);
    }

    public function withTwoFactor(): static
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Creates the user inside the organization's tenant with an active
     * membership, which is what actually grants them access.
     */
    public function memberOf(Organization $organization, MembershipStatus $status = MembershipStatus::Active): static
    {
        return $this
            ->state(fn (): array => [
                'tenant_id' => $organization->tenant_id,
                'is_platform_user' => false,
            ])
            ->afterCreating(function (User $user) use ($organization, $status): void {
                $organization->members()->attach($user->getKey(), [
                    'tenant_id' => $organization->tenant_id,
                    'status' => $status->value,
                    'is_primary' => true,
                    'joined_at' => now(),
                ]);
            });
    }
}
