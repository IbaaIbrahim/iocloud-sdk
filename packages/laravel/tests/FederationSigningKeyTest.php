<?php

namespace IOCloud\Laravel\Tests;

use IOCloud\Laravel\Exceptions\IOCloudFederationException;
use IOCloud\Laravel\Federation\FederationSigningKey;
use IOCloud\Laravel\Tests\Support\JwsVerifier;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

final class FederationSigningKeyTest extends PHPUnitTestCase
{
    public function test_public_jwk_carries_the_fields_a_verifier_needs(): void
    {
        $jwk = FederationSigningKey::generate()->publicJwk();

        $this->assertSame('RSA', $jwk['kty']);
        $this->assertSame('sig', $jwk['use']);
        $this->assertSame('RS256', $jwk['alg']);
        $this->assertSame('AQAB', $jwk['e']);
        $this->assertNotEmpty($jwk['kid']);
        $this->assertStringNotContainsString('=', $jwk['n']);
        $this->assertStringNotContainsString('+', $jwk['n']);
        $this->assertStringNotContainsString('/', $jwk['n']);
    }

    public function test_jwks_publishes_only_the_public_half(): void
    {
        $jwks = FederationSigningKey::generate()->jwks();

        $this->assertCount(1, $jwks['keys']);
        $this->assertSame(
            ['alg', 'e', 'kid', 'kty', 'n', 'use'],
            $this->sortedKeys($jwks['keys'][0]),
        );
    }

    public function test_kid_is_derived_from_the_key_so_it_survives_a_reload(): void
    {
        $key = FederationSigningKey::generate();

        $reloaded = FederationSigningKey::fromPrivateKeyPem($key->privateKeyPem());

        $this->assertSame($key->kid(), $reloaded->kid());
        $this->assertSame($key->publicJwk(), $reloaded->publicJwk());
    }

    public function test_distinct_keys_get_distinct_kids(): void
    {
        $this->assertNotSame(
            FederationSigningKey::generate()->kid(),
            FederationSigningKey::generate()->kid(),
        );
    }

    public function test_loading_a_non_pem_value_reports_a_federation_error(): void
    {
        $this->expectException(IOCloudFederationException::class);

        FederationSigningKey::fromPrivateKeyPem('not-a-key');
    }

    public function test_signed_tokens_verify_against_the_published_jwks(): void
    {
        // Repeated because an RSA signature carries a leading zero byte roughly
        // one time in 256, which a length-trimming encoder would corrupt.
        $key = FederationSigningKey::generate();

        for ($attempt = 0; $attempt < 32; $attempt++) {
            $token = $key->sign(['sub' => "user-{$attempt}", 'iat' => time()]);

            $verified = JwsVerifier::verify($token, $key->jwks());

            $this->assertSame('RS256', $verified['header']['alg']);
            $this->assertSame($key->kid(), $verified['header']['kid']);
            $this->assertSame('JWT', $verified['header']['typ']);
            $this->assertSame("user-{$attempt}", $verified['claims']['sub']);
        }
    }

    public function test_a_token_from_another_key_is_not_in_the_published_jwks(): void
    {
        $published = FederationSigningKey::generate();
        $stranger = FederationSigningKey::generate();

        $this->assertFalse(
            JwsVerifier::hasKey($published->jwks(), $stranger->kid())
        );
    }

    public function test_build_jwks_publishes_every_key_in_a_rotation(): void
    {
        $current = FederationSigningKey::generate();
        $retiring = FederationSigningKey::generate();

        $jwks = FederationSigningKey::buildJwks($current, $retiring);

        $this->assertSame(
            [$current->kid(), $retiring->kid()],
            array_column($jwks['keys'], 'kid'),
        );
    }

    public function test_build_jwks_rejects_an_empty_key_set(): void
    {
        $this->expectException(IOCloudFederationException::class);

        FederationSigningKey::buildJwks();
    }

    /**
     * @param array<string, string> $jwk
     * @return list<string>
     */
    private function sortedKeys(array $jwk): array
    {
        $keys = array_keys($jwk);
        sort($keys);

        return $keys;
    }
}
