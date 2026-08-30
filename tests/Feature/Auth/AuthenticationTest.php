<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\LoginHistory;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-Battery-9!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    #[Test]
    public function a_user_can_sign_in_with_valid_credentials(): void
    {
        $user = $this->createMember();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_successful_sign_in_is_recorded_and_audited(): void
    {
        $user = $this->createMember();

        $this->post(route('login'), ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->assertDatabaseHas('login_histories', [
            'user_id' => $user->getKey(),
            'successful' => true,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->getKey(),
            'action' => AuditAction::LoginSucceeded->value,
        ]);
    }

    #[Test]
    public function a_failed_sign_in_is_recorded_without_the_attempted_password(): void
    {
        $user = $this->createMember();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors();

        $this->assertGuest();

        $entry = LoginHistory::query()->where('email', $user->email)->firstOrFail();

        $this->assertFalse($entry->successful);
        $this->assertSame('invalid_password', $entry->failure_reason);

        // No column of the record may contain the submitted secret.
        $this->assertStringNotContainsString(
            'wrong-password',
            json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function an_attempt_against_an_unknown_address_is_recorded_but_locks_nothing(): void
    {
        $this->post(route('login'), [
            'email' => 'nobody@example.test',
            'password' => 'whatever-value',
        ])->assertSessionHasErrors();

        $this->assertDatabaseHas('login_histories', [
            'email' => 'nobody@example.test',
            'successful' => false,
            'failure_reason' => 'unknown_account',
            'user_id' => null,
        ]);
    }

    #[Test]
    public function repeated_failures_lock_the_account(): void
    {
        $user = $this->createMember();
        $maximum = (int) config('platform.security.max_login_attempts');

        // The per-address rate limiter is disabled here so this test exercises
        // the account lock on its own. Throttling has its own test below; the
        // two defences are independent and must be verified independently.
        $this->withoutMiddleware(ThrottleRequests::class);

        for ($attempt = 0; $attempt < $maximum; $attempt++) {
            $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $user->refresh();

        $this->assertSame($maximum, $user->failed_login_attempts);
        $this->assertTrue($user->isLocked(), 'The account should be locked after the configured attempts.');

        // Even the correct password is refused while the lock stands.
        $this->post(route('login'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertGuest();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->getKey(),
            'action' => AuditAction::LoginBlocked->value,
        ]);
    }

    #[Test]
    public function a_suspended_account_cannot_sign_in(): void
    {
        $user = $this->createMember();
        $user->forceFill(['status' => UserStatus::Suspended])->save();

        $this->post(route('login'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function a_successful_sign_in_clears_the_failure_counter(): void
    {
        $user = $this->createMember();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password']);
        $this->assertSame(1, $user->fresh()?->failed_login_attempts);

        $this->post(route('login'), ['email' => $user->email, 'password' => self::PASSWORD]);

        $user->refresh();
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
        $this->assertNotNull($user->last_login_at);
    }

    #[Test]
    public function signing_out_is_audited(): void
    {
        $user = $this->createMember();

        $this->actingAs($user)->post(route('logout'))->assertRedirect();

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->getKey(),
            'action' => AuditAction::LogoutPerformed->value,
        ]);
    }

    #[Test]
    public function passwords_are_hashed_with_argon2id(): void
    {
        $user = $this->createMember();

        $this->assertStringStartsWith('$argon2id$', $user->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    #[Test]
    public function the_password_and_two_factor_secret_are_never_serialised(): void
    {
        $user = $this->createMember();

        $payload = json_encode($user->toArray(), JSON_THROW_ON_ERROR);

        foreach (['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'] as $hidden) {
            $this->assertStringNotContainsString(
                $hidden,
                $payload,
                "[{$hidden}] leaked into the serialised user."
            );
        }
    }

    #[Test]
    public function login_is_rate_limited_per_address_and_account(): void
    {
        $user = $this->createMember();

        // Five attempts per minute are allowed for an address and email pair.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertSessionHasErrors();
        }

        // The sixth is rejected by the limiter before credentials are examined.
        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    #[Test]
    public function the_rate_limiter_does_not_block_a_different_account(): void
    {
        $victim = $this->createMember();
        $attacker = $this->createMember();

        // Exhaust the limiter against one account.
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post(route('login'), ['email' => $attacker->email, 'password' => 'wrong-password']);
        }

        // A different account from the same address is unaffected, so one
        // attacker cannot lock every user out of the platform.
        $this->post(route('login'), [
            'email' => $victim->email,
            'password' => self::PASSWORD,
        ])->assertRedirect();

        $this->assertAuthenticatedAs($victim);
    }

    #[Test]
    public function an_audit_record_never_stores_a_secret(): void
    {
        $user = $this->createMember();

        $this->post(route('login'), ['email' => $user->email, 'password' => self::PASSWORD]);

        $entries = AuditLog::query()->get();

        $this->assertNotEmpty($entries);

        foreach ($entries as $entry) {
            $this->assertStringNotContainsString(
                self::PASSWORD,
                json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR),
            );
        }
    }

    private function createMember(): User
    {
        $organization = Organization::factory()->create();

        return User::factory()
            ->memberOf($organization)
            ->create(['password' => Hash::make(self::PASSWORD)]);
    }
}
