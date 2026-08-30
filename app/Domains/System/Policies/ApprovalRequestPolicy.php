<?php

declare(strict_types=1);

namespace App\Domains\System\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\System\Models\ApprovalRequest;

/**
 * Authorization for maker-checker requests (spec §25).
 *
 * The queue is platform staff only, and deciding a request needs the permission
 * the action itself would have needed — approving a refund requires the refund
 * permission, not merely access to the queue.
 */
final class ApprovalRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformUser();
    }

    public function view(User $user, ApprovalRequest $request): bool
    {
        return $user->isPlatformUser()
            && $user->hasPermissionTo($request->action_type->approvalPermission());
    }

    /**
     * Whether this person may vote. The model owns the maker-checker rules —
     * not the requester, not already voted — and this adds the permission
     * check on top.
     */
    public function decide(User $user, ApprovalRequest $request): bool
    {
        return $this->view($user, $request) && $request->canBeDecidedBy($user);
    }
}
