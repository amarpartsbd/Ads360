<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers\Meta;

use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Every call to Meta's Graph API goes through here (spec §26, §29).
 *
 * Concentrating the transport in one class buys three things that would
 * otherwise be scattered across every method of the adapter:
 *
 *   - **Tokens stay out of URLs.** Meta accepts `?access_token=`, and it is
 *     the usual example in their docs, but a token in a query string ends up
 *     in access logs, proxy logs and exception traces. Every request here
 *     sends it as a bearer header instead (Rule 12).
 *   - **One error vocabulary.** Meta's envelope is decoded and mapped once, so
 *     callers deal in ProviderUnavailable and never in raw error codes.
 *   - **Retries that know what they are retrying.** Only a transport failure
 *     or a rate limit is retried; a refusal is returned immediately, because
 *     retrying a policy decision is what §27 forbids.
 */
final class MetaGraphClient
{
    /**
     * The token is a constructor parameter rather than a per-call argument so
     * a caller cannot accidentally pass the wrong client's credentials, and is
     * marked sensitive so it is redacted from stack traces.
     */
    public function __construct(
        private readonly MetaConfig $config,
        private readonly MetaErrorMapper $errors,
        #[SensitiveParameter]
        private readonly ?string $accessToken = null,
    ) {}

    /** A client for a different token — a client's grant, say. */
    public function withToken(#[SensitiveParameter] ?string $token): self
    {
        return new self($this->config, $this->errors, $token);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws ProviderUnavailable
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ProviderUnavailable
     */
    public function post(string $path, array $payload = []): array
    {
        return $this->send('post', $path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ProviderUnavailable
     */
    public function delete(string $path, array $payload = []): array
    {
        return $this->send('delete', $path, $payload);
    }

    /**
     * Upload a file — a creative image — as multipart.
     *
     * @param  resource  $stream
     * @return array<string, mixed>
     *
     * @throws ProviderUnavailable
     */
    public function upload(string $path, string $field, $stream, string $filename): array
    {
        try {
            $response = $this->request()
                ->attach($field, $stream, $filename)
                ->post($this->url($path));
        } catch (ConnectionException $exception) {
            throw $this->errors->transport('upload: '.$exception->getMessage());
        }

        return $this->decode($response, $path);
    }

    /**
     * Walk a cursor-paginated edge and return every node.
     *
     * Bounded on purpose. An account with tens of thousands of objects would
     * otherwise walk until it timed out, and no screen in this platform needs
     * an unbounded list — a client with more assets than this has a
     * conversation with us, not a longer page.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     *
     * @throws ProviderUnavailable
     */
    public function paginate(string $path, array $query = [], int $maxPages = 10): array
    {
        $nodes = [];
        $page = $this->get($path, $query);

        for ($visited = 0; $visited < $maxPages; $visited++) {
            foreach ($page['data'] ?? [] as $node) {
                if (is_array($node)) {
                    $nodes[] = $node;
                }
            }

            $next = $page['paging']['cursors']['after'] ?? null;

            // Meta signals "no more" by omitting `paging.next`, not by an
            // empty cursor — a cursor is present on the last page too.
            if (! isset($page['paging']['next']) || ! is_string($next)) {
                break;
            }

            $page = $this->get($path, [...$query, 'after' => $next]);
        }

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ProviderUnavailable
     */
    private function send(string $method, string $path, array $data): array
    {
        try {
            $response = $this->request()->{$method}($this->url($path), $data);
        } catch (ConnectionException $exception) {
            // Never completed, so it says nothing about the request itself.
            throw $this->errors->transport($method.' '.$path.': '.$exception->getMessage());
        }

        return $this->decode($response, $path);
    }

    private function request(): PendingRequest
    {
        $request = Http::asForm()
            ->acceptJson()
            ->timeout($this->config->requestTimeout)
            ->connectTimeout($this->config->connectTimeout)
            /*
             * Retries only the failures where a retry is meaningful. `throw:
             * false` keeps a 4xx out of the retry loop entirely — Meta
             * refusing something will refuse it again, and hammering it is
             * both pointless and a good way to earn a rate limit.
             */
            ->retry(
                $this->config->maxAttempts,
                $this->config->retryDelayMilliseconds,
                fn (\Throwable $exception): bool => $exception instanceof ConnectionException,
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
            rtrim($this->config->graphUrl, '/'),
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
     * Records the failure with Meta's trace id, which is the first thing their
     * support asks for. The request body is not logged: it can carry a
     * client's copy, and the token is never in it anyway (Rule 12).
     *
     * @param  array<string, mixed>  $error
     */
    private function log(string $path, array $error, int $status): void
    {
        Log::warning('Meta Graph API call failed', [
            'path' => $path,
            'status' => $status,
            'code' => $error['code'] ?? null,
            'subcode' => $error['error_subcode'] ?? null,
            'type' => $error['type'] ?? null,
            'fbtrace_id' => $error['fbtrace_id'] ?? null,
            'message' => $error['message'] ?? null,
        ]);
    }
}
