<?php

declare(strict_types=1);

namespace Tests\Feature\Branding;

use App\Domains\Tenant\Actions\UpdateBranding;
use App\Domains\Tenant\Models\Tenant;
use App\Domains\Tenant\Values\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * White-label branding (spec §43, §74).
 *
 * The contrast tests are the ones that matter. A platform that let a customer
 * choose a colour their own staff cannot read text on has sold them a broken
 * interface and called it a feature.
 */
final class WhiteLabelTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
        config()->set('platform.features.white_label', true);
        config()->set('platform.features.agency_module', true);
    }

    #[Test]
    #[DataProvider('colours')]
    public function a_colour_is_accepted_only_when_white_text_can_be_read_on_it(
        string $hex,
        bool $legible,
    ): void {
        $this->assertSame($legible, Branding::isLegible($hex));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function colours(): array
    {
        return [
            // The platform's own primary, for reference.
            'the design system blue' => ['#2158a7', true],
            'black' => ['#000000', true],
            'a deep green' => ['#2e7d32', true],

            // Every one of these would put white text on a background nobody
            // can read it against.
            'yellow' => ['#ffff00', false],
            'mid grey' => ['#777777', false],
            'white' => ['#ffffff', false],

            'not a colour at all' => ['teal', false],
        ];
    }

    #[Test]
    public function an_illegible_colour_is_refused_with_a_message_that_says_what_to_do(): void
    {
        $agency = $this->createAgencyWorkspace();

        try {
            app(UpdateBranding::class)->handle(
                $agency['tenant'],
                ['primary_color' => '#ffff00'],
                null,
                $agency['user'],
            );
            $this->fail('An unreadable colour should have been refused.');
        } catch (ValidationException $exception) {
            $message = $exception->validator->errors()->first('primary_color');

            $this->assertStringContainsString('too light', $message);
            // Tells them the number, so "make it darker" is actionable.
            $this->assertStringContainsString('4.5', $message);
        }
    }

    #[Test]
    public function branding_is_stored_and_read_back_through_the_same_rules(): void
    {
        $agency = $this->createAgencyWorkspace();

        app(UpdateBranding::class)->handle(
            $agency['tenant'],
            [
                'name' => 'Demo Media',
                'primary_color' => '#2E7D32',
                'support_email' => 'Help@Demo-Media.test',
                'logo_url' => 'https://cdn.demo-media.test/logo.svg',
            ],
            'ads.demo-media.test',
            $agency['user'],
        );

        $branding = $agency['tenant']->fresh()->brandingValue();

        $this->assertSame('Demo Media', $branding->name);
        // Normalised on the way in, so a comparison later is like for like.
        $this->assertSame('#2e7d32', $branding->primaryColor);
        $this->assertSame('help@demo-media.test', $branding->supportEmail);
        $this->assertSame('ads.demo-media.test', $agency['tenant']->fresh()->custom_domain);
    }

    #[Test]
    public function a_stored_colour_that_would_no_longer_pass_is_not_rendered(): void
    {
        $agency = $this->createAgencyWorkspace();

        // Written around the action — an older release, or a hand edit.
        $agency['tenant']->forceFill(['branding' => ['primary_color' => '#ffff00']])->save();

        // Read back through the same object that validates on the way in, so
        // an unreadable colour never reaches a screen.
        $this->assertNull($agency['tenant']->fresh()->brandingValue()->primaryColor);
    }

    #[Test]
    public function a_logo_must_be_served_over_https(): void
    {
        $agency = $this->createAgencyWorkspace();

        // An http logo on an https page is a mixed-content warning in every
        // client's browser.
        $this->expectException(ValidationException::class);

        app(UpdateBranding::class)->handle(
            $agency['tenant'],
            ['logo_url' => 'http://cdn.demo-media.test/logo.svg'],
            null,
            $agency['user'],
        );
    }

    #[Test]
    public function a_domain_is_reduced_to_the_hostname_people_actually_paste(): void
    {
        $agency = $this->createAgencyWorkspace();

        app(UpdateBranding::class)->handle(
            $agency['tenant'],
            [],
            'https://Ads.Demo-Media.test/dashboard',
            $agency['user'],
        );

        // A stored "https://Ads.Demo-Media.test/" would never match a Host
        // header while looking perfectly correct on the settings screen.
        $this->assertSame('ads.demo-media.test', $agency['tenant']->fresh()->custom_domain);
    }

    #[Test]
    public function two_tenants_cannot_claim_the_same_domain(): void
    {
        $first = $this->createAgencyWorkspace();
        $second = $this->createAgencyWorkspace();

        $update = app(UpdateBranding::class);

        $update->handle($first['tenant'], [], 'ads.example.test', $first['user']);

        $this->expectException(ValidationException::class);

        $update->handle($second['tenant'], [], 'ads.example.test', $second['user']);
    }

    #[Test]
    public function the_database_refuses_a_second_claim_even_without_the_service(): void
    {
        $first = $this->createAgencyWorkspace();
        $second = $this->createAgencyWorkspace();

        Tenant::query()->whereKey($first['tenant']->getKey())->update(['custom_domain' => 'ads.example.test']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // The service gives the message; the index is the guarantee.
        Tenant::query()->whereKey($second['tenant']->getKey())->update(['custom_domain' => 'ads.example.test']);
    }

    #[Test]
    public function branding_cannot_be_changed_while_the_feature_is_off(): void
    {
        config()->set('platform.features.white_label', false);

        $agency = $this->createAgencyWorkspace();

        $this->expectException(ValidationException::class);

        app(UpdateBranding::class)->handle($agency['tenant'], ['name' => 'Demo'], null, $agency['user']);
    }

    #[Test]
    public function the_screen_is_closed_when_the_feature_is_off(): void
    {
        config()->set('platform.features.white_label', false);

        $agency = $this->createAgencyWorkspace();

        $this->actingAs($agency['user'])
            ->get(route('client.branding.edit'))
            ->assertNotFound();
    }

    #[Test]
    public function a_client_owner_cannot_rebrand_the_platform(): void
    {
        // Organization-scoped, and without the branding permission: a client
        // of an agency must not be able to change what the agency's other
        // clients see.
        $workspace = $this->createWorkspace('client-owner');

        $this->actingAs($workspace['user'])
            ->put(route('client.branding.update'), ['name' => 'Not mine'])
            ->assertForbidden();
    }

    #[Test]
    public function the_form_refuses_an_unreadable_colour_before_it_reaches_the_action(): void
    {
        $agency = $this->createAgencyWorkspace();

        $this->actingAs($agency['user'])
            ->put(route('client.branding.update'), ['primary_color' => '#ffff00'])
            ->assertSessionHasErrors('primary_color');
    }

    #[Test]
    public function clearing_a_field_falls_back_rather_than_storing_a_blank(): void
    {
        $agency = $this->createAgencyWorkspace();

        $update = app(UpdateBranding::class);

        $update->handle($agency['tenant'], ['name' => 'Demo Media'], null, $agency['user']);
        $update->handle($agency['tenant'], ['name' => ''], null, $agency['user']);

        // Absent, not an explicit null that would render as blank.
        $this->assertArrayNotHasKey('name', $agency['tenant']->fresh()->branding);
        $this->assertNull($agency['tenant']->fresh()->brandingValue()->name);
    }

    #[Test]
    public function the_shell_carries_the_tenants_own_name_and_colour(): void
    {
        $agency = $this->createAgencyWorkspace();

        app(UpdateBranding::class)->handle(
            $agency['tenant'],
            ['name' => 'Demo Media', 'primary_color' => '#2e7d32'],
            null,
            $agency['user'],
        );

        $response = $this->actingAs($agency['user'])->get(route('client.dashboard'));

        $response->assertOk();

        /*
         * In the document itself, not only in a prop. A tenant whose colour
         * arrived after hydration would watch the platform's blue flash on
         * every page load.
         */
        $response->assertSee('<title inertia>Demo Media</title>', false);
        $response->assertSee('--primary: #2e7d32', false);
    }

    #[Test]
    public function an_unbranded_tenant_sees_the_platforms_own_name(): void
    {
        $workspace = $this->createWorkspace('client-owner');

        $this->actingAs($workspace['user'])
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('<title inertia>'.config('platform.name').'</title>', false);
    }

    #[Test]
    public function branding_is_recorded_in_the_audit_trail(): void
    {
        $agency = $this->createAgencyWorkspace();

        app(UpdateBranding::class)->handle(
            $agency['tenant'],
            ['name' => 'Demo Media'],
            null,
            $agency['user'],
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => \App\Domains\Audit\Enums\AuditAction::TenantBrandingChanged->value,
            'actor_id' => $agency['user']->getKey(),
        ]);
    }
}
