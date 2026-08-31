<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers\Google;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Every call to the Google Ads API goes through here (spec §26, §29).
 *
 * The same reasons as the Meta client — tokens out of URLs, one error
 * vocabulary, retries that know what they are retrying — plus three that are
 * specific to Google:
 *
 *   - **Two credentials, not one.** Every request carries both an OAuth
 *     bearer token (who is asking) and a developer token (which application
 *     is asking). Google rejects a request missing either, and the developer
 *     token belongs to the platform rather than to any client, so it is
 *     attached here rather than passed around.
 *   - **A manager header.** `login-customer-id` names the manager account the
 *     platform is acting through, which is how a managed ad account is
 *     reached at all (spec §17). It is omitted where it would be wrong —
 *     listing what a *user* can access is a question about the user.
 *   - **A query language.** Reads are GAQL sent to `googleAds:search`, not
 *     field lists on a REST path. `escape()` exists because a GAQL literal is
 *     interpolated into that query, and a reference containing a quote would
 *     otherwise change the query's meaning.
 */
final class GoogleAdsClient
{
    /**
     * Google's own ceiling for a search page. Asking for more is an error,
     * and asking for fewer just means more round trips.
     */
    private const MAX_PAGE_SIZE = 10000;

    public function __construct(
        private readonly GoogleAdsConfig $config,
        private readonly GoogleAdsErrorMapper $errors,
        #[SensitiveParameter]
        private readonly ?string $accessToken = null,
        /**
         * Overrides the configured manager account for these calls. Null means
         * "use the platform's own manager", which is right for the managed
         * inventory and wrong for a client's own grant — see
         * withManagerAccount().
         */
        private readonly ?string $managerId = null,
    ) {}

