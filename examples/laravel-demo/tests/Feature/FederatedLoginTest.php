<?php

namespace Tests\Feature;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IOCloud\Laravel\IOCloudClient;
use Tests\TestCase;

/**
 * End-to-end exercise of the SDK from a partner's point of view.
 *
 * The fake IOCloud in these tests does what the real platform does: it fetches
 * the portal's own JWKS endpoint and verifies the subject token against it with
 * an independent JWT library. A signing, JWKS, or claim-mapping bug in the SDK
 * fails these tests.
 */
final class FederatedLoginTest extends TestCase
{
    private const TOKEN_ENDPOINT = 'api.iocloud.test/v1/federation/token';

    private const SESSION_BODY = [
        'access_token' => 'platform-session-token',
        'issued_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'user_uuid' => '992d64fc-8f2a-4c31-b7e5-1d0a6c9f3b48',
        'name' => 'Dana Okafor',
        'email' => 'dana.okafor@acme.example',
    ];

    public function test_a_portal_login_produces_a_platform_session(): void
    {
        Http::fake([self::TOKEN_ENDPOINT => Http::response(self::SESSION_BODY)]);

        $response = $this->post('/federated-login', ['subject' => 'acme-user-1001']);

        $response->assertOk();
        $response->assertSee('platform-session-token');
        $response->assertSee('992d64fc-8f2a-4c31-b7e5-1d0a6c9f3b48');
    }

    public function test_the_subject_token_verifies_against_the_published_jwks(): void
    {
        Http::fake([self::TOKEN_ENDPOINT => Http::response(self::SESSION_BODY)]);

        $this->post('/federated-login', ['subject' => 'acme-user-1001'])->assertOk();

        $claims = $this->claimsOfSentSubjectToken();
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

    public function test_the_exchange_uses_the_rfc_8693_form_grammar(): void
    {
        Http::fake([self::TOKEN_ENDPOINT => Http::response(self::SESSION_BODY)]);

        $this->post('/federated-login', ['subject' => 'acme-user-1001'])->assertOk();

        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $form);

            return $request->url() === 'https://api.iocloud.test/v1/federation/token'
                && $request->method() === 'POST'
                && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
                && ! $request->hasHeader('Authorization')
                && $form['grant_type'] === IOCloudClient::TOKEN_EXCHANGE_GRANT_TYPE
                && $form['subject_token_type'] === IOCloudClient::JWT_TOKEN_TYPE;
        });
        // No partner token is fetched: the subject token is the credential.
        Http::assertSentCount(1);
    }

    public function test_each_login_signs_a_distinct_token_so_replay_is_impossible(): void
    {
        Http::fake([self::TOKEN_ENDPOINT => Http::response(self::SESSION_BODY)]);

        $this->post('/federated-login', ['subject' => 'acme-user-1001'])->assertOk();
        $this->post('/federated-login', ['subject' => 'acme-user-1001'])->assertOk();

        $tokenIds = array_map(
            fn (object $claims): string => $claims->jti,
            $this->allSentSubjectTokenClaims(),
        );
        $this->assertCount(2, $tokenIds);
        $this->assertNotSame($tokenIds[0], $tokenIds[1]);
    }

    public function test_an_unverified_email_is_signed_as_unverified(): void
    {
        Http::fake([self::TOKEN_ENDPOINT => Http::response(self::SESSION_BODY)]);

        $this->post('/federated-login', ['subject' => 'acme-user-1003'])->assertOk();

        // The SDK reports what the portal knows; the platform decides whether a
        // provider with require_email_verified accepts it.
        $this->assertFalse($this->claimsOfSentSubjectToken()->email_verified);
    }

    public function test_a_rejected_exchange_is_shown_with_its_rfc_error(): void
    {
        Http::fake([
            self::TOKEN_ENDPOINT => Http::response([
                'error' => 'invalid_target',
                'error_description' => "The token's tenant is not mapped to a tenant on this platform.",
            ], 400),
        ]);

        $response = $this->post('/federated-login', ['subject' => 'acme-user-1001']);

        $response->assertOk();
        $response->assertSee('invalid_target');
        $response->assertSee('not mapped', escape: false);
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
     * Verify the sent subject token the way the platform does: fetch the portal's
     * JWKS over its own HTTP endpoint, then verify with an independent library.
     */
    private function claimsOfSentSubjectToken(): object
    {
        $allClaims = $this->allSentSubjectTokenClaims();
        $this->assertNotEmpty($allClaims, 'no subject token was exchanged');

        return $allClaims[0];
    }

    /** @return list<object> */
    private function allSentSubjectTokenClaims(): array
    {
        /** @var array{keys: list<array<string, string>>} $jwks */
        $jwks = $this->getJson('/.well-known/jwks.json')->json();
        $keys = JWK::parseKeySet($jwks);

        $claims = [];
        foreach (Http::recorded() as [$request]) {
            if (! str_contains($request->url(), '/v1/federation/token')) {
                continue;
            }
            parse_str($request->body(), $form);
            $claims[] = JWT::decode((string) $form['subject_token'], $keys);
        }

        return $claims;
    }
}
