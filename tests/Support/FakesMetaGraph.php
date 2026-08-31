<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Advertising\Providers\Meta\MetaAdvertisingProvider;
use App\Domains\Advertising\Providers\Meta\MetaConfig;
use App\Domains\Advertising\Providers\Meta\MetaErrorMapper;
use App\Domains\Advertising\Providers\Meta\MetaGraphClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Builds the live Meta adapter against a faked Graph API.
 *
 * There are no Meta credentials in this environment and there must never be
 * (spec §64), so every test drives the adapter through Laravel's HTTP fake.
 * That exercises everything the platform actually owns — the request shapes,
 * the error mapping, the pagination, the idempotency lookup — and stops at the
 * boundary where Meta's own behaviour begins.
 *
 * What it cannot prove is that Meta agrees with the request shapes. That needs
 * a real app and real credentials, and is called out in the deployment notes.
 */
trait FakesMetaGraph
{
    protected function metaAdapter(): MetaAdvertisingProvider
    {
        $config = $this->metaConfig();

        return new MetaAdvertisingProvider(
            $config,
            new MetaGraphClient($config, new MetaErrorMapper),
        );
    }

    protected function metaConfig(): MetaConfig
    {
        return new MetaConfig(
            appId: '1234567890',
            // Obviously fake, and never a real secret shape.
            appSecret: 'test-app-secret',
            apiVersion: 'v21.0',
            graphUrl: 'https://graph.facebook.test',
            dialogUrl: 'https://www.facebook.test',
            redirectUri: 'https://ads360.test/app/assets/callback/meta',
            scopes: ['ads_management', 'ads_read'],
            requestTimeout: 5,
            connectTimeout: 2,
            // One attempt, so a test asserting a failure is not waiting out
            // three rounds of backoff.
            maxAttempts: 1,
            retryDelayMilliseconds: 0,
            webhookVerifyToken: 'test-verify-token',
            businessId: '999888777',
            systemUserToken: 'test-system-user-token',
        );
    }

    /**
     * @param  array<string, mixed>  $responses  keyed by URL pattern
     */
    protected function fakeGraph(array $responses): void
    {
        Http::fake($responses);
    }

    /** A Meta error envelope, as the Graph API returns one. */
    protected function metaError(int $code, string $message, int $subcode = 0): array
    {
        return [
            'error' => array_filter([
                'message' => $message,
                'type' => 'OAuthException',
                'code' => $code,
                'error_subcode' => $subcode ?: null,
                'fbtrace_id' => 'TESTTRACE123',
            ], static fn ($value): bool => $value !== null),
        ];
    }

    /**
     * Every request the adapter made must carry its token as a header, never
     * in the URL — query strings end up in access logs and proxy logs
     * (Rule 12).
     */
    protected function assertTokenNeverInQueryString(string $token): void
    {
        Http::assertSent(function (Request $request) use ($token): bool {
            $this->assertStringNotContainsString(
                $token,
                $request->url(),
                'A token reached the URL: '.$request->url(),
            );

            return true;
        });
    }

    /**
     * @return list<Request>
     */
    protected function recordedRequests(): array
    {
        return Http::recorded()->map(static fn (array $pair): Request => $pair[0])->all();
    }
}
