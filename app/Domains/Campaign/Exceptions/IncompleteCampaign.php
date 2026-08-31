<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Exceptions;

use RuntimeException;

/**
 * A campaign that cannot be submitted yet, with every reason at once
 * (spec §21, §72).
 *
 * All the problems are collected rather than thrown one at a time. A client
 * fixing a campaign should see everything that is missing in one pass, not
 * discover a new failure on each attempt.
 */
final class IncompleteCampaign extends RuntimeException
{
    /**
     * @param  list<string>  $reasons
     */
    private function __construct(public readonly array $reasons)
    {
        parent::__construct(
            'This campaign is not ready to submit: '.implode(' ', $reasons)
        );
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function because(array $reasons): self
    {
        return new self($reasons);
    }

    /**
     * Shaped for a validation error bag, so the interface can put each reason
     * where it belongs rather than showing one long sentence.
     *
     * @return array<string, list<string>>
     */
    public function toErrorBag(): array
    {
        return ['campaign' => $this->reasons];
    }
}
