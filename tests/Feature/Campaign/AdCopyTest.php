<?php

declare(strict_types=1);

namespace Tests\Feature\Campaign;

use App\Domains\Campaign\Actions\SaveAd;
use App\Domains\Campaign\Models\Ad;
use App\Domains\Campaign\Models\AdSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsCampaigns;
use Tests\TestCase;

/**
 * The extra headlines and descriptions an ad can carry (spec §21, §23).
 *
 * Providers disagree about what an ad is. Meta shows the copy that was
 * written; a Google responsive search ad rotates at least three headlines and
 * two descriptions and refuses an ad carrying fewer. The platform collects
 * them rather than having an adapter invent the difference — words the client
 * never wrote appearing under their name.
 */
final class AdCopyTest extends TestCase
{
    use BuildsCampaigns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function adSet(): AdSet
    {
        return $this->draftCampaign()->adSets()->firstOrFail();
    }

    #[Test]
    public function an_ad_stores_the_extra_copy_it_was_given(): void
    {
        $ad = app(SaveAd::class)->create(
            adSet: $this->adSet(),
            name: 'Search ad',
            headline: 'Genuine parts, fast',
            primaryText: 'Order before six and it arrives tomorrow.',
            destinationUrl: 'https://example.test/landing',
            creative: $this->campaignCreative(),
            identity: $this->campaignIdentity(),
            extraHeadlines: ['Parts for every model', 'Genuine, guaranteed'],
            extraDescriptions: ['Thousands of parts in stock today.'],
        );

        $this->assertSame(
            ['Parts for every model', 'Genuine, guaranteed'],
            $ad->fresh()->extra_headlines,
        );
    }

    #[Test]
    public function blank_and_repeated_copy_is_dropped_before_it_reaches_a_provider(): void
    {
        $ad = app(SaveAd::class)->create(
            adSet: $this->adSet(),
            name: 'Search ad',
            headline: 'Genuine parts, fast',
            primaryText: 'Order before six.',
            destinationUrl: 'https://example.test/landing',
            creative: $this->campaignCreative(),
            identity: $this->campaignIdentity(),
            extraHeadlines: ['  Parts for every model  ', '', '   ', 'Parts for every model'],
        );

        /*
         * A blank headline among three real ones is an ad Google refuses for a
         * reason nobody reading the form would guess at, and a repeat does not
         * add a combination.
         */
        $this->assertSame(['Parts for every model'], $ad->fresh()->extra_headlines);
    }

    #[Test]
    public function an_ad_written_without_extra_copy_carries_empty_lists_not_nulls(): void
    {
        $ad = app(SaveAd::class)->create(
            adSet: $this->adSet(),
            name: 'Meta ad',
            headline: 'One headline is enough here',
            primaryText: 'Body copy.',
            destinationUrl: 'https://example.test/landing',
            creative: $this->campaignCreative(),
            identity: $this->campaignIdentity(),
        );

        $this->assertSame([], $ad->fresh()->extra_headlines);
        $this->assertSame([], $ad->fresh()->extra_descriptions);
    }

    #[Test]
    public function editing_an_ad_replaces_its_extra_copy(): void
    {
        $ad = app(SaveAd::class)->create(
            adSet: $this->adSet(),
            name: 'Search ad',
            headline: 'Genuine parts, fast',
            primaryText: 'Order before six.',
            destinationUrl: 'https://example.test/landing',
            creative: $this->campaignCreative(),
            identity: $this->campaignIdentity(),
            extraHeadlines: ['First', 'Second'],
        );

        app(SaveAd::class)->update($ad, ['extra_headlines' => ['Rewritten']]);

        $this->assertSame(['Rewritten'], $ad->fresh()->extra_headlines);
    }

    #[Test]
    public function the_database_refuses_extra_copy_that_is_not_a_list(): void
    {
        $this->draftCampaign();

        $ad = Ad::query()->withoutGlobalScopes()->firstOrFail();

        // Enforced in the database as well as in the request, because this is
        // sent to a provider verbatim.
        $this->expectException(\Illuminate\Database\QueryException::class);

        \Illuminate\Support\Facades\DB::table('ads')
            ->where('id', $ad->getKey())
            ->update(['extra_headlines' => '{"not":"a list"}']);
    }
}
