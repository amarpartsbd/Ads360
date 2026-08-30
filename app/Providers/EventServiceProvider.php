<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Identity\Listeners\RecordLogout;
use App\Domains\Identity\Listeners\RecordSuccessfulLogin;
use App\Domains\Identity\Listeners\RecordTwoFactorChange;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, string>>
     */
    protected $listen = [
        Login::class => [
            RecordSuccessfulLogin::class,
        ],
        Logout::class => [
            RecordLogout::class,
        ],
        TwoFactorAuthenticationConfirmed::class => [
            [RecordTwoFactorChange::class, 'handleConfirmed'],
        ],
        TwoFactorAuthenticationDisabled::class => [
            [RecordTwoFactorChange::class, 'handleDisabled'],
        ],
        RecoveryCodesGenerated::class => [
            [RecordTwoFactorChange::class, 'handleRecoveryCodesGenerated'],
        ],
    ];
}
