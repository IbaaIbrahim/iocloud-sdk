<?php

namespace IOCloud\Laravel\Tests;

use IOCloud\Laravel\Federation\FederationSigningKey;

/**
 * The opt-in shortcut: set `iocloud.federation.jwks_route` and the package
 * registers that route itself, for applications that would rather not write the
 * one-line route. Off unless configured — see {@see JwksRouteTest}.
 */
final class ConfiguredJwksRouteTest extends TestCase
{
    private FederationSigningKey $signingKey;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->signingKey = FederationSigningKey::generate();
        $app['config']->set('iocloud.federation.issuer', 'https://portal.acme.example');
        $app['config']->set('iocloud.federation.private_key', $this->signingKey->privateKeyPem());
        $app['config']->set('iocloud.federation.private_key_path', null);
        $app['config']->set('iocloud.federation.jwks_route', '/.well-known/jwks.json');
    }

    public function test_it_serves_the_key_set_at_the_configured_path(): void
    {
        $response = $this->getJson('/.well-known/jwks.json');

        $response->assertOk();
        $response->assertExactJson($this->signingKey->jwks());
    }

    public function test_the_configured_route_is_named_for_url_generation(): void
    {
        $this->assertSame(
            'http://localhost/.well-known/jwks.json',
            route('iocloud.federation.jwks'),
        );
    }

    public function test_it_is_cacheable_so_the_platform_does_not_refetch_per_login(): void
    {
        $response = $this->get('/.well-known/jwks.json');

        $this->assertStringContainsString(
            'max-age=300',
            (string) $response->headers->get('Cache-Control'),
        );
    }
}
