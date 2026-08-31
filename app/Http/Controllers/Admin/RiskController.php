<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Client\Actions\ReviewRiskProfile;
use App\Domains\Client\DTOs\RiskContribution;
use App\Domains\Client\Enums\RiskLevel;
use App\Domains\Client\Models\OrganizationRiskProfile;
use App\Domains\Client\Services\RiskAssessor;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The client risk queue (spec §12, §41).
 *
 * Everything a person can do here is a decision *about* a score, never an
 * automatic consequence of one. There is no route on this controller that
 * suspends an account, freezes a wallet or stops a campaign — those live where
 * they always did, behind their own permissions, and remain deliberate acts.
 */
final class RiskController
{
    public function __construct(
        private readonly ReviewRiskProfile $review,
        private readonly RiskAssessor $assessor,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', OrganizationRiskProfile::class);

        $level = $request->query('level');
        $onlyUnreviewed = $request->boolean('unreviewed');

        $profiles = OrganizationRiskProfile::query()
            ->withoutGlobalScopes()
            ->with(['organization:id,public_id,name,status', 'flaggedBy:id,name', 'reviewedBy:id,name'])
            ->when(
                is_string($level) && $level !== '',
                fn ($query) => $query->where('level', $level),
                // The default view is what needs a person: high and critical.
                fn ($query) => $query->whereIn('level', [
                    RiskLevel::High->value,
                    RiskLevel::Critical->value,
                ]),
            )
            ->when($onlyUnreviewed, fn ($query) => $query->whereNull('reviewed_at'))
            ->orderByDesc('score')
            ->orderBy('assessed_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (OrganizationRiskProfile $profile): array => $this->serialise($profile));

        return Inertia::render('Admin/Risk/Index', [
            'profiles' => $profiles,
            'filters' => ['level' => $level, 'unreviewed' => $onlyUnreviewed],
            'levels' => array_map(
                static fn (RiskLevel $case): array => [
                    'value' => $case->value,
                    'label' => $case->label(),
                    'guidance' => $case->guidance(),
                ],
                RiskLevel::cases(),
            ),
            'can' => ['manage' => Gate::allows('manage', OrganizationRiskProfile::class)],
        ]);
    }

    /** Reassess one organization now, rather than waiting for the sweep. */
    public function reassess(Request $request, string $organization): RedirectResponse
    {
        Gate::authorize('manage', OrganizationRiskProfile::class);

        $target = $this->requireOrganization($organization);

        $profile = $this->assessor->record($target);

        return back()->with(
            'success',
            "{$target->name} reassessed: {$profile->score}/100, {$profile->level->label()}.",
        );
    }

    public function flag(Request $request, string $organization): RedirectResponse
    {
        Gate::authorize('manage', OrganizationRiskProfile::class);

        $validated = $request->validate([
            // Required and stored: a flag is worth a fifth of the whole scale,
            // and an unexplained one would be points nobody can account for.
            'reason' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $target = $this->requireOrganization($organization);

        $profile = $this->review->flag($target, $this->actor($request), $validated['reason']);

        return back()->with(
            'success',
            "{$target->name} flagged. Risk is now {$profile->score}/100.",
        );
    }

    public function clearFlag(Request $request, string $organization): RedirectResponse
    {
        Gate::authorize('manage', OrganizationRiskProfile::class);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $target = $this->requireOrganization($organization);

        $profile = $this->review->clearFlag($target, $this->actor($request), $validated['reason']);

        return back()->with(
            'success',
            "Flag cleared. Risk is now {$profile->score}/100.",
        );
    }

    public function markReviewed(Request $request, string $organization): RedirectResponse
    {
        Gate::authorize('manage', OrganizationRiskProfile::class);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $target = $this->requireOrganization($organization);

        $this->review->markReviewed($target, $this->actor($request), $validated['note'] ?? null);

        return back()->with('success', "{$target->name} marked as reviewed.");
    }

    /**
     * @return array<string, mixed>
     */
    private function serialise(OrganizationRiskProfile $profile): array
    {
        return [
            'id' => $profile->public_id,
            'organization' => [
                'id' => $profile->organization?->public_id,
                'name' => $profile->organization?->name ?? 'Unknown',
                'status' => $profile->organization?->status->value,
            ],
            'score' => $profile->score,
            'level' => $profile->level->value,
            'levelLabel' => $profile->level->label(),
            'guidance' => $profile->level->guidance(),
            // The reasons, which is what makes the score arguable (§12).
            'factors' => array_map(
                static fn (RiskContribution $contribution): array => [
                    'label' => $contribution->factor->label(),
                    'points' => $contribution->points,
                    'detail' => $contribution->detail,
                    'remedy' => $contribution->factor->remedy(),
                ],
                $profile->contributions(),
            ),
            'requiresSecondApprover' => $profile->requiresSecondApprover(),
            'flagged' => $profile->manual_flag,
            'flagReason' => $profile->manual_flag_reason,
            'flaggedBy' => $profile->flaggedBy?->name,
            'reviewedAt' => $profile->reviewed_at?->toIso8601String(),
            'reviewedBy' => $profile->reviewedBy?->name,
            'reviewNote' => $profile->review_note,
            'assessedAt' => $profile->assessed_at?->toIso8601String(),
            'isStale' => $profile->isStale(),
        ];
    }

    private function requireOrganization(string $publicId): Organization
    {
        /** @var Organization|null $organization */
        $organization = Organization::query()
            ->withoutGlobalScopes()
            ->where('public_id', $publicId)
            ->first();

        if ($organization === null) {
            throw new NotFoundHttpException;
        }

        return $organization;
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
