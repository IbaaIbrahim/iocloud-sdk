<?php

namespace Tests\Feature;

use Firebase\JWT\JWK;
use Tests\TestCase;

/**
 * The portal registers no JWKS route — the IOCloud SDK does. These tests hold
 * that promise, and check the document is usable by a standard JWKS consumer.
 */
final class JwksEndpointTest extends TestCase
{
    public function test_the_sdk_publishes_the_portal_key_set(): void
    {
        $response = $this->getJson('/.well-known/jwks.json');

        $response->assertOk();
        $response->assertExactJson($this->signingKey->jwks());
    }

    public function test_the_document_is_parseable_by_a_standard_jwks_consumer(): void
    {
        /** @var array{keys: list<array<string, string>>} $jwks */
        $jwks = $this->getJson('/.well-known/jwks.json')->json();

        $keys = JWK::parseKeySet($jwks);

        $this->assertArrayHasKey($this->signingKey->kid(), $keys);
    }

    public function test_no_private_key_material_is_ever_published(): void
    {
        $body = (string) $this->get('/.well-known/jwks.json')->getContent();

        $this->assertStringNotContainsString('PRIVATE', $body);
        // RSA private JWK members, none of which belong in a published key set.
        foreach (['"d"', '"p"', '"q"', '"dp"', '"dq"', '"qi"'] as $privateMember) {
            $this->assertStringNotContainsString($privateMember, $body);
        }
    }
}
