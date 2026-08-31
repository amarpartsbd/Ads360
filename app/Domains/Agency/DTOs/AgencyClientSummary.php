<?php

declare(strict_types=1);

namespace App\Domains\Agency\DTOs;

use App\Domains\Tenant\Enums\OrganizationStatus;
use App\Support\Values\Money;

/**
 * One client on an agency's roster, with the figures the agency needs to see
 * at a glance (spec §42).
 *
 * The money fields are Money objects rather than integers, so a currency can
 * never be lost between the query and the screen. `spend` is null rather than
 * zero when nothing has been reported for the window — a client whose
 * campaigns have not started yet is a different thing from one that spent
 * nothing, and an agency deciding where to put its attention should be able to
 * tell them apart (§87).
 */
final readonly class AgencyClientSummary
{
    public function __construct(
        public string $publicId,
        public string $name,
        public OrganizationStatus $status,
        public bool $isVerified,
        public Money $availableBalance,
        public ?Money $spend,
        public int $activeCampaigns,
        public int $totalCampaigns,
        public int $assignedStaff,
        public ?int $impressions = null,
        public ?int $clicks = null,
        public ?int $conversions = null,
    ) {}

    /**
     * Whether this client can actually run anything right now.
     *
     * Both halves matter and they fail differently: an unverified client is
     * waiting on compliance, an empty wallet is waiting on the agency.
     */
    public function canSpend(): bool
    {
        return $this->isVerified
            && $this->status === OrganizationStatus::Active
            && $this->availableBalance->isPositive();
    }
}
