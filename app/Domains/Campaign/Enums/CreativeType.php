<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Enums;

/**
 * What kind of asset a creative is (spec §23).
 */
enum CreativeType: string
{
    case Image = 'IMAGE';
    case Video = 'VIDEO';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Image',
            self::Video => 'Video',
        };
    }

    /**
     * Extensions accepted for this type. The declared MIME type of an upload
     * is never trusted — the file's leading bytes decide, and the extension
     * only has to agree (spec §13).
     *
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        return match ($this) {
            self::Image => ['jpg', 'jpeg', 'png'],
            self::Video => ['mp4'],
        };
    }

    /** Largest file accepted, in bytes. */
    public function maximumBytes(): int
    {
        return match ($this) {
            self::Image => 10 * 1024 * 1024,
            self::Video => 200 * 1024 * 1024,
        };
    }

    /** Smallest edge a provider will accept without upscaling it badly. */
    public function minimumDimension(): int
    {
        return 600;
    }
}
