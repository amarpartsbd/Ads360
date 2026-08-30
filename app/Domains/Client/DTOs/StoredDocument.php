<?php

declare(strict_types=1);

namespace App\Domains\Client\DTOs;

use App\Domains\Client\Enums\DocumentMediaType;

/**
 * The result of storing an uploaded file: everything the caller needs to
 * persist a row, and nothing about where the bytes physically live beyond the
 * disk-relative path.
 */
final readonly class StoredDocument
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $originalFilename,
        public DocumentMediaType $mediaType,
        public int $sizeInBytes,
        /** SHA-256 of the stored bytes, for de-duplication and integrity checks. */
        public string $checksum,
        public ?int $width = null,
        public ?int $height = null,
    ) {}
}
