<?php

declare(strict_types=1);

namespace App\Domains\Advertising\DTOs;

use Closure;

/**
 * An ad as the platform describes it (spec §21, §23).
 *
 * The extra headlines and descriptions are here because providers disagree
 * about what an ad is. Meta shows the copy that was written; Google's
 * responsive search ads rotate at least three headlines and two descriptions
 * and will not accept fewer. Rather than have each adapter invent the missing
 * ones — putting words in a client's mouth that they never approved — the
 * platform collects them and passes on whatever it has.
 *
 * The creative arrives as a checksum plus a closure that opens the bytes, not
 * as a path. An adapter uploads a file to a provider and has no business
 * knowing where the platform keeps its storage — and the closure means the
 * file is only opened by an adapter that actually needs it, rather than on
 * every draft that gets built.
 */
final readonly class AdDraft
{
    /**
     * @param  Closure(): resource|null  $openCreative
     * @param  list<string>  $extraHeadlines  further headlines, for providers
     *                                        that rotate several in one ad
     * @param  list<string>  $extraDescriptions
     */
    public function __construct(
        public string $reference,
        public string $externalAdSetId,
        public string $name,
        public string $headline,
        public string $primaryText,
        public string $destinationUrl,
        public string $identityExternalId,
        public string $creativeChecksum,
        public ?Closure $openCreative = null,
        public ?string $description = null,
        public ?string $callToAction = null,
        public array $extraHeadlines = [],
        public array $extraDescriptions = [],
    ) {}

    /**
     * @return resource|null
     */
    public function creativeStream()
    {
        return $this->openCreative === null ? null : ($this->openCreative)();
    }
}
