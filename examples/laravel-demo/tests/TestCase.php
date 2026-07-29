<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use IOCloud\Laravel\Federation\FederationSigningKey;

abstract class TestCase extends BaseTestCase
{
    protected const ISSUER = 'https://portal.acme.test';

    protected const AUDIENCE = 'ai-ecosystem';

    protected const IOCLOUD_BASE_URL = 'https://api.iocloud.test';

    protected FederationSigningKey $signingKey;

    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        // One key per test, generated before the app boots, so the same key backs
        // both the JWKS route and the issuer the container hands to the SDK.
        $this->signingKey = FederationSigningKey::generate();

        parent::setUp();

        config([
            'iocloud.base_url' => self::IOCLOUD_BASE_URL,
            'iocloud.client_id' => 'partner-client-id',
            'iocloud.client_secret' => 'partner-client-secret',
            'iocloud.federation.issuer' => self::ISSUER,
            'iocloud.federation.audience' => self::AUDIENCE,
            'iocloud.federation.private_key' => $this->signingKey->privateKeyPem(),
            'iocloud.federation.private_key_path' => null,
            'demo.iocloud_tenant_uuid' => 'ab8c1f2e-3d45-4a67-8b90-1c2d3e4f5a6b',
        ]);
    }
}
