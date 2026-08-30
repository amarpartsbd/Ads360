<?php

declare(strict_types=1);

namespace App\Domains\Identity\Listeners;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use Illuminate\Auth\Events\Logout;

final class RecordLogout
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->audit->record(
            action: AuditAction::LogoutPerformed,
            resource: $event->user,
            actor: $event->user,
        );
    }
}
