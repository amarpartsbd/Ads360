<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Identity\Listeners\RecordLogout;
use App\Domains\Identity\Listeners\RecordSuccessfulLogin;
use App\Domains\Identity\Listeners\RecordTwoFactorChange;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

/**
 * Everything the platform records about an authentication event (spec §10).
 *
 * Registered here rather than through the inherited `$listen` map because three
 * of these events are handled by different methods on the same listener, and
 * the parent's map is declared as a list of listener *class names*. Calling
 * `Event::listen()` says the same thing, and says it in a form the type checker
 * can read.
 */
class EventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Event::listen(Login::class, RecordSuccessfulLogin::class);
        Event::listen(Logout::class, RecordLogout::class);

        Event::listen(
            TwoFactorAuthenticationConfirmed::class,
            [RecordTwoFactorChange::class, 'handleConfirmed'],
        );
        Event::listen(
            TwoFactorAuthenticationDisabled::class,
            [RecordTwoFactorChange::class, 'handleDisabled'],
        );
        Event::listen(
            RecoveryCodesGenerated::class,
            [RecordTwoFactorChange::class, 'handleRecoveryCodesGenerated'],
        );
    }
}
