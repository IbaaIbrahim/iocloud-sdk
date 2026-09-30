<?php

namespace IOCloud\Laravel\Tests;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use IOCloud\Laravel\Data\SubjectTokenClaimNames;
use IOCloud\Laravel\Data\TenantProfile;
use IOCloud\Laravel\Exceptions\IOCloudFederationException;
use IOCloud\Laravel\Exceptions\IOCloudTokenExchangeException;
use IOCloud\Laravel\Federation\FederationSigningKey;
use IOCloud\Laravel\Federation\SubjectTokenIssuer;
use IOCloud\Laravel\IOCloudClient;
use IOCloud\Laravel\Tests\Support\JwsVerifier;

final class ClientFederationTest extends TestCase
{
    private const PARTNER_TOKEN_BODY = [
        'data' => [
            'token' => [
                'access_token' => 'partner-token',
                'token_type' => 'Bearer',
                'expires_at' => '2099-01-01T00:00:00Z',
            ],
        ],
    ];

    private const APPLICATION_UUID = '11111111-1111-4111-8111-111111111111';

    private const TENANT_UUID = '22222222-2222-4222-8222-222222222222';

    private const TENANT_NOT_CREATED = "The token's tenant could not be created.";

    private const TENANT_PLAN_MISSING = "The token's tenant plan does not exist.";

    private const PROVIDER_BODY = [
        'uuid' => '4be507fc-2a1b-4e19-9f0e-2c7f7f5f8a11',
        'application_uuid' => self::APPLICATION_UUID,
        'name' => 'Acme Portal',
        'issuer' => 'https://portal.acme.example',
        'jwks_url' => 'https://portal.acme.example/.well-known/jwks.json',
        'allowed_audiences' => ['ai-ecosystem'],
        'allowed_algorithms' => ['RS256'],
        'token_max_age_seconds' => 900,
        'require_email_verified' => true,
        'user_claim' => 'sub',
        'tenant_claim' => 'tenant_id',
        'email_claim' => 'email',
        'name_claim' => 'name',
        'allow_jit_users' => true,
        'status' => 'active',
        'created_at' => '2026-07-09T10:15:00Z',
    ];

    private const SESSION_BODY = [
        'access_token' => 'platform-session-token',
        'issued_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'user_uuid' => '992d64fc-8f2a-4c31-b7e5-1d0a6c9f3b48',
        'name' => 'Test User',
        'email' => 'user@customer.example',
    ];

    public function test_it_derives_the_jwks_url_from_the_issuer(): void
    {
        Http::fake([
            'api.example.com/v1/partner/auth/token' => Http::response(self::PARTNER_TOKEN_BODY),
            'api.example.com/v1/partner/federation/providers' => Http::response(
                ['data' => ['provider' => self::PROVIDER_BODY]],
                201,
            ),
        ]);

        $provider = $this->client()->createIdentityProvider(
            applicationUuid: self::APPLICATION_UUID,
            name: 'Acme Portal',
            issuer: 'https://portal.acme.example/',
            allowedAudiences: ['ai-ecosystem'],
            requireEmailVerified: true,
            allowJitUsers: true,
        );

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/v1/partner/federation/providers')) {
                return false;
            }
            $body = $request->data();

