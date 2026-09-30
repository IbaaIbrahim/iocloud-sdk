<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The one-time setup path: the portal tells IOCloud which issuer to trust for
 * which application, and which IOCloud tenant carries its organisation id.
 */
final class RegisterFederationCommandTest extends TestCase
{
    private const PARTNER_TOKEN_ENDPOINT = 'api.iocloud.test/v1/partner/auth/token';

    private const PROVIDERS_ENDPOINT = 'api.iocloud.test/v1/partner/federation/providers';

    private const TENANTS_ENDPOINT = 'api.iocloud.test/v1/partner/tenants';

    private const APPLICATION_UUID = '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';

    private const TENANT_UUID = 'ab8c1f2e-3d45-4a67-8b90-1c2d3e4f5a6b';

    private const PROVIDER_UUID = '4be507fc-2a1b-4e19-9f0e-2c7f7f5f8a11';

    public function test_it_registers_the_issuer_the_sdk_actually_signs_with(): void
    {
        $this->fakeIOCloud(existingProviders: []);

        $this->artisan('demo:federation:register', ['--tenant' => self::TENANT_UUID])
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST'
                || ! str_ends_with($request->url(), '/v1/partner/federation/providers')) {
                return false;
            }
            $body = $request->data();

            return $body['application_uuid'] === self::APPLICATION_UUID
                && $body['issuer'] === self::ISSUER
                && $body['jwks_url'] === self::ISSUER.'/.well-known/jwks.json'
                && $body['allowed_audiences'] === [self::AUDIENCE]
                && $body['allowed_algorithms'] === ['RS256']
                && $body['user_claim'] === 'sub'
                && $body['tenant_claim'] === 'tenant_id'
                && $body['require_email_verified'] === true
                && $body['allow_jit_users'] === true;
        });
    }

    public function test_the_application_option_overrides_the_configured_one(): void
    {
        $this->fakeIOCloud(existingProviders: []);
        $otherApplication = '0e1f2a3b-4c5d-4e6f-9a0b-1c2d3e4f5a6b';

        $this->artisan('demo:federation:register', [
            '--application' => $otherApplication,
            '--tenant' => self::TENANT_UUID,
        ])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/v1/partner/federation/providers')
            && $request->data()['application_uuid'] === $otherApplication);
    }

    public function test_it_refuses_to_register_without_an_application(): void
    {
        $this->fakeIOCloud(existingProviders: []);
        config(['demo.iocloud_application_uuid' => null]);

        $this->artisan('demo:federation:register', ['--tenant' => self::TENANT_UUID])
            ->expectsOutputToContain('DEMO_IOCLOUD_APPLICATION_UUID')
            ->assertFailed();

        Http::assertNotSent(fn (Request $request): bool =>
            $request->method() === 'POST'
            && str_ends_with($request->url(), '/v1/partner/federation/providers'));
        Http::assertNotSent(fn (Request $request): bool =>
            str_contains($request->url(), '/v1/partner/tenants/'));
    }

    public function test_it_sets_the_portal_organisation_as_the_tenants_external_id(): void
    {
        $this->fakeIOCloud(existingProviders: []);

        $this->artisan('demo:federation:register', ['--tenant' => self::TENANT_UUID])
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/v1/partner/tenants/'.self::TENANT_UUID.'/external-id')
            && $request->data() === ['external_id' => 'acme-tenant-1']);
    }

    public function test_it_reuses_an_already_registered_issuer(): void
    {
        $this->fakeIOCloud(existingProviders: [$this->providerBody()]);

        $this->artisan('demo:federation:register', ['--tenant' => self::TENANT_UUID])
            ->expectsOutputToContain('already registered')
            ->assertSuccessful();

        // Registering twice would be rejected with ISSUER_ALREADY_TRUSTED.
        Http::assertNotSent(fn (Request $request): bool =>
            $request->method() === 'POST'
            && str_ends_with($request->url(), '/v1/partner/federation/providers'));
    }

    public function test_a_registered_issuer_needs_no_application_to_set_the_tenant(): void
    {
        // The provider already belongs to its application; only registering
        // one needs it.
        $this->fakeIOCloud(existingProviders: [$this->providerBody()]);
        config(['demo.iocloud_application_uuid' => null]);

        $this->artisan('demo:federation:register', ['--tenant' => self::TENANT_UUID])
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/tenants/'.self::TENANT_UUID.'/external-id'));
    }

    public function test_it_warns_instead_of_setting_an_external_id_when_no_tenant_is_given(): void
    {
        $this->fakeIOCloud(existingProviders: []);
        config(['demo.iocloud_tenant_uuid' => null]);

        $this->artisan('demo:federation:register')
            ->expectsOutputToContain('invalid_target')
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request): bool =>
            str_contains($request->url(), '/tenants'));
    }

    public function test_it_reports_an_api_rejection_without_a_stack_trace(): void
    {
        Http::fake([
            self::PARTNER_TOKEN_ENDPOINT => Http::response([
                'data' => [
                    'token' => [
                        'access_token' => 'partner-token',
                        'token_type' => 'Bearer',
                        'expires_at' => '2099-01-01T00:00:00Z',
                    ],
                ],
            ]),
            self::PROVIDERS_ENDPOINT => Http::response(
                ['code' => 'ISSUER_ALREADY_TRUSTED', 'message' => 'Already registered.'],
                409,
            ),
        ]);

        $this->artisan('demo:federation:register', ['--tenant' => self::TENANT_UUID])
            ->expectsOutputToContain('IOCloud rejected the request')
            ->assertFailed();
    }

    /** @param list<array<string, mixed>> $existingProviders */
    private function fakeIOCloud(array $existingProviders): void
    {
        Http::fake([
            self::PARTNER_TOKEN_ENDPOINT => Http::response([
                'data' => [
                    'token' => [
                        'access_token' => 'partner-token',
                        'token_type' => 'Bearer',
                        'expires_at' => '2099-01-01T00:00:00Z',
                    ],
                ],
            ]),
            self::TENANTS_ENDPOINT.'/*' => Http::response(
                ['data' => ['tenant' => [
                    'uuid' => self::TENANT_UUID,
                    'application_uuid' => self::APPLICATION_UUID,
                    'name' => 'Acme',
                    'slug' => 'acme',
                    'contact_email' => 'ops@acme.example',
                    'external_id' => 'acme-tenant-1',
                    'status' => 'active',
                    'created_at' => '2026-07-09T10:15:00Z',
                ]]],
            ),
            self::PROVIDERS_ENDPOINT => Http::sequence()
                ->push(['data' => ['providers' => $existingProviders]])
                ->push(['data' => ['provider' => $this->providerBody()]], 201),
        ]);
    }

    /** @return array<string, mixed> */
    private function providerBody(): array
    {
        return [
            'uuid' => self::PROVIDER_UUID,
            'application_uuid' => self::APPLICATION_UUID,
            'name' => 'Acme Portal (demo)',
            'issuer' => self::ISSUER,
            'jwks_url' => self::ISSUER.'/.well-known/jwks.json',
            'allowed_audiences' => [self::AUDIENCE],
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
    }
}
