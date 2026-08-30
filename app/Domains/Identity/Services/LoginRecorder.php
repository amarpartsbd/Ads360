<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\LoginHistory;
use App\Domains\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Records authentication attempts and applies account lockout (spec §8).
 *
 * Lockout is per account and additional to the per-address rate limiter: the
 * limiter slows a single source down, this stops a distributed attempt against
 * one account.
 */
final class LoginRecorder
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Request $request,
    ) {}

    public function recordSuccess(User $user, bool $twoFactorUsed = false): void
    {
        LoginHistory::create([
            'user_id' => $user->getKey(),
            'email' => $user->email,
            'successful' => true,
            'two_factor_used' => $twoFactorUsed,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->truncatedUserAgent(),
            'device_fingerprint' => $this->fingerprint(),
            'created_at' => Carbon::now(),
        ]);

        $user->forceFill([
            'last_login_at' => Carbon::now(),
            'last_login_ip' => $this->request->ip(),
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $this->audit->record(action: AuditAction::LoginSucceeded, resource: $user, actor: $user);
    }

    /**
     * Records a failure and, when the account exists, counts it towards
     * lockout. Failures against unknown addresses are still stored so
     * credential stuffing is visible, but cannot lock anything.
     */
    public function recordFailure(string $email, ?User $user, string $reason): void
    {
        LoginHistory::create([
            'user_id' => $user?->getKey(),
            'email' => $email,
            'successful' => false,
            'failure_reason' => $reason,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->truncatedUserAgent(),
            'device_fingerprint' => $this->fingerprint(),
            'created_at' => Carbon::now(),
        ]);

        if ($user === null) {
            return;
        }

        $attempts = $user->failed_login_attempts + 1;
        $maximum = (int) config('platform.security.max_login_attempts');

        $user->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_until' => $attempts >= $maximum
                ? Carbon::now()->addMinutes((int) config('platform.security.lockout_minutes'))
                : $user->locked_until,
        ])->save();

        $this->audit->record(
            action: $attempts >= $maximum ? AuditAction::LoginBlocked : AuditAction::LoginFailed,
            resource: $user,
            context: ['reason' => $reason, 'attempts' => $attempts],
            actor: $user,
        );
    }

    /**
     * A coarse device identifier derived from the user agent and address,
     * salted with the application key so it is not correlatable outside this
     * installation.
     */
    public function fingerprint(): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [$this->request->userAgent(), $this->request->ip()]),
            (string) config('app.key'),
        );
    }

    private function truncatedUserAgent(): ?string
    {
        $agent = $this->request->userAgent();

        return $agent === null ? null : substr($agent, 0, 1024);
    }
}
