<?php

declare(strict_types=1);

/**
 * Loaded by PHPStan through `--autoload-file`, before anything else runs.
 *
 * Larastan decides which of its stub files apply by comparing them against the
 * `LARAVEL_VERSION` constant, and defines that constant from its own bootstrap
 * file. On a full run that is early enough. On an incremental run it is not:
 * PHPStan resolves the PHPDoc of every changed file while it works out what the
 * result cache can still be trusted for, which happens before bootstrap files
 * are executed, and Larastan's stub extension then reads a constant that does
 * not exist yet — so the second `phpstan analyse` in a row fails with
 * "Undefined constant LARAVEL_VERSION" rather than reporting anything.
 *
 * Defining it here fixes that, and defines it from the framework rather than
 * from a literal so it cannot drift from the installed version (Rule 6).
 * Larastan's own bootstrap guards its define, so this simply wins the race.
 */
require_once __DIR__.'/vendor/autoload.php';

if (! defined('LARAVEL_VERSION')) {
    define('LARAVEL_VERSION', Illuminate\Foundation\Application::VERSION);
}
