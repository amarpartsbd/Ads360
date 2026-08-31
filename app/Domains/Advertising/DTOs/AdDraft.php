<?php

declare(strict_types=1);

namespace App\Domains\Advertising\DTOs;

use Closure;

/**
 * An ad as the platform describes it (spec §21, §23).
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
    ) {}

    /**
     * @return resource|null
     */
    public function creativeStream()
    {
        return $this->openCreative === null ? null : ($this->openCreative)();
    }
}
