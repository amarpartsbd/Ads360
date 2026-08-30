<?php

declare(strict_types=1);

namespace Tests;

use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Each test starts with no tenant bound. A test that needs context
        // establishes it explicitly, so nothing leaks between tests.
        app(TenantContext::class)->forget();
    }
}
