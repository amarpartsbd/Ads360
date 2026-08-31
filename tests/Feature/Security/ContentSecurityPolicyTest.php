<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The content security policy, against the page it actually governs (spec §53).
 *
 * Asserted end to end rather than on the header alone, because the header being
 * correct is not the property that matters. The policy refuses inline scripts,
 * the page emits exactly one, and if those two facts are not reconciled the
 * browser renders a blank page and says why only in its console — which is what
 * shipped, because the policy is off wherever APP_DEBUG is true and every test
 * and every developer machine runs with it off.
 */
final class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // On in production and off in development, so the only way to test the
        // production behaviour is to ask for it.
        config(['platform.security.content_security_policy' => true]);
    }

    #[Test]
    public function every_inline_script_on_the_page_carries_the_nonce_the_policy_names(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        $nonce = $this->nonceFromPolicy($response->headers->get('Content-Security-Policy'));
        $html = $response->getContent();

        $this->assertIsString($html);

        preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*>/i', $html, $inlineScripts);

        $this->assertNotEmpty(
            $inlineScripts[0],
            'The page emits no inline script at all, so this test is no longer testing anything.'
        );

        foreach ($inlineScripts[0] as $tag) {
            $this->assertStringContainsString(
                'nonce="'.$nonce.'"',
                $tag,
                "An inline script carries no nonce and would be blocked in production: {$tag}"
            );
        }
    }

    /**
     * Ziggy's route table is the inline script in question. Named explicitly so
     * that removing the nonce from it fails here rather than in a browser.
     *
     * Two forms, because Ziggy writes the whole table the first time it renders
     * in a process and merges into the existing one after that — and a test
     * suite renders many pages in one process, so which form appears depends on
     * test order rather than on anything this application controls.
     */
    #[Test]
    public function the_ziggy_route_table_is_nonced(): void
    {
        $response = $this->get('/');

        $html = (string) $response->getContent();
        $nonce = $this->nonceFromPolicy($response->headers->get('Content-Security-Policy'));

        $this->assertMatchesRegularExpression(
            '/<script[^>]*nonce="'.preg_quote($nonce, '/').'"[^>]*>'
            .'(const Ziggy=|Object\.assign\(Ziggy)/',
            $html,
        );
    }

    /**
     * A nonce reused across requests is a nonce an attacker can read from one
     * page and reuse on the next, which is most of the way to no nonce at all.
     */
    #[Test]
    public function the_nonce_is_different_on_every_request(): void
    {
        $first = $this->nonceFromPolicy(
            $this->get('/')->headers->get('Content-Security-Policy')
        );
        $second = $this->nonceFromPolicy(
            $this->get('/')->headers->get('Content-Security-Policy')
        );

        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function the_policy_still_refuses_inline_scripts_it_did_not_name(): void
    {
        $policy = (string) $this->get('/')->headers->get('Content-Security-Policy');

        // `unsafe-inline` would admit an injected script as readily as our own,
        // and is the shortcut this fix exists to avoid.
        $this->assertStringNotContainsString('unsafe-inline', $this->scriptSrc($policy));
        $this->assertStringContainsString("'self'", $this->scriptSrc($policy));
    }

    #[Test]
    public function the_policy_is_absent_when_it_is_switched_off(): void
    {
        config(['platform.security.content_security_policy' => false]);

        $this->get('/')->assertHeaderMissing('Content-Security-Policy');
    }

    private function nonceFromPolicy(?string $policy): string
    {
        $this->assertNotNull($policy, 'No Content-Security-Policy header was sent.');

        preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", (string) $policy, $matches);

        $this->assertArrayHasKey(1, $matches, "No nonce in the policy: {$policy}");

        return $matches[1];
    }

    private function scriptSrc(string $policy): string
    {
        foreach (explode('; ', $policy) as $directive) {
            if (str_starts_with($directive, 'script-src ')) {
                return $directive;
            }
        }

        $this->fail("No script-src directive in the policy: {$policy}");
    }
}
