<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Identity\Actions\CreateNewUser;
use App\Domains\Identity\Actions\ResetUserPassword;
use App\Domains\Identity\Actions\UpdateUserPassword;
use App\Domains\Identity\Actions\UpdateUserProfileInformation;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\LoginRecorder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

/**
 * Authentication wiring (spec §8).
 *
 * Fortify supplies the flows; this provider decides how they behave — Inertia
 * views, the authentication rules including account lockout, and the rate
 * limiters.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        $this->registerViews();
        $this->registerAuthentication();
        $this->registerRateLimiters();
    }

    private function registerViews(): void
    {
        Fortify::loginView(fn () => Inertia::render('Auth/Login', [
            'canResetPassword' => true,
            'status' => session('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('Auth/Register'));

        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('Auth/ResetPassword', [
            'email' => $request->input('email'),
            'token' => $request->route('token'),
        ]));

        Fortify::verifyEmailView(fn () => Inertia::render('Auth/VerifyEmail', [
            'status' => session('status'),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmPassword'));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/TwoFactorChallenge'));
    }

    /**
     * Credential verification, extended with the checks Fortify does not know
     * about: account status and lockout.
     */
    private function registerAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request) {
            $email = Str::lower((string) $request->input('email'));
            $recorder = app(LoginRecorder::class);

            /** @var User|null $user */
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                // Hash anyway so a missing account and a wrong password take
                // comparable time, and the response cannot be used to
                // enumerate registered addresses.
                Hash::make((string) $request->input('password'));
                $recorder->recordFailure($email, null, 'unknown_account');

                return null;
            }

            if ($user->isLocked()) {
                $recorder->recordFailure($email, $user, 'account_locked');

                throw ValidationException::withMessages([
                    'email' => 'This account is temporarily locked after too many failed attempts. Try again later.',
                ]);
            }

            if (! Hash::check((string) $request->input('password'), $user->password)) {
                $recorder->recordFailure($email, $user, 'invalid_password');

                return null;
            }

            if (! $user->canAuthenticate()) {
                $recorder->recordFailure($email, $user, 'account_not_active');

                throw ValidationException::withMessages([
                    'email' => 'This account is not active. Please contact support.',
                ]);
            }

            // Two-factor, when enabled, is challenged by Fortify after this
            // callback returns; the success record is written by the listener
            // once authentication actually completes.
            return $user;
        });
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            // Keyed on address and account together, so one attacker cannot
            // lock every account from a single address and a distributed
            // attempt on one account is still counted.
            $throttleKey = Str::transliterate(
                Str::lower((string) $request->input('email')).'|'.$request->ip()
            );

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinute(5)
            ->by((string) $request->session()->get('login.id')));

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
}
