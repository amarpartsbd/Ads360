<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Contracts;

use App\Domains\Assistant\DTOs\CampaignBrief;
use App\Domains\Assistant\DTOs\CampaignSuggestion;
use App\Domains\Assistant\DTOs\CopyRequest;
use App\Domains\Assistant\DTOs\CopySuggestion;

/**
 * An assistant that can propose campaigns and write ad copy (spec §45, §46).
 *
 * Shaped like the advertising provider contract, and for the same reason:
 * nothing above this interface should know or care which model answered, and
 * swapping one is a configuration change rather than a code change.
 *
 * Three rules bind every implementation.
 *
 * **Output is a recommendation.** §45 is explicit that a person approves before
 * financial execution. No implementation may create a campaign, submit one,
 * reserve funds or publish anything. What comes back is a proposal, stored as
 * such, which a person then accepts into a draft that goes through exactly the
 * same review as one they typed.
 *
 * **Provenance travels with the output.** Every suggestion names the driver,
 * the model and the version that produced it (§46). A client is entitled to
 * know whether a headline was written by a person or a model, and if a model
 * turns out to have been producing bad advice, someone has to be able to find
 * all of it.
 *
 * **Bangla and English.** §46 asks for both. An implementation that silently
 * answers in English when asked for Bangla has not failed loudly enough to be
 * noticed, and a client would find out when their ad ran.
 */
interface AssistantProvider
{
    /** Which implementation this is, for the provenance record. */
    public function name(): string;

    public function model(): string;

    public function version(): string;

    /**
     * Whether this assistant can answer in a language.
     *
     * Asked rather than assumed, the same way provider capabilities are
     * (§87): a caller checks before offering a client a language rather than
     * discovering the gap in the copy that ran.
     */
    public function supportsLanguage(string $language): bool;

    /**
     * Propose a campaign from a client's own description (spec §45).
     *
     * Nothing about the return value is acted on automatically.
     */
    public function suggestCampaign(CampaignBrief $brief): CampaignSuggestion;

    /**
     * Write ad copy (spec §46).
     */
    public function generateCopy(CopyRequest $request): CopySuggestion;
}
