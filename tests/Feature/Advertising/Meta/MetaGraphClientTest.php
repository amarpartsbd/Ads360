<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising\Meta;

use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Providers\Meta\MetaErrorMapper;
use App\Domains\Advertising\Providers\Meta\MetaGraphClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesMetaGraph;
use Tests\TestCase;

/**
 * The Graph API transport (spec §29, §80, Rule 12).
 *
 * These tests are about what the platform does with what Meta says — the
 * mapping from error codes to retryable or not, the pagination, and the
 * handling of credentials. They cannot prove Meta agrees with the request
 * shapes; only real credentials can do that.
 */
final class MetaGraphClientTest extends TestCase
{
    use FakesMetaGraph;

    private function client(?string $token = 'test-access-token'): MetaGraphClient
    {
        return new MetaGraphClient($this->metaConfig(), new MetaErrorMapper, $token);
    }

    #[Test]
    public function the_api_version_is_pinned_in_every_url(): void
    {
        Http::fake(['*' => Http::response(['id' => '1'])]);

        $this->client()->get('me');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v21.0/me'));
    }

    #[Test]
    public function the_token_travels_as_a_header_and_never_in_the_url(): void
    {
        Http::fake(['*' => Http::response(['id' => '1'])]);

        $this->client('super-secret-token')->get('me', ['fields' => 'id']);

        $this->assertTokenNeverInQueryString('super-secret-token');

        Http::assertSent(
            fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer super-secret-token'),
        );
    }

    #[Test]
    #[DataProvider('errorCodes')]
    public function meta_error_codes_map_to_the_right_kind_of_failure(
        int $code,
        int $status,
        bool $retryable,
    ): void {
        Http::fake(['*' => Http::response($this->metaError($code, 'Something went wrong'), $status)]);

        try {
            $this->client()->get('me');
            $this->fail('The call should have thrown.');
        } catch (ProviderUnavailable $exception) {
            $this->assertSame(
                $retryable,
                $exception->retryable,
                "Code {$code} should ".($retryable ? '' : 'not ').'be retryable.',
            );
        }
    }

    /**
     * @return array<string, array{int, int, bool}>
     */
    public static function errorCodes(): array
    {
        return [
            // Worth trying again.
            'app rate limit' => [4, 400, true],
            'user rate limit' => [17, 400, true],
            'too many calls' => [613, 400, true],
            'temporary issue' => [2, 500, true],
            'server error' => [0, 503, true],

            // Not worth trying again with the same token.
            'invalid token' => [190, 400, false],
            'session expired' => [463, 400, false],

            // A refusal on Meta's own terms, never worked around (§27).
            'permission missing' => [200, 403, false],
            'invalid parameter' => [100, 400, false],
        ];
    }

    #[Test]
    public function a_rate_limit_is_reported_as_such_rather_than_as_a_refusal(): void
    {
        Http::fake(['*' => Http::response($this->metaError(4, 'Application request limit reached'), 400)]);

        try {
            $this->client()->get('me');
            $this->fail('The call should have thrown.');
        } catch (ProviderUnavailable $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertStringContainsString('limiting how quickly', $exception->clientMessage);
        }
    }

    #[Test]
    public function a_provider_error_code_never_reaches_the_client_message(): void
    {
        Http::fake(['*' => Http::response($this->metaError(100, '(#100) Invalid parameter'), 400)]);

        try {
            $this->client()->get('me');
            $this->fail('The call should have thrown.');
        } catch (ProviderUnavailable $exception) {
            $this->assertStringNotContainsString('#100', $exception->clientMessage);
            // The detail is kept for the log, where it belongs.
            $this->assertStringContainsString('trace=TESTTRACE123', $exception->getMessage());
        }
    }

    #[Test]
    public function a_message_meta_wrote_for_an_end_user_is_passed_through(): void
    {
        Http::fake(['*' => Http::response([
            'error' => [
                'message' => '(#1487742) Ad account has hit its spending limit',
                'code' => 1487742,
                'error_user_title' => 'Spending limit reached',
                'error_user_msg' => 'This ad account has reached the spending limit you set.',
                'fbtrace_id' => 'TESTTRACE123',
            ],
        ], 400)]);

        try {
            $this->client()->get('me');
            $this->fail('The call should have thrown.');
        } catch (ProviderUnavailable $exception) {
            // Meta wrote it for a person; nothing we could write would be better.
            $this->assertStringContainsString('spending limit you set', $exception->clientMessage);
        }
    }

    #[Test]
    public function an_error_object_inside_a_two_hundred_is_still_an_error(): void
    {
        // Meta occasionally returns an error envelope with a 200 status.
        Http::fake(['*' => Http::response($this->metaError(190, 'Invalid token'), 200)]);

        $this->expectException(ProviderUnavailable::class);

        $this->client()->get('me');
    }

    #[Test]
    public function pagination_follows_cursors_and_stops_when_meta_stops(): void
    {
        Http::fakeSequence()
            ->push([
                'data' => [['id' => '1'], ['id' => '2']],
                'paging' => ['cursors' => ['after' => 'CURSOR_ONE'], 'next' => 'https://next'],
            ])
            ->push([
                'data' => [['id' => '3']],
                // No `next`, so this is the last page even though a cursor is
                // present — which it always is.
                'paging' => ['cursors' => ['after' => 'CURSOR_TWO']],
            ]);

        $nodes = $this->client()->paginate('me/adaccounts');

        $this->assertCount(3, $nodes);
        $this->assertSame('3', $nodes[2]['id']);
    }

    #[Test]
    public function pagination_is_bounded_so_a_huge_account_cannot_hang_the_worker(): void
    {
        // A provider that always says there is another page.
        Http::fake(['*' => Http::response([
            'data' => [['id' => 'x']],
            'paging' => ['cursors' => ['after' => 'ENDLESS'], 'next' => 'https://next'],
        ])]);

        $nodes = $this->client()->paginate('me/adaccounts', maxPages: 3);

        $this->assertCount(3, $nodes);
    }

    #[Test]
    public function a_connection_failure_is_transient_rather_than_a_refusal(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out'));

        try {
            $this->client()->get('me');
            $this->fail('The call should have thrown.');
        } catch (ProviderUnavailable $exception) {
            // It never completed, so it says nothing about the request.
            $this->assertTrue($exception->retryable);
        }
    }

    #[Test]
    public function a_refusal_is_not_retried(): void
    {
        Http::fake(['*' => Http::response($this->metaError(100, 'Invalid parameter'), 400)]);

        try {
            $this->client()->get('me');
        } catch (ProviderUnavailable) {
            // Expected.
        }

        // Exactly one attempt: hammering a decision Meta has already made is
        // both pointless and a good way to earn a rate limit.
        Http::assertSentCount(1);
    }
}
