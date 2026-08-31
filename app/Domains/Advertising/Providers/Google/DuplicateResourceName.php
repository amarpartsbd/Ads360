<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers\Google;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;

/**
 * Google refused a creation because something with that name already exists
 * (Rule 17).
 *
 * This is not a failure. It is Google enforcing, on its own side, the
 * guarantee that the platform is trying to obtain: a campaign name is unique
 * within a customer account, so a retry of a request that already landed is
 * *rejected* rather than duplicated. The publishing code catches this and goes
 * looking for the object the first attempt created.
 *
 * It extends ProviderUnavailable so that anything which has never heard of it
 * — the queue layer, an unrelated caller — still handles it correctly as a
 * refusal that is not worth retrying.
 */
final class DuplicateResourceName extends ProviderUnavailable
{
    public static function for(string $resource, string $detail): self
    {
        return new self(
            Provider::Google,
            false,
            'This has already been created in Google Ads.',
            "GOOGLE refused to create a duplicate {$resource}: {$detail}",
        );
    }
}
