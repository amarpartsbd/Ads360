<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Advertising\Providers\Google\GoogleAdsClient;
use App\Domains\Advertising\Providers\Google\GoogleAdsConfig;
use App\Domains\Advertising\Providers\Google\GoogleAdsErrorMapper;
use App\Domains\Advertising\Providers\Google\GoogleAdsProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Builds the live Google Ads adapter against a faked API.
 *
 * There are no Google credentials in this environment and there must never be
 * (spec §64), so every test drives the adapter through Laravel's HTTP fake.
 * That exercises everything the platform actually owns — the GAQL it writes,
 * the micros conversion, the error mapping, the idempotency lookup and the
 * duplicate-name recovery — and stops at the boundary where Google's own
 * behaviour begins.
 *
 * What it cannot prove is that Google agrees with the request shapes, or that
 * `LIKE` on a campaign name filters the way the idempotency lookup assumes.
 * That needs a real developer token and a real account, and is called out in
 * the deployment notes.
 */
trait FakesGoogleAds
{
    protected function googleAdapter(): GoogleAdsProvider
    {
        $config = $this->googleConfig();

        return new GoogleAdsProvider(
            $config,
            new GoogleAdsClient($config, new GoogleAdsErrorMapper),
        );
    }

    protected function googleConfig(): GoogleAdsConfig
    {
        return new GoogleAdsConfig(
            clientId: '1234.apps.googleusercontent.com',
            // Obviously fake, and never a real secret shape.
            clientSecret: 'test-client-secret',
            developerToken: 'test-developer-token',
            apiVersion: 'v21',
            apiUrl: 'https://googleads.googleapis.test',
            authUrl: 'https://accounts.google.test/o/oauth2/v2/auth',
            tokenUrl: 'https://oauth2.googleapis.test/token',
            userInfoUrl: 'https://openidconnect.googleapis.test/v1/userinfo',
            redirectUri: 'https://ads360.test/app/assets/callback/google',
            scopes: [GoogleAdsConfig::ADS_SCOPE, 'openid', 'email'],
            requestTimeout: 5,
            connectTimeout: 2,
            // One attempt, so a test asserting a failure is not waiting out
            // three rounds of backoff.
            maxAttempts: 1,
            retryDelayMilliseconds: 0,
            loginCustomerId: '9998887776',
            refreshToken: 'test-platform-refresh-token',
        );
    }

    /**
     * Fake the Ads API with the platform's own token exchange already stubbed.
     *
     * Managed ad accounts have no client grant behind them, so every call the
     * adapter makes on the platform's behalf begins by exchanging the
     * platform's refresh token. A test about publishing should not have to
     * restate that.
     *
     * @param  array<string, mixed>  $responses  keyed by URL pattern
     */
    protected function fakeGoogle(array $responses): void
    {
        Http::fake(['*/token' => Http::response([
            'access_token' => 'platform-access-token',
            'expires_in' => 3599,
        ])] + $responses);
    }

    /**
     * A GoogleAdsFailure envelope, as the API returns one.
     *
     * The `errorCode` is a single-key object whose *key* names the error's
     * family — which is the shape the mapper reads.
     *
     * @return array<string, mixed>
     */
    protected function googleError(string $family, string $code, string $message = 'It went wrong'): array
    {
        return [
            'error' => [
                'code' => 400,
                'message' => $message,
                'status' => 'INVALID_ARGUMENT',
                'details' => [[
                    '@type' => 'type.googleapis.com/google.ads.googleads.v21.errors.GoogleAdsFailure',
                    'errors' => [[
                        'errorCode' => [$family => $code],
                        'message' => $message,
                    ]],
                    'requestId' => 'TESTREQUEST123',
                ]],
            ],
        ];
    }

    /**
     * A `googleAds:search` page.
     *
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    protected function googleSearch(array $results, ?string $nextPageToken = null): array
    {
        return array_filter([
            'results' => $results,
            'nextPageToken' => $nextPageToken,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * A `:mutate` response.
     *
     * @return array<string, mixed>
     */
    protected function googleMutated(string $resourceName): array
    {
        return ['results' => [['resourceName' => $resourceName]]];
    }

    /**
     * Every request the adapter made must carry its token as a header, never
     * in the URL — query strings end up in access logs and proxy logs
     * (Rule 12).
     */
    protected function assertGoogleTokenNeverInQueryString(string $token): void
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
    protected function recordedGoogleRequests(): array
    {
        return Http::recorded()->map(static fn (array $pair): Request => $pair[0])->all();
    }

    /**
     * The GAQL queries the adapter sent, in order.
     *
     * @return list<string>
     */
    protected function sentQueries(): array
    {
        $queries = [];

        foreach ($this->recordedGoogleRequests() as $request) {
            $query = $request->data()['query'] ?? null;

            if (is_string($query)) {
                $queries[] = $query;
            }
        }

        return $queries;
    }

    /**
     * The mutate payloads the adapter sent, keyed in order.
     *
     * @return list<array<string, mixed>>
     */
    protected function sentMutations(): array
    {
        $mutations = [];

        foreach ($this->recordedGoogleRequests() as $request) {
            if (! str_contains($request->url(), ':mutate')) {
                continue;
            }

            $mutations[] = ['url' => $request->url(), 'body' => $request->data()];
        }

        return $mutations;
    }
}
