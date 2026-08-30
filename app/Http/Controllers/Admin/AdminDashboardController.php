<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\OrganizationStatus;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform overview (spec §40).
 *
 * Phase 0 reports the counts that exist today. Spend, revenue, wallet liability
 * and account-health figures arrive with the finance and advertising modules;
 * the dashboard names them as pending rather than showing a zero that would
 * read as a real measurement.
 */
final class AdminDashboardController
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        // Platform staff query across tenants deliberately, and only with the
        // permission that allows it.
        $canViewClients = $user->hasPermissionTo(Permission::ClientsView);

        return Inertia::render('Admin/Dashboard', [
            'metrics' => [
                'tenants' => $canViewClients ? Tenant::query()->count() : null,
                'organizations' => $canViewClients ? Organization::acrossTenants()->count() : null,
                'pendingVerification' => $canViewClients
                    ? Organization::acrossTenants()
                        ->whereIn('status', [
                            OrganizationStatus::Pending->value,
                            OrganizationStatus::UnderReview->value,
                        ])
                        ->count()
                    : null,
                'platformUsers' => User::query()->where('is_platform_user', true)->count(),
            ],
            'recentAuditEvents' => Gate::allows('audit.view')
                ? AuditLog::query()
                    ->with('actor:id,name,email')
                    ->latest('created_at')
                    ->limit(10)
                    ->get()
                    ->map(fn (AuditLog $log): array => [
                        'id' => $log->public_id,
                        'action' => $log->action,
                        'actor' => $log->actor?->name ?? $log->actor_label ?? 'System',
                        'at' => $log->created_at?->toIso8601String(),
                    ])
                    ->all()
                : [],
        ]);
    }
}