            return $body['application_uuid'] === self::APPLICATION_UUID
                && $body['issuer'] === 'https://portal.acme.example'
                && $body['jwks_url'] === 'https://portal.acme.example/.well-known/jwks.json'
                && $body['allowed_algorithms'] === ['RS256']
                && $body['token_max_age_seconds'] === 900
                && $body['require_email_verified'] === true
                && $body['allow_jit_users'] === true
                && $request->hasHeader('Authorization', 'Bearer partner-token');
        });
        $this->assertSame(self::PROVIDER_BODY['uuid'], $provider->uuid);
        $this->assertSame(self::APPLICATION_UUID, $provider->applicationUuid);
        $this->assertTrue($provider->isActive());
        $this->assertSame('tenant_id', $provider->claimNames->tenant);
    }

    public function test_it_registers_the_claim_names_the_issuer_will_emit(): void
    {
        Http::fake([
            'api.example.com/v1/partner/auth/token' => Http::response(self::PARTNER_TOKEN_BODY),
            'api.example.com/v1/partner/federation/providers' => Http::response(
                ['data' => ['provider' => self::PROVIDER_BODY]],
                201,
            ),
        ]);

        $this->client()->createIdentityProvider(
            applicationUuid: self::APPLICATION_UUID,
            name: 'Acme Portal',
            issuer: 'https://portal.acme.example',
            allowedAudiences: ['ai-ecosystem'],
            claimNames: new SubjectTokenClaimNames(user: 'user_id', tenant: 'org_id'),
        );

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/v1/partner/federation/providers')) {
                return false;
            }
            $body = $request->data();

            return $body['user_claim'] === 'user_id'
                && $body['tenant_claim'] === 'org_id'
                && $body['email_claim'] === 'email'
                && $body['name_claim'] === 'name';
        });
    }

    public function test_a_positional_call_from_before_0_6_0_still_sends_its_claim_names(): void
    {
        // allowJitTenants was added last, so $claimNames stayed the tenth argument.
        Http::fake([
            'api.example.com/v1/partner/auth/token' => Http::response(self::PARTNER_TOKEN_BODY),
            'api.example.com/v1/partner/federation/providers' => Http::response(
                ['data' => ['provider' => self::PROVIDER_BODY]],
                201,
            ),
        ]);

        $this->client()->createIdentityProvider(
            self::APPLICATION_UUID,
            'Acme Portal',
            'https://portal.acme.example',
            ['ai-ecosystem'],
            null,
            ['RS256'],
            900,
            false,
            true,
            new SubjectTokenClaimNames(user: 'user_id', tenant: 'org_id'),
        );

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/v1/partner/federation/providers')) {
                return false;
            }
            $body = $request->data();

            return $body['tenant_claim'] === 'org_id'
                && $body['allow_jit_users'] === true
                && $body['allow_jit_tenants'] === false;
        });
    }

    public function test_it_sends_allow_jit_tenants_false_by_default(): void
    {
        Http::fake([
            'api.example.com/v1/partner/auth/token' => Http::response(self::PARTNER_TOKEN_BODY),
            'api.example.com/v1/partner/federation/providers' => Http::response(
                ['data' => ['provider' => self::PROVIDER_BODY]],
                201,
            ),
        ]);

        $this->client()->createIdentityProvider(
            applicationUuid: self::APPLICATION_UUID,
            name: 'Acme Portal',
            issuer: 'https://portal.acme.example',
            allowedAudiences: ['ai-ecosystem'],
        );

        Http::assertSent(fn (Request $request): bool =>
            str_contains($request->url(), '/v1/partner/federation/providers')
            && $request->data()['allow_jit_tenants'] === false);
    }

    public function test_it_sends_allow_jit_tenants_when_asked_and_reads_it_back(): void
    {
        Http::fake([
            'api.example.com/v1/partner/auth/token' => Http::response(self::PARTNER_TOKEN_BODY),
            'api.example.com/v1/partner/federation/providers' => Http::response(
                ['data' => ['provider' => [...self::PROVIDER_BODY, 'allow_jit_tenants' => true]]],
                201,
            ),
        ]);

        $provider = $this->client()->createIdentityProvider(
            applicationUuid: self::APPLICATION_UUID,
            name: 'Acme Portal',
            issuer: 'https://portal.acme.example',
            allowedAudiences: ['ai-ecosystem'],
            allowJitUsers: true,
            allowJitTenants: true,
        );

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/v1/partner/federation/providers')) {
                return false;
            }
            $body = $request->data();

            return $body['allow_jit_users'] === true
                && $body['allow_jit_tenants'] === true;
        });
        $this->assertTrue($provider->allowJitTenants);
    }

    public function test_a_provider_without_allow_jit_tenants_reads_as_false(): void
    {
        // PROVIDER_BODY is what a platform that predates just-in-time tenants
        // sends: no allow_jit_tenants member at all.
        Http::fake([
            'api.example.com/v1/partner/auth/token' => Http::response(self::PARTNER_TOKEN_BODY),
            'api.example.com/v1/partner/federation/providers' => Http::response(
                ['data' => ['providers' => [self::PROVIDER_BODY]]],
            ),
        ]);

        $providers = $this->client()->listIdentityProviders();

        $this->assertFalse($providers[0]->allowJitTenants);
    }

    public function test_listing_providers_sends_a_get_with_no_request_body(): void
    {
        Http::fake([
            'api.example.com/v1/partner/auth/token' => Http::response(self::PARTNER_TOKEN_BODY),
            'api.example.com/v1/partner/federation/providers' => Http::response(
                ['data' => ['providers' => [self::PROVIDER_BODY]]],
            ),
        ]);

        $providers = $this->client()->listIdentityProviders();

        Http::assertSent(fn (Request $request): bool =>
            ! str_contains($request->url(), '/v1/partner/federation/providers')
            || ($request->method() === 'GET' && $request->body() === ''));
        $this->assertCount(1, $providers);
        $this->assertSame('Acme Portal', $providers[0]->name);
        $this->assertSame(self::APPLICATION_UUID, $providers[0]->applicationUuid);
    }

    public function test_it_posts_the_rfc_8693_form_grammar_without_a_partner_token(): void
    {
        Http::fake([
            'api.example.com/v1/federation/token' => Http::response(self::SESSION_BODY),
        ]);

        $session = $this->client()->exchangeSubjectToken('signed.jwt.value');

        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $form);

            return str_contains($request->url(), '/v1/federation/token')
                && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
                && ! $request->hasHeader('Authorization')
                && $form['grant_type'] === IOCloudClient::TOKEN_EXCHANGE_GRANT_TYPE
                && $form['subject_token'] === 'signed.jwt.value'
                && $form['subject_token_type'] === IOCloudClient::JWT_TOKEN_TYPE;
        });
        Http::assertSentCount(1);
        $this->assertSame('platform-session-token', $session->accessToken);
        $this->assertSame(3600, $session->expiresIn);
        $this->assertSame(self::SESSION_BODY['user_uuid'], $session->userUuid);
        $this->assertGreaterThan(time(), $session->expiresAt->getTimestamp());
    }

    public function test_a_session_names_its_tenant_and_whether_this_login_created_it(): void
    {
        Http::fake([
            'api.example.com/v1/federation/token' => Http::response([
                ...self::SESSION_BODY,
                'tenant_uuid' => self::TENANT_UUID,
                'tenant_created' => true,
            ]),
        ]);

        $session = $this->client()->exchangeSubjectToken('signed.jwt.value');

        $this->assertSame(self::TENANT_UUID, $session->tenantUuid);
        $this->assertTrue($session->tenantCreated);
    }

    public function test_a_platform_without_the_tenant_members_reads_as_null_and_false(): void
    {
        // SESSION_BODY is what a platform that predates just-in-time tenants
        // sends: neither tenant_uuid nor tenant_created.
        Http::fake([
            'api.example.com/v1/federation/token' => Http::response(self::SESSION_BODY),
        ]);

        $session = $this->client()->exchangeSubjectToken('signed.jwt.value');

        $this->assertNull($session->tenantUuid);
        $this->assertFalse($session->tenantCreated);
    }

    public function test_a_rejected_subject_token_raises_the_rfc_6749_error(): void
    {
        Http::fake([
            'api.example.com/v1/federation/token' => Http::response([
                'error' => 'invalid_target',
                'error_description' => "The token's tenant is not mapped.",
            ], 400),
        ]);

        try {
            $this->client()->exchangeSubjectToken('signed.jwt.value');
            $this->fail('expected the exchange to be rejected');
        } catch (IOCloudTokenExchangeException $exception) {
            $this->assertSame('invalid_target', $exception->error);
            $this->assertSame(
                "The token's tenant is not mapped.",
                $exception->errorDescription,
            );
            $this->assertSame(400, $exception->statusCode);
        }
    }

    public function test_federated_login_signs_and_exchanges_in_one_call(): void
    {
        Http::fake([
            'api.example.com/v1/federation/token' => Http::response(self::SESSION_BODY),
        ]);
        $signingKey = FederationSigningKey::generate();
        $client = $this->client(new SubjectTokenIssuer(
            signingKey: $signingKey,
            issuer: 'https://portal.acme.example',
            audience: 'ai-ecosystem',
        ));

        $session = $client->federatedLogin(
            subject: 'acme-user-1',
            externalTenantId: 'acme-tenant-1',
            email: 'user@customer.example',
            name: 'Test User',
            emailVerified: true,
        );

        Http::assertSent(function (Request $request) use ($signingKey): bool {
            parse_str($request->body(), $form);
            $verified = JwsVerifier::verify($form['subject_token'], $signingKey->jwks());

            return $verified['header']['kid'] === $signingKey->kid()
                && $verified['claims']['sub'] === 'acme-user-1'
                && $verified['claims']['tenant_id'] === 'acme-tenant-1'
                && $verified['claims']['email_verified'] === true
                && ! array_key_exists('tenant_profile', $verified['claims']);
        });
        $this->assertSame('platform-session-token', $session->accessToken);
    }

    public function test_federated_login_passes_the_tenant_profile_into_the_token(): void
    {
        Http::fake([
            'api.example.com/v1/federation/token' => Http::response([
                ...self::SESSION_BODY,
                'tenant_uuid' => self::TENANT_UUID,
                'tenant_created' => true,
            ]),
        ]);
        $signingKey = FederationSigningKey::generate();
        $client = $this->client(new SubjectTokenIssuer(
            signingKey: $signingKey,
            issuer: 'https://portal.acme.example',
            audience: 'ai-ecosystem',
        ));

        $session = $client->federatedLogin(
            subject: 'acme-user-1',
            externalTenantId: 'acme-tenant-1',
            email: 'user@customer.example',
            tenant: new TenantProfile(name: 'Acme Ltd', contactEmail: 'ops@acme.example'),
        );

        Http::assertSent(function (Request $request) use ($signingKey): bool {
            parse_str($request->body(), $form);
            $claims = JwsVerifier::verify($form['subject_token'], $signingKey->jwks())['claims'];

            return $claims['tenant_id'] === 'acme-tenant-1'
                && $claims['tenant_profile'] === [
                    'name' => 'Acme Ltd',
                    'contact_email' => 'ops@acme.example',
                ];
        });
        $this->assertSame(self::TENANT_UUID, $session->tenantUuid);
        $this->assertTrue($session->tenantCreated);
    }

    public function test_a_tenant_profile_carrying_the_external_tenant_id_names_the_tenant(): void
    {
        Http::fake(['api.example.com/v1/federation/token' => Http::response(self::SESSION_BODY)]);
        $signingKey = FederationSigningKey::generate();
        $client = $this->client(new SubjectTokenIssuer(
            signingKey: $signingKey,
            issuer: 'https://portal.acme.example',
            audience: 'ai-ecosystem',
        ));

        $client->federatedLogin(
            subject: 'acme-user-1',
            email: 'user@customer.example',
            tenant: new TenantProfile(name: 'Acme Ltd', externalTenantId: 'acme-tenant-1'),
        );

        Http::assertSent(function (Request $request) use ($signingKey): bool {
            parse_str($request->body(), $form);
            $claims = JwsVerifier::verify($form['subject_token'], $signingKey->jwks())['claims'];

            return $claims['tenant_id'] === 'acme-tenant-1'
                && $claims['tenant_profile'] === ['name' => 'Acme Ltd'];
        });
    }

    public function test_a_tenant_the_platform_could_not_create_refuses_the_login(): void
    {
        Http::fake([
            'api.example.com/v1/federation/token' => Http::response([
                'error' => 'invalid_target',
                'error_description' => self::TENANT_NOT_CREATED,
            ], 400),
        ]);
        $client = $this->client(new SubjectTokenIssuer(
            signingKey: FederationSigningKey::generate(),
            issuer: 'https://portal.acme.example',
            audience: 'ai-ecosystem',
        ));

        try {
            $client->federatedLogin(
                subject: 'acme-user-1',
                externalTenantId: 'acme-tenant-1',
                email: 'user@customer.example',
                tenant: new TenantProfile(name: 'Acme Ltd'),
            );
            $this->fail('expected the exchange to be rejected');
        } catch (IOCloudTokenExchangeException $exception) {
            $this->assertSame(400, $exception->statusCode);
            $this->assertSame('invalid_target', $exception->error);
            $this->assertSame(self::TENANT_NOT_CREATED, $exception->errorDescription);
        }
    }

    public function test_a_plan_code_no_plan_has_refuses_the_login(): void
    {
        Http::fake([
            'api.example.com/v1/federation/token' => Http::response([
                'error' => 'invalid_target',
                'error_description' => self::TENANT_PLAN_MISSING,
            ], 400),
        ]);
        $signingKey = FederationSigningKey::generate();
        $client = $this->client(new SubjectTokenIssuer(
            signingKey: $signingKey,
            issuer: 'https://portal.acme.example',
            audience: 'ai-ecosystem',
        ));

        try {
            $client->federatedLogin(
                subject: 'acme-user-1',
                externalTenantId: 'acme-tenant-1',
                email: 'user@customer.example',
                tenant: new TenantProfile(name: 'Acme Ltd', planCode: 'no-such-plan'),
            );
            $this->fail('expected the exchange to be rejected');
        } catch (IOCloudTokenExchangeException $exception) {
            $this->assertSame(400, $exception->statusCode);
            $this->assertSame('invalid_target', $exception->error);
            $this->assertSame(self::TENANT_PLAN_MISSING, $exception->errorDescription);
        }
        Http::assertSent(function (Request $request) use ($signingKey): bool {
            parse_str($request->body(), $form);
            $claims = JwsVerifier::verify($form['subject_token'], $signingKey->jwks())['claims'];

            return $claims['tenant_profile']['plan_code'] === 'no-such-plan';
        });
    }

    public function test_federated_login_reports_a_missing_issuer_before_any_request(): void
    {
        Http::fake();

        $this->expectException(IOCloudFederationException::class);

        try {
            $this->client()->federatedLogin(
                subject: 'user-1',
                externalTenantId: 'tenant-1',
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_an_empty_subject_token_never_reaches_the_network(): void
    {
        Http::fake();

        try {
            $this->client()->exchangeSubjectToken('   ');
            $this->fail('expected a validation failure');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public function test_a_container_client_stays_usable_without_federation_config(): void
    {
        Http::fake([
            'api.example.com/v1/partner/auth/token' => Http::response(self::PARTNER_TOKEN_BODY),
        ]);

        // The base TestCase configures no federation, so resolving the client must
        // not try to load a signing key that does not exist.
        $token = $this->app->make(IOCloudClient::class)->issuePartnerToken();

        $this->assertSame('partner-token', $token->accessToken);
    }

    private function client(?SubjectTokenIssuer $tokenIssuer = null): IOCloudClient
    {
        return new IOCloudClient(
            http: $this->app->make(HttpFactory::class),
            clientId: 'client-id',
            clientSecret: 'client-secret',
            baseUrl: 'https://api.example.com',
            tokenIssuer: $tokenIssuer,
        );
    }
}
