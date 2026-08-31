<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Actions;

use App\Domains\Assistant\Enums\RecommendationStatus;
use App\Domains\Assistant\Models\Recommendation;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A person decides what to do with a recommendation (spec §45).
 *
 * ## What accepting does, and what it deliberately does not
 *
 * It records that someone accepted, and returns the payload so a screen can
 * prefill a form with it. That is all.
 *
 * It does **not** create a campaign, submit one, reserve funds, allocate an ad
 * account or publish anything. §45 is explicit that AI output is a
 * recommendation and that a person approves before financial execution, and the
 * cheapest way to be sure of that is for this code to have no way to spend
 * money — there is no wallet, no campaign service and no publisher injected
 * here, so a future change that tried to would have to add one and would be
 * visible in review.
 *
 * A campaign built from an accepted suggestion is created by the client through
 * the ordinary builder, and goes through exactly the same review, approval and
 * reservation as one they typed themselves.
 */
final class DecideRecommendation
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @return array<string, mixed> the payload, for prefilling a form
     *
     * @throws ValidationException
     */
    public function accept(Recommendation $recommendation, User $actor, ?string $note = null): array
    {
        $decided = $this->decide($recommendation, $actor, RecommendationStatus::Accepted, $note);

        return $decided->payload ?? [];
    }

    /**
     * @throws ValidationException
     */
    public function dismiss(Recommendation $recommendation, User $actor, ?string $note = null): Recommendation
    {
        return $this->decide($recommendation, $actor, RecommendationStatus::Dismissed, $note);
    }

    /**
     * @throws ValidationException
     */
    private function decide(
        Recommendation $recommendation,
        User $actor,
        RecommendationStatus $status,
        ?string $note,
    ): Recommendation {
        return DB::transaction(function () use ($recommendation, $actor, $status, $note): Recommendation {
            /** @var Recommendation $locked */
            $locked = Recommendation::query()
                ->withoutGlobalScopes()
                ->whereKey($recommendation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages([
                    'recommendation' => 'That suggestion has already been decided.',
                ]);
            }

            $locked->forceFill([
                'status' => $status,
                'decided_by' => $actor->getKey(),
                'decided_at' => Carbon::now(),
                'decision_note' => $note === null ? null : trim($note),
            ])->save();

            $this->audit->record(
                action: $status === RecommendationStatus::Accepted
                    ? AuditAction::RecommendationAccepted
                    : AuditAction::RecommendationDismissed,
                resource: $locked,
                after: [
                    'kind' => $locked->kind->value,
                    'status' => $status->value,
                    // The provenance travels into the audit trail, so "who
                    // suggested this" survives even if the row is later read
                    // out of context (§46).
                    'source' => $locked->provenance(),
                ],
                organization: $locked->organization,
                actor: $actor,
            );

            $recommendation->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }
}
