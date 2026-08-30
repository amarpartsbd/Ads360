<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | Argon2id is required by spec §8. It is memory-hard, which makes offline
    | cracking of a leaked hash substantially more expensive than bcrypt.
    |
    */

    'driver' => 'argon2id',

    'bcrypt' => [
        'rounds' => (int) env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    'argon' => [
        // 64 MiB of memory per hash, three passes, one thread. Raise `memory`
        // and `time` together with the hardware the application runs on.
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 3),
        'verify' => true,
    ],

    'rehash_on_login' => true,

];
