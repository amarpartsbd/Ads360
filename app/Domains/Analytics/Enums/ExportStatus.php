<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Enums;

/**
 * Where a report export has got to (spec §39).
 */
enum ExportStatus: string
{
    case Queued = 'QUEUED';
    case Generating = 'GENERATING';
    case Ready = 'READY';
    case Failed = 'FAILED';
    case Expired = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Generating => 'Generating',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
        };
    }

    public function clientMessage(): string
    {
        return match ($this) {
            self::Queued => 'Waiting to start. This page updates when it is ready.',
            self::Generating => 'Being generated now.',
            self::Ready => 'Ready to download.',
            self::Failed => 'We could not generate this report. Please try again.',
            self::Expired => 'This file has been removed. Generate it again to get a fresh copy.',
        };
    }

    public function isDownloadable(): bool
    {
        return $this === self::Ready;
    }
}
