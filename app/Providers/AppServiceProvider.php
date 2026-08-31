<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Advertising\Providers\Meta\MetaConfig;
use App\Domains\Advertising\Services\ProviderManager;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
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

        // Read once. Nothing else should be re-reading credentials out of
        // configuration, and a single instance keeps the app secret in one
        // place (spec §64).
        $this->app->singleton(MetaConfig::class, static fn (): MetaConfig => MetaConfig::fromConfig());
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

        $this->shareBrandingWithTheShell();
    }

    /**
     * The tenant's branding, available to the HTML document itself (spec §43).
     *
     * A view composer rather than an Inertia shared prop, because two of the
     * things branding decides — the browser tab's title and the primary colour
     * variable — live in the document `<head>` and are rendered before any
     * React component exists. A tenant whose colour only arrived after
     * hydration would watch their platform flash our blue on every page load.
     */
    private function shareBrandingWithTheShell(): void
    {
        View::composer('app', static function ($view): void {
            $tenant = app(TenantContext::class)->tenant();

            $view->with('branding', $tenant?->brandingValue()->toArray() ?? []);
        });
    }
}
