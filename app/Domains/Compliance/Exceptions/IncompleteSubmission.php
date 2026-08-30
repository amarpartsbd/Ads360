<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Exceptions;

use App\Domains\Compliance\Enums\DocumentType;
use DomainException;

/**
 * A verification submission is missing evidence it cannot be reviewed without.
 */
final class IncompleteSubmission extends DomainException
{
    /**
     * @param  list<DocumentType>  $missing
     */
    public static function missingDocuments(array $missing): self
    {
        $labels = implode(', ', array_map(
            static fn (DocumentType $type): string => $type->label(),
            $missing,
        ));

        return new self("The following documents are still required: {$labels}.");
    }
}
