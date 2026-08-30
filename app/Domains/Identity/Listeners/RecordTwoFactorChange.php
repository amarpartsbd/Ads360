<?php

declare(strict_types=1);

namespace App\Domains\Identity\Listeners;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

/**
 * Two-factor changes are security events and are audited (spec §51).
 *
 * The secret and the recovery codes themselves are never part of the record.
 */
final class RecordTwoFactorChange
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handleConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->record(AuditAction::TwoFactorEnabled, $event->user);
    }

    public function handleDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->record(AuditAction::TwoFactorDisabled, $event->user);
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->record(AuditAction::TwoFactorRecoveryCodesRegenerated, $event->user);
    }

    private function record(AuditAction $action, mixed $user): void
    {
        if (! $user instanceof User) {
            return;
        }

        $this->audit->record(action: $action, resource: $user, actor: $user);
    }
}
