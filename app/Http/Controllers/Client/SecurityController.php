<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\LoginHistory;
use App\Domains\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Security settings: two-factor state, active sessions and login history
 * (spec §8, §14).
 */
final class SecurityController
{
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Client/Settings/Security', [
            'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
            // Started and not finished: a secret exists but no code has
            // confirmed it, which is a screen of its own rather than a reason
            // to start over and invalidate the code they have just scanned.
            'twoFactorPending' => $user->two_factor_secret !== null,
            'sessions' => $this->sessionsFor($request, $user),
            'loginHistory' => LoginHistory::query()
                ->where('user_id', $user->getKey())
                ->latest('created_at')
                ->limit(20)
                ->get()
                ->map(fn (LoginHistory $entry): array => [
                    'id' => $entry->getKey(),
                    'successful' => $entry->successful,
                    'reason' => $entry->failure_reason,
                    'ipAddress' => $entry->ip_address,
                    'twoFactorUsed' => $entry->two_factor_used,
                    'at' => $entry->created_at->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    /**
     * Revokes another session belonging to this user.
     *
     * The lookup is constrained by `user_id`, so a session identifier belonging
     * to someone else simply does not match.
     */
    public function destroySession(Request $request, string $session): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $deleted = DB::table('sessions')
            ->where('id', $session)
            ->where('user_id', $user->getKey())
            ->delete();

        if ($deleted === 0) {
            return back()->with('error', 'That session no longer exists.');
        }

        app(AuditRecorder::class)->record(
            action: AuditAction::SessionRevoked,
            resource: $user,
            context: ['session_id' => substr($session, 0, 8).'…'],
            actor: $user,
        );

        return back()->with('success', 'Session revoked.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sessionsFor(Request $request, User $user): array
    {
        return DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session): array => [
                'id' => $session->id,
                'ipAddress' => $session->ip_address,
                'userAgent' => $session->user_agent,
                'lastActive' => (int) $session->last_activity,
                'current' => $session->id === $request->session()->getId(),
            ])
            ->values()
            ->all();
    }
}
