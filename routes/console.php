<?php

declare(strict_types=1);

use App\Console\Commands\CheckAdAccountsCommand;
use App\Console\Commands\CheckProviderConnectionsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Scheduled maintenance (spec §20, §29).
 *
 * Both sweeps only queue work; the provider calls happen on the `providers`
 * queue, so a slow provider delays its own checks and nothing else. Both are
 * `withoutOverlapping` because a sweep that runs long must not have a second
 * copy start on top of it.
 */
Schedule::command(CheckProviderConnectionsCommand::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(CheckAdAccountsCommand::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
