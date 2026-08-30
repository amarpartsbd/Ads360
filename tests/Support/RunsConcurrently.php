<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Runs a callback in several genuinely concurrent OS processes.
 *
 * Financial safety cannot be demonstrated by calling a method twice in a row:
 * the failure mode being guarded against is two requests reading the same
 * balance before either writes. That only happens with real parallelism, so
 * these tests fork.
 *
 * Each child drops the connection it inherited and opens its own — sharing a
 * PDO handle across a fork corrupts both sides. Children wait on a common start
 * time so they collide rather than queue politely, write their outcome to a
 * file, and terminate with SIGKILL so PHPUnit's shutdown handlers do not run
 * once per child.
 *
 * Tests using this must NOT wrap themselves in a transaction (so
 * DatabaseMigrations, not RefreshDatabase): a forked child cannot see
 * uncommitted rows from the parent.
 */
trait RunsConcurrently
{
    /**
     * @param  callable(int): bool  $work  returns true when the attempt succeeded
     * @return array{succeeded: int, failed: int, errors: list<string>}
     */
    protected function runConcurrently(int $workers, callable $work): array
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required for concurrency tests.');
        }

        $directory = sys_get_temp_dir().'/ads360-concurrency-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);

        // Far enough ahead that every child is parked and waiting before any of
        // them starts work.
        $startAt = microtime(true) + 0.5;

        $pids = [];

        for ($worker = 0; $worker < $workers; $worker++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Could not fork a worker process.');
            }

            if ($pid === 0) {
                $this->runChild($directory, $worker, $startAt, $work);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        return $this->collect($directory, $workers);
    }

    /**
     * @param  callable(int): bool  $work
     */
    private function runChild(string $directory, int $worker, float $startAt, callable $work): never
    {
        $outcome = 'error:unknown';

        try {
            // The inherited connection belongs to the parent's socket; using it
            // from two processes interleaves protocol frames.
            DB::purge();
            DB::reconnect();

            // Spin rather than sleep: the aim is for every worker to enter the
            // critical section within the same few microseconds.
            while (microtime(true) < $startAt) {
                usleep(200);
            }

            $outcome = $work($worker) ? 'ok' : 'rejected';
        } catch (\Throwable $exception) {
            $outcome = 'error:'.$exception::class.':'.$exception->getMessage();
        }

        $handle = fopen($directory.'/'.$worker, 'wb');

        if ($handle !== false) {
            fwrite($handle, $outcome);
            fflush($handle);
            fclose($handle);
        }

        // Terminating rather than exiting: exit() would run PHPUnit's shutdown
        // handlers once per child and pollute the test output.
        posix_kill(posix_getpid(), SIGKILL);

        exit(0); // @phpstan-ignore-line unreachable, kept so the return type holds
    }

    /**
     * @return array{succeeded: int, failed: int, errors: list<string>}
     */
    private function collect(string $directory, int $workers): array
    {
        $succeeded = 0;
        $failed = 0;
        $errors = [];

        for ($worker = 0; $worker < $workers; $worker++) {
            $path = $directory.'/'.$worker;
            $outcome = is_file($path) ? (string) file_get_contents($path) : 'error:no-result';

            if ($outcome === 'ok') {
                $succeeded++;
            } elseif ($outcome === 'rejected') {
                $failed++;
            } else {
                $failed++;
                $errors[] = $outcome;
            }

            @unlink($path);
        }

        @rmdir($directory);

        return ['succeeded' => $succeeded, 'failed' => $failed, 'errors' => $errors];
    }
}
