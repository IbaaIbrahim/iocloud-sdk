<?php

namespace IOCloud\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use IOCloud\Laravel\Exceptions\IOCloudConfigurationException;
use IOCloud\Laravel\Facades\IOCloud;
use IOCloud\Laravel\Federation\FederationSigningKey;
use IOCloud\Laravel\Federation\SubjectTokenIssuer;
use IOCloud\Laravel\IOCloudClient;

/**
 * `IOCloud::jwks()` is the JWKS endpoint: a host application returns it from a
 * route of its own choosing, at whatever path it registered with IOCloud.
 */
final class JwksRouteTest extends TestCase
{
    private FederationSigningKey $signingKey;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->signingKey = FederationSigningKey::generate();
        $app['config']->set('iocloud.federation.issuer', 'https://portal.acme.example');
        $app['config']->set('iocloud.federation.audience', 'ai-ecosystem');
        $app['config']->set('iocloud.federation.private_key', $this->signingKey->privateKeyPem());
        $app['config']->set('iocloud.federation.private_key_path', null);
    }

    public function test_the_facade_returns_the_document_a_route_can_publish(): void
    {
        Route::get('/.well-known/jwks.json', fn (): array => IOCloud::jwks());

        $response = $this->getJson('/.well-known/jwks.json');

        $response->assertOk();
        $response->assertExactJson($this->signingKey->jwks());
    }

    public function test_publishing_the_key_set_needs_no_partner_credentials(): void
    {
        // A partner generates keys and publishes them while setting federation up,
        // long before it has API credentials. That must not 500.
        config(['iocloud.client_id' => null, 'iocloud.client_secret' => null]);
        Route::get('/.well-known/jwks.json', fn (): array => IOCloud::jwks());

        $this->getJson('/.well-known/jwks.json')
            ->assertOk()
            ->assertExactJson($this->signingKey->jwks());
    }

    public function test_a_partner_call_without_credentials_says_which_ones(): void
    {
        config(['iocloud.client_id' => null, 'iocloud.client_secret' => null]);

        $this->expectException(IOCloudConfigurationException::class);
        $this->expectExceptionMessageMatches('/IOCLOUD_CLIENT_ID/');

        $this->app->make(IOCloudClient::class)->issuePartnerToken();
    }

    public function test_the_path_is_the_host_application_choice(): void
    {
        Route::get('/oidc/keys', fn (): array => IOCloud::jwks());

        $this->getJson('/oidc/keys')
            ->assertOk()
            ->assertExactJson($this->signingKey->jwks());
    }

    public function test_no_route_is_registered_unless_the_application_asks(): void
    {
        // Default config leaves the path unset, so the package claims no URL and
        // cannot collide with a route the application defines itself.
        $this->getJson('/.well-known/jwks.json')->assertNotFound();
    }

    public function test_the_published_document_contains_no_private_key_material(): void
    {
        Route::get('/.well-known/jwks.json', fn (): array => IOCloud::jwks());

        $body = (string) $this->get('/.well-known/jwks.json')->getContent();

        $this->assertStringNotContainsString('PRIVATE', $body);
        foreach (['"d"', '"p"', '"q"', '"dp"', '"dq"', '"qi"'] as $privateMember) {
            $this->assertStringNotContainsString($privateMember, $body);
        }
    }

    public function test_federation_details_report_what_to_register_with_iocloud(): void
    {
        $details = IOCloud::federationDetails();

        $this->assertSame('https://portal.acme.example', $details['issuer']);
        $this->assertSame('ai-ecosystem', $details['audience']);
        $this->assertSame(
            'https://portal.acme.example/.well-known/jwks.json',
            $details['jwks_url'],
        );
        $this->assertSame($this->signingKey->kid(), $details['kid']);
    }

    public function test_the_configured_key_backs_the_container_token_issuer(): void
    {
        $issuer = $this->app->make(SubjectTokenIssuer::class);

        $this->assertSame($this->signingKey->kid(), $issuer->signingKey()->kid());
        $this->assertSame('https://portal.acme.example', $issuer->issuer());
    }

    public function test_the_resolved_client_can_federate_when_federation_is_configured(): void
    {
        // Reaching the HTTP layer proves an issuer was wired in; without one the
        // client refuses before sending anything.
        Http::fake([
            'api.example.com/v1/federation/token' => Http::response([
                'access_token' => 'platform-session-token',
                'issued_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                'token_type' => 'Bearer',
                'expires_in' => 60,
                'user_uuid' => '992d64fc-8f2a-4c31-b7e5-1d0a6c9f3b48',
                'name' => 'Test User',
                'email' => 'user@customer.example',
            ]),
        ]);

        $session = $this->app->make(IOCloudClient::class)->federatedLogin(
            subject: 'acme-user-1',
            externalTenantId: 'acme-tenant-1',
        );

        $this->assertSame('platform-session-token', $session->accessToken);
    }
}
