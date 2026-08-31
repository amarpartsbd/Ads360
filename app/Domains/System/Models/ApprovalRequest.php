<?php

declare(strict_types=1);

namespace App\Domains\System\Models;

use App\Domains\Identity\Models\User;
use App\Domains\System\Enums\ApprovableAction;
use App\Domains\System\Enums\ApprovalStatus;
use App\Domains\Tenant\Models\Organization;
use App\Support\Concerns\HasPublicId;
use App\Support\Values\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A high-risk action waiting for a second pair of eyes (spec §25).
 *
 * Not tenant-scoped globally: these sit in a platform queue and are read by
 * finance staff who have no tenant of their own. The policy decides access.
 *
 * @property ApprovableAction $action_type
 * @property ApprovalStatus $status
 * @property array<string, mixed> $payload
 */
class ApprovalRequest extends Model
{
    use HasPublicId;

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'action_type',
        'summary',
        'payload',
        'amount',
        'currency',
        'required_approvals',
        'requires_senior_approval',
        'elevation_reason',
        'status',
        'requested_by',
        'request_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action_type' => ApprovableAction::class,
            'requires_senior_approval' => 'boolean',
            'senior_approved_at' => 'immutable_datetime',
            'status' => ApprovalStatus::class,
            'payload' => 'array',
            'amount' => 'integer',
            'required_approvals' => 'integer',
            'approvals_received' => 'integer',
            'executed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<ApprovalDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function amountMoney(): ?Money
    {
        if ($this->amount === null || $this->currency === null) {
            return null;
        }

        return Money::ofMinor($this->amount, $this->currency);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Whether a given person may vote on this request.
     *
     * The creator is excluded unconditionally: an action approved by the person
     * who requested it is not a checked action (spec §25). Anyone who has
     * already voted is excluded too — the unique index enforces that, this
     * makes the interface honest about it.
     */
    public function canBeDecidedBy(User $user): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        if ($user->getKey() === $this->requested_by) {
            return false;
        }

        return ! $this->decisions()->where('approver_id', $user->getKey())->exists();
    }

    public function outstandingApprovals(): int
    {
        return max(0, $this->required_approvals - $this->approvals_received);
    }

    /** Whether the senior signature §25 asks for is still missing. */
    public function awaitingSeniorApproval(): bool
    {
        return $this->requires_senior_approval && $this->senior_approved_at === null;
    }

    /**
     * What this request is still waiting for, in one sentence.
     *
     * A queue that says "1 of 2" while a request sits there for a day is a
     * queue nobody trusts. This says which of the two things is missing —
     * another signature, a senior one, or both.
     */
    public function outstandingSummary(): ?string
    {
        if (! $this->isOpen()) {
            return null;
        }

        $signatures = $this->outstandingApprovals();
        $senior = $this->awaitingSeniorApproval();

        return match (true) {
            $signatures > 0 && $senior => $signatures === 1
                ? 'Waiting on one more approval, which must include a senior approver.'
                : "Waiting on {$signatures} more approvals, which must include a senior approver.",
            $signatures > 0 => $signatures === 1
                ? 'Waiting on one more approval.'
                : "Waiting on {$signatures} more approvals.",
            $senior => 'Has enough approvals, but still needs a senior approver.',
            default => null,
        };
    }
}
