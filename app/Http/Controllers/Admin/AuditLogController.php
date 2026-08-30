<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Audit trail viewer (spec §41 Audit Logs).
 *
 * Read-only by construction: there is no write route, the policy denies every
 * mutating ability, and the model refuses updates and deletes.
 */
final class AuditLogController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AuditLog::class);

        $logs = AuditLog::query()
            ->with(['actor:id,name,email', 'tenant:id,name', 'organization:id,name'])
            ->when($request->query('action'), fn ($query, $action) => $query->where('action', $action))
            ->when($request->query('request_id'), fn ($query, $id) => $query->where('request_id', $id))
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log): array => [
                'id' => $log->public_id,
                'action' => $log->action,
                'actor' => $log->actor?->name ?? $log->actor_label ?? 'System',
                'actorType' => $log->actor_type->value,
                'tenant' => $log->tenant?->name,
                'organization' => $log->organization?->name,
                'resourceType' => $log->resource_type !== null ? class_basename($log->resource_type) : null,
                'resourceId' => $log->resource_id,
                'ipAddress' => $log->ip_address,
                'requestId' => $log->request_id,
                'at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/AuditLogs/Index', [
            'logs' => $logs,
            'filters' => [
                'action' => $request->query('action'),
                'request_id' => $request->query('request_id'),
            ],
        ]);
    }
}
