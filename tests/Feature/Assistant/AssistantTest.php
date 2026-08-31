<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Domains\Assistant\Actions\DecideRecommendation;
use App\Domains\Assistant\DTOs\CampaignBrief;
use App\Domains\Assistant\DTOs\CopyRequest;
use App\Domains\Assistant\Enums\RecommendationKind;
use App\Domains\Assistant\Enums\RecommendationStatus;
use App\Domains\Assistant\Models\Recommendation;
use App\Domains\Assistant\Providers\MockAssistantProvider;
use App\Domains\Assistant\Services\AssistantManager;
use App\Domains\Assistant\Services\RecommendationRecorder;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Tenant\Models\Organization;
use App\Support\Values\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * The assistant (spec §45, §46, §95).
 *
 * The rule being defended is §45's: AI output is a recommendation, and a person
 * approves before anything financial happens. The tests that matter are the
 * ones proving that accepting a suggestion does not create, submit, fund or
 * publish a campaign.
 */
final class AssistantTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
        config()->set('platform.features.ai_assistant', true);
        config()->set('platform.assistant.driver', 'mock');
    }

    private function organization(): Organization
    {
        return $this->createWorkspace()['organization'];
    }

    private function brief(): CampaignBrief
    {
        return new CampaignBrief(
            description: 'I want leads for my GPS tracking service in Dhaka.',
            budget: Money::of('20000.00', 'BDT'),
            language: 'en',
            country: 'BD',
        );
    }

    #[Test]
    public function accepting_a_suggestion_creates_no_campaign_and_spends_nothing(): void
    {
        $organization = $this->organization();
        $user = $this->createWorkspace()['user'];

        $recommendation = app(RecommendationRecorder::class)
            ->suggestCampaign($organization, $this->brief(), $user);

        $campaignsBefore = Campaign::query()->withoutGlobalScopes()->count();

        $payload = app(DecideRecommendation::class)->accept($recommendation, $user);

        /*
         * The whole point of §45. Accepting returns something to prefill a
         * form with; it does not build anything, and the client still goes
         * through the ordinary builder, review and approval.
         */
        $this->assertSame($campaignsBefore, Campaign::query()->withoutGlobalScopes()->count());
        $this->assertArrayHasKey('objective', $payload);
        $this->assertSame(RecommendationStatus::Accepted, $recommendation->fresh()->status);
    }

    #[Test]
    public function the_action_that_accepts_has_no_way_to_spend_money(): void
    {
        $reflection = new \ReflectionClass(DecideRecommendation::class);
        $constructor = $reflection->getConstructor();

        $dependencies = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor?->getParameters() ?? [],
        );

        /*
         * Structural, not behavioural, and deliberately so: a future change
         * that made acceptance execute something would have to inject a wallet
         * or a publisher here, and this test would fail in review rather than
         * in production (§45).
         */
        foreach ($dependencies as $dependency) {
            $this->assertStringNotContainsString('Wallet', $dependency);
            $this->assertStringNotContainsString('Campaign', $dependency);
            $this->assertStringNotContainsString('Publisher', $dependency);
        }
    }

    #[Test]
    public function every_recommendation_records_where_it_came_from(): void
    {
        $organization = $this->organization();
        $user = $this->createWorkspace()['user'];

        $recommendation = app(RecommendationRecorder::class)
            ->generateCopy($organization, new CopyRequest('GPS trackers', 'fleet owners'), $user);

        // §46: stored with its source metadata.
        $this->assertSame('mock', $recommendation->source_driver);
        $this->assertSame('stub', $recommendation->source_model);
        $this->assertNotSame('', $recommendation->source_version);
        $this->assertFalse($recommendation->isDeterministic());
    }

    #[Test]
    public function the_brief_itself_is_never_stored(): void
    {
        $organization = $this->organization();
        $user = $this->createWorkspace()['user'];

        $brief = new CampaignBrief(
            description: 'Launching an unannounced product at a 60 percent margin.',
            budget: Money::of('20000.00', 'BDT'),
            country: 'BD',
        );

        $recommendation = app(RecommendationRecorder::class)
            ->suggestCampaign($organization, $brief, $user);

        $row = json_encode($recommendation->fresh()->getAttributes());

        // A digest, not the text. This table is read by every screen that
        // lists recommendations (§53, §54).
        $this->assertStringNotContainsString('unannounced', (string) $row);
        $this->assertStringNotContainsString('60 percent', (string) $row);
        $this->assertSame($brief->digest(), $recommendation->prompt_digest);
    }

    #[Test]
    public function the_same_brief_produces_the_same_digest(): void
    {
        $this->assertSame($this->brief()->digest(), $this->brief()->digest());
    }

    #[Test]
    public function a_language_the_assistant_cannot_write_is_refused_rather_than_substituted(): void
    {
        $organization = $this->organization();
        $user = $this->createWorkspace()['user'];

        // Answering in English when asked for French is the failure a client
        // discovers in the ad that ran (§46).
        $this->expectException(RuntimeException::class);

        app(RecommendationRecorder::class)->generateCopy(
            $organization,
            new CopyRequest('GPS trackers', 'fleet owners', language: 'fr'),
            $user,
        );
    }

    #[Test]
    public function bangla_and_english_are_both_offered(): void
    {
        $assistant = new MockAssistantProvider;

        $this->assertTrue($assistant->supportsLanguage('en'));
        $this->assertTrue($assistant->supportsLanguage('bn'));
    }

    #[Test]
    public function a_decision_cannot_be_made_twice(): void
    {
        $organization = $this->organization();
        $user = $this->createWorkspace()['user'];

        $recommendation = app(RecommendationRecorder::class)
            ->suggestCampaign($organization, $this->brief(), $user);

        $decide = app(DecideRecommendation::class);
        $decide->dismiss($recommendation, $user);

        $this->expectException(ValidationException::class);

        $decide->accept($recommendation->fresh(), $user);
    }

    #[Test]
    public function the_database_refuses_a_decision_that_names_nobody(): void
    {
        $organization = $this->organization();

        $recommendation = Recommendation::factory()->forOrganization($organization)->create();

        // §45's requirement that a person approves is worth nothing if the
        // record cannot name them.
        $this->expectException(\Illuminate\Database\QueryException::class);

        \Illuminate\Support\Facades\DB::table('recommendations')
            ->where('id', $recommendation->getKey())
            ->update(['status' => RecommendationStatus::Accepted->value]);
    }

    #[Test]
    public function no_assistant_answers_while_the_feature_is_off(): void
    {
        config()->set('platform.features.ai_assistant', false);

        $manager = new AssistantManager;

        $this->assertFalse($manager->isAvailable());

        $this->expectException(RuntimeException::class);

        $manager->provider();
    }

    #[Test]
    public function an_unimplemented_driver_is_refused_rather_than_falling_back_to_the_mock(): void
    {
        config()->set('platform.assistant.driver', 'openai');

        $manager = new AssistantManager;

        /*
         * A mock advertising adapter answering in production is caught when a
         * client asks why their ads never ran. A mock *assistant* writes copy
         * that gets published under a client's name, and nobody may ever
         * notice (§95).
         */
        $this->expectException(RuntimeException::class);

        $manager->provider();
    }

    #[Test]
    public function the_default_is_no_assistant_at_all(): void
    {
        /*
         * Read from the config file itself rather than the runtime value,
         * which setUp deliberately overrides. An assistant that was on unless
         * someone turned it off would be writing copy on a platform where
         * nobody chose a model.
         */
        $shipped = require config_path('platform.php');

        $this->assertSame('none', $shipped['assistant']['driver']);
        $this->assertFalse($shipped['features']['ai_assistant']);
    }

    #[Test]
    public function a_decision_is_recorded_in_the_audit_trail_with_its_provenance(): void
    {
        $organization = $this->organization();
        $user = $this->createWorkspace()['user'];

        $recommendation = app(RecommendationRecorder::class)
            ->suggestCampaign($organization, $this->brief(), $user);

        app(DecideRecommendation::class)->accept($recommendation, $user);

        $this->assertDatabaseHas('audit_logs', [
            'action' => \App\Domains\Audit\Enums\AuditAction::RecommendationAccepted->value,
            'actor_id' => $user->getKey(),
        ]);
    }

    #[Test]
    public function an_insight_is_marked_as_arithmetic_rather_than_a_model(): void
    {
        $organization = $this->organization();

        $insight = Recommendation::factory()->forOrganization($organization)->create();

        // The single most useful thing a reader can know about a
        // recommendation is whether a model wrote it.
        $this->assertTrue($insight->isDeterministic());
        $this->assertSame(RecommendationKind::Insight, $insight->kind);
    }
}
