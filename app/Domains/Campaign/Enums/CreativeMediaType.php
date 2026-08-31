<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Enums;

/**
 * The file formats a creative may be uploaded in (spec §23).
 *
 * Small and explicit, like the document allow-list. Each entry pairs the
 * extensions accepted with the bytes a genuine file of that type begins with,
 * so a file is identified by what it contains rather than by what the uploader
 * called it (spec §13).
 */
enum CreativeMediaType: string
{
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Mp4 = 'video/mp4';

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return match ($this) {
            self::Jpeg => ['jpg', 'jpeg'],
            self::Png => ['png'],
            self::Mp4 => ['mp4'],
        };
    }

    /**
     * Leading bytes as hex.
     *
     * MP4 is ISO-BMFF: the first four bytes are a box length that varies, and
     * the type marker `ftyp` sits at offset 4 — so the sniffer checks that
     * offset rather than the very start of the file.
     *
     * @return list<string>
     */
    public function signatures(): array
    {
        return match ($this) {
            self::Jpeg => ['ffd8ff'],
            self::Png => ['89504e470d0a1a0a'],
            self::Mp4 => ['66747970'],  // ftyp, at offset 4
        };
    }

    /** Where in the file the signature is expected to appear. */
    public function signatureOffset(): int
    {
        return $this === self::Mp4 ? 4 : 0;
    }

    public function creativeType(): CreativeType
    {
        return $this === self::Mp4 ? CreativeType::Video : CreativeType::Image;
    }

    public function isImage(): bool
    {
        return $this->creativeType() === CreativeType::Image;
    }

    public function label(): string
    {
        return match ($this) {
            self::Jpeg => 'JPEG image',
            self::Png => 'PNG image',
            self::Mp4 => 'MP4 video',
        };
    }

    public static function fromExtension(string $extension): ?self
    {
        $normalised = strtolower(trim($extension));

        foreach (self::cases() as $case) {
            if (in_array($normalised, $case->extensions(), true)) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function allowedExtensions(): array
    {
        $extensions = [];

        foreach (self::cases() as $case) {
            $extensions = [...$extensions, ...$case->extensions()];
        }

        return array_values(array_unique($extensions));
    }
}
