<?php

declare(strict_types=1);

namespace App\Domains\Identity\Listeners;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\LoginRecorder;
use Illuminate\Auth\Events\Login;

/**
 * Writes the success record once authentication has fully completed — after the
 * two-factor challenge, not before it.
 */
final class RecordSuccessfulLogin
{
    public function __construct(private readonly LoginRecorder $recorder) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->recorder->recordSuccess(
            $event->user,
            twoFactorUsed: $event->user->hasTwoFactorEnabled(),
        );
    }
}
