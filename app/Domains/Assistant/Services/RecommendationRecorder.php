<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Services;

use App\Domains\Assistant\Contracts\AssistantProvider;
use App\Domains\Assistant\DTOs\CampaignBrief;
use App\Domains\Assistant\DTOs\CopyRequest;
use App\Domains\Assistant\Enums\RecommendationKind;
use App\Domains\Assistant\Enums\RecommendationStatus;
use App\Domains\Assistant\Models\Recommendation;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;

/**
 * Asks the assistant, and writes down what it said (spec §45, §46).
 *
 * Everything an assistant produces goes through here, so provenance is written
 * once and cannot be forgotten at a call site. A recommendation with no source
 * is a recommendation nobody can evaluate, and §46 asks for the opposite.
 *
 * Nothing in this class acts on a suggestion. It stores one.
 */
final class RecommendationRecorder
{
    public function __construct(private readonly AssistantManager $assistants) {}

    public function suggestCampaign(
        Organization $organization,
        CampaignBrief $brief,
        User $requester,
    ): Recommendation {
        $assistant = $this->assistants->provider();

        $suggestion = $assistant->suggestCampaign($brief);

        return $this->store(
            organization: $organization,
            assistant: $assistant,
            kind: RecommendationKind::Campaign,
            headline: $suggestion->name,
            body: implode(' ', $suggestion->rationale) ?: 'A suggested campaign.',
            payload: $suggestion->toArray(),
            digest: $brief->digest(),
            requester: $requester,
        );
    }

    public function generateCopy(
        Organization $organization,
        CopyRequest $request,
        User $requester,
    ): Recommendation {
        $assistant = $this->assistants->provider();

        $copy = $assistant->generateCopy($request);

        return $this->store(
            organization: $organization,
            assistant: $assistant,
            kind: RecommendationKind::Copy,
            headline: $copy->headlines[0] ?? 'Suggested copy',
            body: $copy->primaryText,
            payload: $copy->toArray(),
            digest: $request->digest(),
            requester: $requester,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function store(
        Organization $organization,
        AssistantProvider $assistant,
        RecommendationKind $kind,
        string $headline,
        string $body,
        array $payload,
        string $digest,
        User $requester,
    ): Recommendation {
        $recommendation = new Recommendation([
            'organization_id' => $organization->getKey(),
            'kind' => $kind,
            'headline' => $headline,
            'body' => $body,
            'payload' => $payload,
        ]);

        $recommendation->tenant_id = $organization->tenant_id;

        // Offered, never accepted. A person decides (spec §45).
        $recommendation->status = RecommendationStatus::Offered;

        $recommendation->source_driver = $assistant->name();
        $recommendation->source_model = $assistant->model();
        $recommendation->source_version = $assistant->version();

        /*
         * The digest, not the brief. A client describing their business will
         * mention unannounced products, margins and competitors, and none of
         * that belongs in a table every recommendation screen reads (§53, §54).
         */
        $recommendation->prompt_digest = $digest;
        $recommendation->requested_by = $requester->getKey();

        $recommendation->save();

        return $recommendation;
    }
}
