<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Exceptions;

use App\Domains\Campaign\Enums\CampaignStatus;
use RuntimeException;

/**
 * A refusal from the campaign engine, written so the interface can show it
 * unchanged (spec §80).
 */
final class CampaignException extends RuntimeException
{
    public static function invalidTransition(CampaignStatus $from, CampaignStatus $to): self
    {
        return new self(sprintf(
            'A campaign that is %s cannot be moved to %s.',
            strtolower($from->label()),
            strtolower($to->label()),
        ));
    }

    public static function notEditable(): self
    {
        return new self('This campaign can no longer be edited.');
    }

    public static function objectiveNotSupported(): self
    {
        return new self('That objective is not available on this advertising platform.');
    }

    public static function alreadySubmitted(): self
    {
        return new self('This campaign has already been submitted for review.');
    }

    public static function notUnderReview(): self
    {
        return new self('This campaign is not waiting for a review decision.');
    }

    public static function cannotReviewOwnSubmission(): self
    {
        return new self('A campaign cannot be reviewed by the person who submitted it.');
    }

    public static function alreadyPublished(): self
    {
        return new self('This campaign has already been published.');
    }

    public static function notPublished(): self
    {
        return new self('This campaign is not running at the advertising platform yet.');
    }

    public static function currencyMismatch(): self
    {
        return new self('This campaign is in a different currency from the wallet it would draw on.');
    }
}
