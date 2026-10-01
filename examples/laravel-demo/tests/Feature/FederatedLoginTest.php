<?php

namespace Tests\Feature;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * End-to-end exercise of the SDK from a partner's point of view.
 *
 * The portal signs a subject token and shows it; exchanging it is the frontend's
 * chat client's job, so the portal itself sends nothing to IOCloud. These tests
 * do with that token what the real platform does: fetch the portal's own JWKS
 * endpoint and verify the token against it with an independent JWT library. A
 * signing, JWKS, or claim-mapping bug in the SDK fails these tests.
 */
final class FederatedLoginTest extends TestCase
{
    public function test_a_portal_login_shows_a_signed_subject_token_and_sends_nothing(): void
    {
        Http::fake();

        $response = $this->post('/federated-login', ['subject' => 'acme-user-1001']);

        $response->assertOk();
        $this->assertSame('acme-user-1001', $this->claimsOfShownSubjectToken($response)->sub);
        // The chat client exchanges the token, not the portal.
        Http::assertNothingSent();
    }

    public function test_the_subject_token_verifies_against_the_published_jwks(): void
    {
        $response = $this->post('/federated-login', ['subject' => 'acme-user-1001'])->assertOk();

        $claims = $this->claimsOfShownSubjectToken($response);
        $this->assertSame(self::ISSUER, $claims->iss);
        $this->assertSame(self::AUDIENCE, $claims->aud);
        $this->assertSame('acme-user-1001', $claims->sub);
        $this->assertSame('acme-tenant-1', $claims->tenant_id);
        $this->assertSame('dana.okafor@acme.example', $claims->email);
        $this->assertTrue($claims->email_verified);
        $this->assertSame('Dana Okafor', $claims->name);
        $this->assertNotEmpty($claims->jti);
        $this->assertSame(300, $claims->exp - $claims->iat);
    }

    public function test_each_login_signs_a_distinct_token_so_replay_is_impossible(): void
    {
        $first = $this->post('/federated-login', ['subject' => 'acme-user-1001'])->assertOk();
        $second = $this->post('/federated-login', ['subject' => 'acme-user-1001'])->assertOk();

        $this->assertNotSame(
            $this->claimsOfShownSubjectToken($first)->jti,
            $this->claimsOfShownSubjectToken($second)->jti,
        );
    }

    public function test_an_unverified_email_is_signed_as_unverified(): void
    {
        $response = $this->post('/federated-login', ['subject' => 'acme-user-1003'])->assertOk();

        // The SDK reports what the portal knows; the platform decides whether a
        // provider with require_email_verified accepts it.
        $this->assertFalse($this->claimsOfShownSubjectToken($response)->email_verified);
    }

    public function test_a_login_without_a_signing_key_fails_before_any_request(): void
    {
        Http::fake();
        config([
            'iocloud.federation.private_key' => null,
            'iocloud.federation.private_key_path' => null,
        ]);

        $response = $this->post('/federated-login', ['subject' => 'acme-user-1001']);

        $response->assertOk();
        $response->assertSee('federation_not_configured');
        Http::assertNothingSent();
    }

    public function test_an_unknown_subject_is_rejected_before_any_request(): void
    {
        Http::fake();

        $this->post('/federated-login', ['subject' => 'nobody'])->assertServerError();

        Http::assertNothingSent();
    }

    public function test_a_missing_subject_fails_validation(): void
    {
        Http::fake();

        $this->post('/federated-login', [])->assertSessionHasErrors('subject');

        Http::assertNothingSent();
    }

    /**
     * Verify the token the page shows the way the platform does: fetch the
     * portal's JWKS over its own HTTP endpoint, then verify with an independent
     * library.
     */
    private function claimsOfShownSubjectToken(TestResponse $response): object
    {
        $found = preg_match(
            '/<code class="wrap">([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)<\/code>/',
            (string) $response->getContent(),
            $match,
        );
        $this->assertSame(1, $found, 'the page shows no subject token');

        /** @var array{keys: list<array<string, string>>} $jwks */
        $jwks = $this->getJson('/.well-known/jwks.json')->json();

        return JWT::decode($match[1], JWK::parseKeySet($jwks));
    }
}
