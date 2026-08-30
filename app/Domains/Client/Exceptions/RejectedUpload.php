<?php

declare(strict_types=1);

namespace App\Domains\Client\Exceptions;

use RuntimeException;

/**
 * An upload failed validation and was not stored.
 *
 * The message is written for the person uploading (spec §80): it says what to
 * do about it, and never leaks the detected type or byte content back to them.
 */
final class RejectedUpload extends RuntimeException
{
    public static function unreadable(): self
    {
        return new self('That file could not be read. Please try uploading it again.');
    }

    public static function disallowedType(): self
    {
        return new self('That file type is not accepted. Upload a PDF, JPEG, PNG or WebP file.');
    }

    /** The declared extension and the actual bytes disagree. */
    public static function contentMismatch(): self
    {
        return new self('That file does not appear to be the type its name suggests.');
    }

    public static function tooLarge(int $maximumBytes): self
    {
        $megabytes = (int) round($maximumBytes / 1_048_576);

        return new self("That file is too large. The maximum size is {$megabytes} MB.");
    }

    public static function emptyFile(): self
    {
        return new self('That file is empty.');
    }

    public static function unreadableImage(): self
    {
        return new self('That image could not be read. Please upload a different file.');
    }

    public static function imageTooSmall(int $minimumPixels): self
    {
        return new self(
            "That image is too small to read. Each side must be at least {$minimumPixels} pixels."
        );
    }
}
