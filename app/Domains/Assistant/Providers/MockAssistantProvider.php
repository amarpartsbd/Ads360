<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Providers;

use App\Domains\Assistant\Contracts\AssistantProvider;
use App\Domains\Assistant\DTOs\CampaignBrief;
use App\Domains\Assistant\DTOs\CampaignSuggestion;
use App\Domains\Assistant\DTOs\CopyRequest;
use App\Domains\Assistant\DTOs\CopySuggestion;
use App\Domains\Campaign\Enums\CampaignObjective;
use RuntimeException;

/**
 * An assistant stand-in for development and tests (spec §95).
 *
 * The same bargain as the mock advertising providers: the whole flow — ask,
 * store, show, accept, refuse — can be exercised without a model, a key or a
 * bill, which is what makes it possible to build and test the surrounding
 * platform before any of those exist.
 *
 * It refuses to instantiate in production, and that refusal matters more here
 * than it does for a provider mock. A mock advertising adapter reporting
 * success is caught the first time a client asks why their ads never ran; a
 * mock *assistant* producing plausible-looking copy would be shipped to
 * clients' audiences and might never be noticed at all.
 *
 * What it returns is deliberately obviously a stub. There is no attempt to look
 * like a real answer, because a convincing fake is exactly what would let this
 * reach production unnoticed.
 */
final class MockAssistantProvider implements AssistantProvider
{
    public function __construct()
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'The mock assistant must never run in production. '
                .'It produces stub copy that would be published to real audiences.'
            );
        }
    }

    public function name(): string
    {
        return 'mock';
    }

    public function model(): string
    {
        return 'stub';
    }

    public function version(): string
    {
        return '1';
    }

    /** Both languages §46 asks for, so a caller's fallback path gets exercised. */
    public function supportsLanguage(string $language): bool
    {
        return in_array(strtolower($language), ['en', 'bn'], true);
    }

    public function suggestCampaign(CampaignBrief $brief): CampaignSuggestion
    {
        $this->assertLanguage($brief->language);

        return new CampaignSuggestion(
            name: 'Suggested campaign (sample)',
            /*
             * Leads rather than the most expensive objective available.
             * A stub that defaulted to Sales would have every development
             * campaign asking for conversion tracking nobody set up.
             */
            objective: CampaignObjective::Leads,
            countries: array_values(array_filter([$brief->country])),
            minimumAge: 18,
            maximumAge: 65,
            headlines: [
                'Sample headline one',
                'Sample headline two',
                'Sample headline three',
            ],
            descriptions: [
                'Sample description for development only.',
                'A second sample description.',
            ],
            rationale: [
                'This is the mock assistant. Nothing here was reasoned about.',
                'Set ASSISTANT_DRIVER to a live adapter before offering this to a client.',
            ],
        );
    }

    public function generateCopy(CopyRequest $request): CopySuggestion
    {
        $this->assertLanguage($request->language);

        $prefix = strtolower($request->language) === 'bn' ? 'নমুনা' : 'Sample';

        return new CopySuggestion(
            headlines: array_map(
                static fn (int $index): string => "{$prefix} headline {$index}",
                range(1, max(1, $request->variants)),
            ),
            descriptions: ["{$prefix} description one", "{$prefix} description two"],
            primaryText: "{$prefix} body copy for development only.",
            callToAction: 'LEARN_MORE',
            language: strtolower($request->language),
        );
    }

    /**
     * Fails loudly on a language it cannot write.
     *
     * §46 asks for Bangla and English. Silently answering in English when
     * asked for Bangla is the failure a client would discover in the ad that
     * ran, which is the most expensive possible place to find it.
     */
    private function assertLanguage(string $language): void
    {
        if (! $this->supportsLanguage($language)) {
            throw new RuntimeException(
                "The assistant cannot write in [{$language}]. Ask for English or Bangla."
            );
        }
    }
}
