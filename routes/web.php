<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\TwoFactorSetupController;
use App\Http\Controllers\Client\ClientDashboardController;
use App\Http\Controllers\Client\OrganizationSwitchController;
use App\Http\Controllers\Client\SecurityController;
use App\Http\Controllers\Client\TeamController;
use App\Http\Controllers\Shared\WelcomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::get('/', WelcomeController::class)->name('welcome');

/*
|--------------------------------------------------------------------------
| Client application — /app (spec §92)
|--------------------------------------------------------------------------
|
| Every route here requires an authenticated, verified user with a resolved
| tenant context. `tenant` fails closed: without context the request is
| refused rather than served unscoped.
|
*/

Route::middleware(['auth', 'verified', 'tenant'])
    ->prefix('app')
    ->name('client.')
    ->group(function (): void {
        Route::get('dashboard', ClientDashboardController::class)->name('dashboard');

        Route::post('organization/switch', OrganizationSwitchController::class)
            ->name('organization.switch');

        Route::get('team', [TeamController::class, 'index'])->name('team.index');

        Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');
        Route::delete('settings/sessions/{session}', [SecurityController::class, 'destroySession'])
            ->name('security.sessions.destroy');
    });

/*
|--------------------------------------------------------------------------
| Administration — /admin (spec §92)
|--------------------------------------------------------------------------
|
| `platform` rejects any non-staff account before a controller runs, and
| `admin.2fa` holds administrators without an authenticator at the enrolment
| page (spec §9).
|
*/

Route::middleware(['auth', 'verified', 'platform', 'admin.2fa'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('dashboard', AdminDashboardController::class)->name('dashboard');

        Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
        Route::get('clients/{organization}', [ClientController::class, 'show'])->name('clients.show');

        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
    });

// Reachable without a confirmed authenticator so an administrator can enrol.
Route::middleware(['auth', 'verified', 'platform'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('security/two-factor', TwoFactorSetupController::class)
            ->name('security.two-factor.setup');
    });
