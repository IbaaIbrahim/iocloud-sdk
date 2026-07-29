<?php

namespace IOCloud\Laravel\Tests;

use IOCloud\Laravel\Exceptions\IOCloudFederationException;
use IOCloud\Laravel\Federation\FederationConfig;
use IOCloud\Laravel\Federation\FederationSigningKey;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

final class FederationConfigTest extends PHPUnitTestCase
{
    public function test_it_reports_unconfigured_when_nothing_is_set(): void
    {
        $config = FederationConfig::fromArray([]);

        $this->assertFalse($config->isConfigured());
        $this->assertNull($config->issuer);
        $this->assertSame('ai-ecosystem', $config->audience);
        $this->assertSame('sub', $config->claimNames->user);
        $this->assertSame('tenant_id', $config->claimNames->tenant);
    }

    public function test_blank_strings_are_treated_as_absent(): void
    {
        $config = FederationConfig::fromArray([
            'issuer' => '   ',
            'private_key' => '',
        ]);

        $this->assertNull($config->issuer);
        $this->assertNull($config->privateKey);
        $this->assertFalse($config->isConfigured());
    }

    public function test_an_inline_key_and_an_issuer_are_enough(): void
    {
        $config = FederationConfig::fromArray([
            'issuer' => ' https://portal.acme.example ',
            'private_key' => FederationSigningKey::generate()->privateKeyPem(),
        ]);

        $this->assertTrue($config->isConfigured());
        $this->assertSame('https://portal.acme.example', $config->requireIssuer());
        $this->assertSame(
            'https://portal.acme.example/.well-known/jwks.json',
            $config->jwksUrl(),
        );
    }

    public function test_an_inline_key_wins_over_a_path(): void
    {
        $inline = FederationSigningKey::generate();
        $path = tempnam(sys_get_temp_dir(), 'iocloud-key-');
        file_put_contents($path, FederationSigningKey::generate()->privateKeyPem());

        try {
            $config = FederationConfig::fromArray([
                'issuer' => 'https://portal.acme.example',
                'private_key' => $inline->privateKeyPem(),
                'private_key_path' => $path,
            ]);

            $this->assertSame($inline->privateKeyPem(), $config->requirePrivateKeyPem());
        } finally {
            unlink($path);
        }
    }

    public function test_a_key_is_read_from_the_configured_path(): void
    {
        $key = FederationSigningKey::generate();
        $path = tempnam(sys_get_temp_dir(), 'iocloud-key-');
        file_put_contents($path, $key->privateKeyPem());

        try {
            $config = FederationConfig::fromArray([
                'issuer' => 'https://portal.acme.example',
                'private_key_path' => $path,
            ]);

            $this->assertTrue($config->isConfigured());
            $this->assertSame($key->privateKeyPem(), $config->requirePrivateKeyPem());
        } finally {
            unlink($path);
        }
    }

    public function test_a_missing_key_file_names_the_generate_command(): void
    {
        $config = FederationConfig::fromArray([
            'issuer' => 'https://portal.acme.example',
            'private_key_path' => sys_get_temp_dir().'/iocloud-absent-key.pem',
        ]);

        $this->expectException(IOCloudFederationException::class);
        $this->expectExceptionMessageMatches('/iocloud:keys/');

        $config->requirePrivateKeyPem();
    }

    public function test_a_missing_issuer_names_the_environment_variable(): void
    {
        $config = FederationConfig::fromArray([]);

        $this->expectException(IOCloudFederationException::class);
        $this->expectExceptionMessageMatches('/IOCLOUD_FEDERATION_ISSUER/');

        $config->requireIssuer();
    }

    public function test_claim_names_come_from_configuration(): void
    {
        $config = FederationConfig::fromArray([
            'claims' => ['user' => 'user_id', 'tenant' => 'org_id'],
        ]);

        $this->assertSame('user_id', $config->claimNames->user);
        $this->assertSame('org_id', $config->claimNames->tenant);
        $this->assertSame('email', $config->claimNames->email);
        $this->assertSame('name', $config->claimNames->name);
    }
}
