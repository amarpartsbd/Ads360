<?php

declare(strict_types=1);

use App\Console\Commands\AssessClientRiskCommand;
use App\Console\Commands\CheckAdAccountsCommand;
use App\Console\Commands\CheckProviderConnectionsCommand;
use App\Console\Commands\IngestCampaignMetricsCommand;
use App\Console\Commands\PruneReportExportsCommand;
use App\Console\Commands\ReconcileSpendCommand;
use App\Console\Commands\SyncCampaignSpendCommand;
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

/*
 * Campaign spend reconciliation (spec §32, §78).
 *
 * Every fifteen minutes rather than hourly: the figures here decide what a
 * client is charged, and a campaign spending quickly should not run far ahead
 * of what has been drawn from its hold.
 */
Schedule::command(SyncCampaignSpendCommand::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Analytics and reconciliation (spec §38, §78).
 *
 * Both run on the analytics queue, which is the lowest priority band: these
 * figures are what a client is shown, and must never delay publishing or the
 * spend capture a client is charged by (spec §28).
 *
 * Ingestion runs hourly and re-reads a trailing window, because providers
 * restate past days as attribution windows close. Reconciliation runs daily,
 * once the day's restatements have mostly settled.
 */
Schedule::command(IngestCampaignMetricsCommand::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(ReconcileSpendCommand::class)
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();

// Expired report files are removed daily; the record of who exported what
// stays (spec §39, §55).
Schedule::command(PruneReportExportsCommand::class)
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Client risk (spec §12).
 *
 * Six-hourly rather than hourly: the factors it reads move over days, not
 * minutes, and a score that is a few hours old is no worse a basis for a
 * compliance decision than one from this minute. On the analytics queue, which
 * is the lowest band — nothing here decides what a client is charged, and it
 * must never delay publishing or spend capture (spec §28).
 */
Schedule::command(AssessClientRiskCommand::class)
    ->everySixHours()
    ->withoutOverlapping()
    ->onOneServer();
