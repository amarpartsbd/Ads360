<?php

declare(strict_types=1);

namespace App\Domains\Client\Enums;

/**
 * The file formats business documents may be uploaded in (spec §55).
 *
 * The allow-list is explicit and small. Each entry pairs the extensions the
 * platform accepts with the leading bytes a genuine file of that type starts
 * with, so a file can be identified by what it contains rather than by what the
 * uploader claims it is.
 */
enum DocumentMediaType: string
{
    case Pdf = 'application/pdf';
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return match ($this) {
            self::Pdf => ['pdf'],
            self::Jpeg => ['jpg', 'jpeg'],
            self::Png => ['png'],
            self::Webp => ['webp'],
        };
    }

    /**
     * Byte signatures a file of this type must begin with, as hex strings.
     * WebP is RIFF-based: bytes 0-3 are "RIFF" and 8-11 are "WEBP", so the
     * container check is handled separately in the sniffer.
     *
     * @return list<string>
     */
    public function signatures(): array
    {
        return match ($this) {
            self::Pdf => ['25504446'],                    // %PDF
            self::Jpeg => ['ffd8ff'],
            self::Png => ['89504e470d0a1a0a'],
            self::Webp => ['52494646'],                   // RIFF
        };
    }

    public function isImage(): bool
    {
        return $this !== self::Pdf;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'PDF',
            self::Jpeg => 'JPEG image',
            self::Png => 'PNG image',
            self::Webp => 'WebP image',
        };
    }

    public static function fromExtension(string $extension): ?self
    {
        $normalised = strtolower(ltrim($extension, '.'));

        foreach (self::cases() as $case) {
            if (in_array($normalised, $case->extensions(), true)) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Every accepted extension, for display and for the file picker.
     *
     * @return list<string>
     */
    public static function allowedExtensions(): array
    {
        return array_merge(...array_map(
            static fn (self $case): array => $case->extensions(),
            self::cases(),
        ));
    }

    /**
     * @return list<string>
     */
    public static function allowedMimeTypes(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
