<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Advertising\Services\ProviderManager;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One context per request or job. Everything that needs to know which
        // tenant it is acting for resolves this same instance.
        $this->app->singleton(TenantContext::class);

        // One registry per request, so an adapter configured in a test is the
        // same instance the code under test resolves.
        $this->app->singleton(ProviderManager::class);
    }

    public function boot(): void
    {
        // Fail loudly in development when a relation is accessed lazily or an
        // attribute that was never selected is read, so N+1 queries surface
        // during development rather than under production load (spec §75).
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Model::unguard(false);

        if (config('platform.security.force_https', false) || $this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
