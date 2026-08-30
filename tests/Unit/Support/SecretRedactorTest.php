<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Domains\Audit\Services\SecretRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Redaction of secrets before they reach durable storage (Rule 12).
 */
final class SecretRedactorTest extends TestCase
{
    private SecretRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redactor = new SecretRedactor;
    }

    /**
     * @return list<array{string}>
     */
    public static function sensitiveKeys(): array
    {
        return [
            ['password'],
            ['password_confirmation'],
            ['current_password'],
            ['two_factor_secret'],
            ['two_factor_recovery_codes'],
            ['access_token'],
            ['refresh_token'],
            ['client_secret'],
            ['api_key'],
            ['apiKey'],
            ['Authorization'],
            ['private_key'],
            ['card_number'],
            ['cvv'],
            ['otp'],
            ['webhook_signature'],
            ['remember_token'],
        ];
    }

    #[Test]
    #[DataProvider('sensitiveKeys')]
    public function it_redacts_sensitive_keys(string $key): void
    {
        $result = $this->redactor->redact([$key => 'the-actual-secret']);

        $this->assertSame(SecretRedactor::REDACTED, $result[$key]);
        $this->assertStringNotContainsString('the-actual-secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_redacts_a_sensitive_value_nested_inside_ordinary_data(): void
    {
        $result = $this->redactor->redact([
            'provider' => [
                'name' => 'META',
                'connection' => [
                    'access_token' => 'EAAG-secret',
                    'expires_at' => '2026-01-01',
                ],
            ],
        ]);

        $this->assertSame('META', $result['provider']['name']);
        $this->assertSame(SecretRedactor::REDACTED, $result['provider']['connection']['access_token']);
        $this->assertSame('2026-01-01', $result['provider']['connection']['expires_at']);
    }

    #[Test]
    public function a_sensitively_named_branch_is_replaced_whole(): void
    {
        // The key itself marks the subtree as secret, so the entire branch is
        // dropped rather than walked. Losing the shape of a credential bag in
        // an audit record is the right trade against leaking part of it.
        $result = $this->redactor->redact([
            'provider' => 'META',
            'credentials' => ['access_token' => 'EAAG-secret', 'scopes' => ['ads_read']],
        ]);

        $this->assertSame('META', $result['provider']);
        $this->assertSame(SecretRedactor::REDACTED, $result['credentials']);
        $this->assertStringNotContainsString('EAAG-secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_leaves_ordinary_values_untouched(): void
    {
        $input = [
            'name' => 'Demo Retail Ltd',
            'status' => 'ACTIVE',
            'amount' => 1250,
            'verified' => true,
            'notes' => null,
        ];

        $this->assertSame($input, $this->redactor->redact($input));
    }

    #[Test]
    public function key_matching_is_case_insensitive_and_matches_substrings(): void
    {
        $this->assertTrue($this->redactor->isSensitive('PASSWORD'));
        $this->assertTrue($this->redactor->isSensitive('user_Access_Token_value'));
        $this->assertFalse($this->redactor->isSensitive('organization_name'));
    }

    #[Test]
    public function it_preserves_list_structure(): void
    {
        $result = $this->redactor->redact([
            'items' => [
                ['id' => 1, 'token' => 'secret-one'],
                ['id' => 2, 'token' => 'secret-two'],
            ],
        ]);

        $this->assertCount(2, $result['items']);
        $this->assertSame(1, $result['items'][0]['id']);
        $this->assertSame(SecretRedactor::REDACTED, $result['items'][0]['token']);
        $this->assertSame(SecretRedactor::REDACTED, $result['items'][1]['token']);
    }
}