    /** A client for a different token — a client's grant, say. */
    public function withToken(#[SensitiveParameter] ?string $token): self
    {
        return new self($this->config, $this->errors, $token, $this->managerId);
    }

    /**
     * A client that acts through a different manager account.
     *
     * `login-customer-id` tells Google which manager account the request is
     * made through, and getting it wrong is a permission error rather than a
     * wrong answer. The platform's own manager is right for managed inventory
     * (spec §17) and wrong for a client's own grant: their account is not
     * under our manager, and naming ours would have Google refuse a request
     * the client is perfectly entitled to make. Callers acting on a client's
     * behalf pass the customer they are querying.
     */
    public function withManagerAccount(?string $customerId): self
    {
        return new self(
            $this->config,
            $this->errors,
            $this->accessToken,
            GoogleAdsConfig::digits($customerId),
        );
    }

    /**
     * Run a GAQL query and return every row across every page.
     *
     * Bounded on purpose, exactly as the Meta client's pagination is: an
     * unbounded walk over a large account would time out rather than finish,
     * and nothing in this platform needs a longer page than this.
     *
     * @return list<array<string, mixed>>
     *
     * @throws ProviderUnavailable
     */
    public function search(
        string $customerId,
        string $query,
        int $pageSize = 1000,
        int $maxPages = 10,
    ): array {
        $customer = $this->customer($customerId);
        $rows = [];
        $pageToken = null;

        for ($visited = 0; $visited < $maxPages; $visited++) {
            $payload = array_filter([
                'query' => $query,
                'pageSize' => min($pageSize, self::MAX_PAGE_SIZE),
                'pageToken' => $pageToken,
            ], static fn (mixed $value): bool => $value !== null);

            $page = $this->send('post', "customers/{$customer}/googleAds:search", $payload);

            foreach ((array) ($page['results'] ?? []) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            $pageToken = $page['nextPageToken'] ?? null;

            // Google omits the token entirely on the last page, rather than
            // returning an empty one.
            if (! is_string($pageToken) || $pageToken === '') {
                break;
            }
        }

        return $rows;
    }

    /**
     * The first row of a query, or null.
     *
     * @return array<string, mixed>|null
     *
     * @throws ProviderUnavailable
     */
    public function searchOne(string $customerId, string $query): ?array
    {
        $rows = $this->search($customerId, $query, pageSize: 1, maxPages: 1);

        return $rows[0] ?? null;
    }

    /**
     * Apply mutate operations to one resource collection and return the
     * results Google reports.
     *
     * `partialFailure` is deliberately absent, which means false. A partial
     * failure would have Google apply some of a campaign's structure and
     * refuse the rest, leaving a half-built campaign that the platform's own
     * publication ledger would record as a success. All or nothing is the only
     * safe shape here (Rule 15).
     *
     * @param  list<array<string, mixed>>  $operations
     * @return list<array<string, mixed>>
     *
     * @throws ProviderUnavailable
     */
    public function mutate(string $customerId, string $resource, array $operations): array
    {
        $customer = $this->customer($customerId);

        $response = $this->send('post', "customers/{$customer}/{$resource}:mutate", [
            'operations' => $operations,
        ]);

        $results = [];

        foreach ((array) ($response['results'] ?? []) as $result) {
            if (is_array($result)) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * One create or update, returning the resource name Google assigned.
     *
     * @param  array<string, mixed>  $operation
     *
     * @throws ProviderUnavailable
     */
    public function mutateOne(string $customerId, string $resource, array $operation): string
    {
        $results = $this->mutate($customerId, $resource, [$operation]);

        $resourceName = $results[0]['resourceName'] ?? null;

        if (! is_string($resourceName) || $resourceName === '') {
            throw ProviderUnavailable::transient(
                Provider::Google,
                "the {$resource} mutate returned no resource name",
            );
        }

        return $resourceName;
    }

    /**
     * The customer accounts the authenticated user can reach.
     *
     * Sent without the manager header: this is a question about the user who
     * granted us access, and scoping it to a manager account would answer a
     * different question.
     *
     * @return list<string> customer ids, digits only
     *
     * @throws ProviderUnavailable
     */
    public function listAccessibleCustomers(): array
    {
        $response = $this->send('get', 'customers:listAccessibleCustomers', [], withManager: false);

        $customers = [];

        foreach ((array) ($response['resourceNames'] ?? []) as $name) {
            $id = GoogleAdsConfig::digits(is_string($name) ? basename($name) : null);

            if ($id !== null) {
                $customers[] = $id;
            }
        }

        return $customers;
    }

    /**
     * A call to Google's OAuth service.
     *
     * Separate from `send()` because it goes to a different host, carries no
     * developer token, and fails with a different envelope entirely — mapping
     * it through the Ads error mapper would produce nonsense.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ProviderUnavailable
     */
    public function token(array $payload): array
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout($this->config->requestTimeout)
                ->connectTimeout($this->config->connectTimeout)
                ->retry(
                    $this->config->maxAttempts,
                    $this->config->retryDelayMilliseconds,
                    static fn (\Throwable $exception): bool => $exception instanceof ConnectionException,
                    throw: false,
                )
                ->post($this->config->tokenUrl, $payload);
        } catch (ConnectionException $exception) {
            throw $this->errors->transport('oauth token: '.$exception->getMessage());
        }

        $body = $response->json();

        if (! is_array($body)) {
            $body = [];
        }

        if ($response->successful() && ! isset($body['error'])) {
            return $body;
        }

        // The payload is never logged: it holds the client secret and the
        // refresh token (Rule 12).
        Log::warning('Google OAuth token call failed', [
            'status' => $response->status(),
            'error' => $body['error'] ?? null,
        ]);

        throw $this->errors->oauth($body, $response->status());
    }

    /**
     * Who the grant belongs to, from Google's OpenID endpoint.
     *
     * Not an Ads call: different host, no developer token, no manager header.
     * It returns `sub` — a stable, opaque identifier for the Google account —
     * and the email address, and nothing about advertising.
     *
     * @return array<string, mixed>
     *
     * @throws ProviderUnavailable
     */
    public function userInfo(#[SensitiveParameter] string $accessToken): array
    {
        try {
            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->timeout($this->config->requestTimeout)
                ->connectTimeout($this->config->connectTimeout)
                ->retry(
                    $this->config->maxAttempts,
                    $this->config->retryDelayMilliseconds,
                    static fn (\Throwable $exception): bool => $exception instanceof ConnectionException,
                    throw: false,
                )
                ->get($this->config->userInfoUrl);
        } catch (ConnectionException $exception) {
            throw $this->errors->transport('userinfo: '.$exception->getMessage());
        }

        $body = $response->json();

        if (! is_array($body)) {
            $body = [];
        }

        if ($response->successful() && ! isset($body['error'])) {
            return $body;
        }

        throw $this->errors->oauth(
            is_array($body['error'] ?? null) ? $body['error'] : $body,
            $response->status(),
        );
    }

    /**
     * Escape a value for interpolation into a GAQL string literal.
     *
     * GAQL literals are single-quoted and use backslash escapes. A campaign
     * name carrying an apostrophe would otherwise terminate the literal and
     * turn the rest of the name into query syntax — which, for a lookup that
     * decides whether to create a second campaign, is a correctness problem
     * and not only an injection one.
     */
    public static function escape(string $value): string
    {
        return str_replace(
            ['\\', "'", '"', "\n", "\r", "\t"],
            ['\\\\', "\\'", '\\"', ' ', ' ', ' '],
            $value,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ProviderUnavailable
     */
    private function send(string $method, string $path, array $data, bool $withManager = true): array
    {
        try {
            $response = $this->request($withManager)->{$method}($this->url($path), $data);
        } catch (ConnectionException $exception) {
            // Never completed, so it says nothing about the request itself.
            throw $this->errors->transport($method.' '.$path.': '.$exception->getMessage());
        }

        return $this->decode($response, $path);
    }

    private function request(bool $withManager): PendingRequest
    {
        $headers = ['developer-token' => $this->config->developerToken];

        $manager = $this->managerId ?? $this->config->loginCustomerId;

        if ($withManager && $manager !== null) {
            $headers['login-customer-id'] = $manager;
        }

        $request = Http::asJson()
            ->acceptJson()
            ->withHeaders($headers)
            ->timeout($this->config->requestTimeout)
            ->connectTimeout($this->config->connectTimeout)
            /*
             * Same policy as the Meta client: only a transport failure is
             * retried here. A 4xx from Google is a decision, and re-sending it
             * would spend quota to be refused identically.
             */
            ->retry(
                $this->config->maxAttempts,
                $this->config->retryDelayMilliseconds,
                static fn (\Throwable $exception): bool => $exception instanceof ConnectionException,
                throw: false,
            );

        if ($this->accessToken !== null) {
            // A header, not a query parameter: query strings are logged.
            $request = $request->withToken($this->accessToken);
        }

        return $request;
    }

    private function url(string $path): string
    {
        return sprintf(
            '%s/%s/%s',
            rtrim($this->config->apiUrl, '/'),
            $this->config->apiVersion,
            ltrim($path, '/'),
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ProviderUnavailable
     */
    private function decode(Response $response, string $path): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            $body = [];
        }

        if ($response->successful() && ! isset($body['error'])) {
            return $body;
        }

        $error = is_array($body['error'] ?? null) ? $body['error'] : [];

        $this->log($path, $error, $response->status());

        throw $this->errors->map($error, $response->status());
    }

    /**
     * Records the failure with Google's request id, which is what their
     * support asks for first. The request body is not logged: it can carry a
     * client's ad copy, and the tokens are never in it anyway (Rule 12).
     *
     * @param  array<string, mixed>  $error
     */
    private function log(string $path, array $error, int $status): void
    {
        Log::warning('Google Ads API call failed', [
            'path' => $path,
            'status' => $status,
            'grpc_status' => $error['status'] ?? null,
            'codes' => $this->errors->codes($this->errors->failures($error)),
            'request_id' => $this->errors->requestId($error),
            'message' => $error['message'] ?? null,
        ]);
    }

    /**
     * @throws ProviderUnavailable when the id is not a customer id at all,
     *                             rather than sending Google a malformed path
     */
    private function customer(string $customerId): string
    {
        $digits = GoogleAdsConfig::digits($customerId);

        if ($digits === null) {
            throw ProviderUnavailable::refused(
                Provider::Google,
                "[{$customerId}] is not a Google Ads customer id",
                'That does not look like a Google Ads account number.',
            );
        }

        return $digits;
    }
}
