<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Enums;

/**
 * What a publication attempt is trying to do at the provider (Rule 17).
 *
 * Creating and changing are separated because their idempotency guarantees
 * differ. A create may happen at most once per entity, ever, and the database
 * enforces that. A pause may happen many times — pausing something already
 * paused is harmless — so repeats are allowed and only concurrent repeats are
 * suppressed.
 */
enum PublicationOperation: string
{
    case CreateCampaign = 'CREATE_CAMPAIGN';
    case CreateAdSet = 'CREATE_AD_SET';
    case CreateAd = 'CREATE_AD';
    case Pause = 'PAUSE';
    case Resume = 'RESUME';
    case Stop = 'STOP';
    case UpdateBudget = 'UPDATE_BUDGET';

    public function label(): string
    {
        return match ($this) {
            self::CreateCampaign => 'Create campaign',
            self::CreateAdSet => 'Create ad set',
            self::CreateAd => 'Create ad',
            self::Pause => 'Pause',
            self::Resume => 'Resume',
            self::Stop => 'Stop',
            self::UpdateBudget => 'Update budget',
        };
    }

    /**
     * Whether this operation brings something into existence at the provider.
     *
     * These are the operations that must never run twice: a second create is a
     * second campaign spending a second budget.
     */
    public function isCreation(): bool
    {
        return in_array($this, [
            self::CreateCampaign,
            self::CreateAdSet,
            self::CreateAd,
        ], true);
    }

    /**
     * Whether running it again after success is harmless. Pausing an already
     * paused campaign changes nothing, so a repeat needs no protection beyond
     * not racing itself.
     */
    public function isRepeatable(): bool
    {
        return ! $this->isCreation();
    }
}
