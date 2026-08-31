<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * Where signing in lands (spec §8, §9).
 *
 * The platform has two front doors and each refuses the other's accounts, so
 * the one configured destination Fortify redirects to was right for clients and
 * a 403 for every administrator — which is what the first administrator on a
 * fresh installation met, having no way past it.
 *
 * Tested through the login form rather than by calling the response object,
 * because the defect was in the wiring rather than in any one class: the
 * response has to be bound, and the two-factor flow completes through a
 * different response than the one everybody thinks of.
 */
final class SignInDestinationTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    private const PASSWORD = 'Kh4l1shpur!Jetty#2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    #[Test]
    public function a_platform_administrator_lands_in_the_administration_area(): void
    {
        $admin = $this->platformAdmin();

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('admin.dashboard'));
    }

    #[Test]
    public function a_client_lands_in_their_workspace(): void
    {
        $workspace = $this->createWorkspace('client-owner');

        $this->post(route('login.store'), [
            'email' => $workspace['user']->email,
            // What UserFactory sets, so the sign-in is a real one.
            'password' => 'password-that-is-long-enough',
        ])->assertRedirect(route('client.dashboard'))
            ->assertSessionHasNoErrors();
    }

    /**
     * Administrators must hold an authenticator, so this is the flow they
     * actually arrive through — and it completes through a different Fortify
     * response than an ordinary sign-in.
     */
    #[Test]
    public function the_destination_survives_the_two_factor_challenge(): void
    {
        if (! Features::enabled(Features::twoFactorAuthentication())) {
            $this->markTestSkipped('Two-factor authentication is not enabled.');
        }

        $admin = $this->platformAdmin();
        $this->enrolTwoFactor($admin);

        // Credentials alone stop at the challenge rather than completing.
        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('two-factor.login'));

        $this->post(route('two-factor.login.store'), [
            'recovery_code' => $this->firstRecoveryCode($admin),
        ])->assertRedirect(route('admin.dashboard'));
    }

    /**
     * A deep link followed before signing in should survive the detour, or
     * every emailed link drops people on a dashboard instead.
     */
    #[Test]
    public function a_page_the_visitor_was_headed_for_still_wins(): void
    {
        $admin = $this->platformAdmin();

        // Being bounced to the login form is what records the intended page.
        $this->get(route('admin.clients.index'))->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('admin.clients.index'));
    }

    /**
     * The recovery codes are shown once, on this screen. It used to redirect an
     * enrolled administrator to the dashboard, so the one moment they were
     * available was spent on a redirect and anyone who later lost their phone
     * had nothing to sign in with.
     */
    #[Test]
    public function the_two_factor_screen_stays_reachable_once_enrolled(): void
    {
        $admin = $this->platformAdmin();
        $this->enrolTwoFactor($admin);

        $this->actingAs($admin)
            ->get(route('admin.security.two-factor.setup'))
            ->assertOk();
    }

    #[Test]
    public function an_administrator_who_is_already_signed_in_is_not_sent_to_the_client_area(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->get(route('login'))
            ->assertRedirect(route('admin.dashboard'));
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create([
            'is_platform_user' => true,
            'tenant_id' => null,
            'password' => self::PASSWORD,
        ]);

        $this->grantRole($admin, 'super-admin');

        return $admin;
    }

    private function enrolTwoFactor(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['aaaaaaaaaa-bbbbbbbbbb'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    private function firstRecoveryCode(User $user): string
    {
        /** @var list<string> $codes */
        $codes = json_decode(decrypt($user->fresh()->two_factor_recovery_codes), true);

        return $codes[0];
    }
}
