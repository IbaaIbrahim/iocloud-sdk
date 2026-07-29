<?php

namespace IOCloud\Laravel\Tests;

use IOCloud\Laravel\Data\SubjectTokenClaimNames;
use IOCloud\Laravel\Exceptions\IOCloudFederationException;
use IOCloud\Laravel\Federation\FederationSigningKey;
use IOCloud\Laravel\Federation\SubjectTokenIssuer;
use IOCloud\Laravel\Tests\Support\JwsVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

final class SubjectTokenIssuerTest extends PHPUnitTestCase
{
    private const ISSUER = 'https://portal.acme.example';

    private const AUDIENCE = 'ai-ecosystem';

    private FederationSigningKey $signingKey;

    private SubjectTokenIssuer $issuer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signingKey = FederationSigningKey::generate();
        $this->issuer = new SubjectTokenIssuer(
            signingKey: $this->signingKey,
            issuer: self::ISSUER,
            audience: self::AUDIENCE,
            tokenTtlSeconds: 300,
        );
    }

    public function test_token_verifies_against_the_published_jwks(): void
    {
        $token = $this->issuer->issue(subject: 'user-1', externalTenantId: 'tenant-1');

        $claims = JwsVerifier::verify($token, $this->issuer->jwks())['claims'];

        $this->assertSame(self::ISSUER, $claims['iss']);
        $this->assertSame(self::AUDIENCE, $claims['aud']);
        $this->assertSame('user-1', $claims['sub']);
        $this->assertSame('tenant-1', $claims['tenant_id']);
    }

    public function test_claims_cover_the_platform_validation_rules(): void
    {
        $token = $this->issuer->issue(
            subject: 'user-1',
            externalTenantId: 'tenant-1',
            email: 'user@customer.example',
            name: 'Test User',
            emailVerified: true,
        );

        $claims = JwsVerifier::verify($token, $this->issuer->jwks())['claims'];

        $this->assertSame('user@customer.example', $claims['email']);
        $this->assertTrue($claims['email_verified']);
        $this->assertSame('Test User', $claims['name']);
        $this->assertSame(300, $claims['exp'] - $claims['iat']);
        $this->assertSame($claims['iat'], $claims['nbf']);
        $this->assertNotEmpty($claims['jti']);
    }

    public function test_every_token_gets_a_unique_jti_for_replay_protection(): void
    {
        $first = $this->claimsOf($this->issuer->issue('user-1', 'tenant-1'));
        $second = $this->claimsOf($this->issuer->issue('user-1', 'tenant-1'));

        $this->assertNotSame($first['jti'], $second['jti']);
    }

    public function test_email_verified_is_absent_when_no_email_is_supplied(): void
    {
        $claims = $this->claimsOf($this->issuer->issue('user-1', 'tenant-1'));

        $this->assertArrayNotHasKey('email', $claims);
        $this->assertArrayNotHasKey('email_verified', $claims);
    }

    public function test_trailing_slash_is_stripped_so_iss_matches_byte_for_byte(): void
    {
        $issuer = new SubjectTokenIssuer(
            signingKey: $this->signingKey,
            issuer: self::ISSUER.'/',
            audience: self::AUDIENCE,
        );

        $this->assertSame(self::ISSUER, $issuer->issuer());
        $this->assertSame(self::ISSUER.'/.well-known/jwks.json', $issuer->jwksUrl());
    }

    public function test_claim_names_are_configurable_per_provider(): void
    {
        $issuer = $this->issuer->withClaimNames(
            new SubjectTokenClaimNames(user: 'user_id', tenant: 'org_id')
        );

        $claims = JwsVerifier::verify(
            $issuer->issue('user-1', 'org-1'),
            $issuer->jwks(),
        )['claims'];

        $this->assertSame('user-1', $claims['user_id']);
        $this->assertSame('org-1', $claims['org_id']);
        $this->assertArrayNotHasKey('sub', $claims);
    }

    public function test_extra_claims_are_added_to_the_token(): void
    {
        $claims = $this->claimsOf($this->issuer->issue(
            subject: 'user-1',
            externalTenantId: 'tenant-1',
            extraClaims: ['scope' => 'jobs:create'],
        ));

        $this->assertSame('jobs:create', $claims['scope']);
    }

    #[DataProvider('reservedClaims')]
    public function test_extra_claims_cannot_override_issuer_controlled_claims(
        string $reservedClaim,
    ): void {
        $this->expectException(IOCloudFederationException::class);

        $this->issuer->issue(
            subject: 'user-1',
            externalTenantId: 'tenant-1',
            extraClaims: [$reservedClaim => 'attacker'],
        );
    }

    /** @return array<string, array{string}> */
    public static function reservedClaims(): array
    {
        return [
            'iss' => ['iss'],
            'aud' => ['aud'],
            'exp' => ['exp'],
            'iat' => ['iat'],
            'nbf' => ['nbf'],
            'jti' => ['jti'],
        ];
    }

    public function test_a_blank_subject_is_rejected_at_the_boundary(): void
    {
        $this->expectException(IOCloudFederationException::class);

        $this->issuer->issue(subject: '  ', externalTenantId: 'tenant-1');
    }

    public function test_a_blank_external_tenant_id_is_rejected_at_the_boundary(): void
    {
        $this->expectException(IOCloudFederationException::class);

        $this->issuer->issue(subject: 'user-1', externalTenantId: '');
    }

    public function test_a_blank_issuer_is_rejected_on_construction(): void
    {
        $this->expectException(IOCloudFederationException::class);

        new SubjectTokenIssuer(
            signingKey: $this->signingKey,
            issuer: ' ',
            audience: self::AUDIENCE,
        );
    }

    public function test_a_non_positive_ttl_is_rejected_on_construction(): void
    {
        $this->expectException(IOCloudFederationException::class);

        new SubjectTokenIssuer(
            signingKey: $this->signingKey,
            issuer: self::ISSUER,
            audience: self::AUDIENCE,
            tokenTtlSeconds: 0,
        );
    }

    /** @return array<string, mixed> */
    private function claimsOf(string $token): array
    {
        return JwsVerifier::verify($token, $this->issuer->jwks())['claims'];
    }
}
