<?php

namespace IOCloud\Laravel\Tests;

use IOCloud\Laravel\Console\KeysCommand;
use IOCloud\Laravel\Federation\FederationSigningKey;
use PHPUnit\Framework\Attributes\DataProvider;

final class KeysCommandTest extends TestCase
{
    private string $directory;

    private string $privateKeyPath;

    private string $publicKeyPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/iocloud-keys-'.bin2hex(random_bytes(8));
        $this->privateKeyPath = $this->directory.'/iocloud-federation-private.key';
        $this->publicKeyPath = $this->directory.'/iocloud-federation-public.key';
    }

    protected function tearDown(): void
    {
        foreach ([$this->privateKeyPath, $this->publicKeyPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_it_writes_a_usable_keypair(): void
    {
        $this->artisan('iocloud:keys', ['--path' => $this->privateKeyPath])
            ->assertSuccessful();

        $this->assertFileExists($this->privateKeyPath);
        $this->assertFileExists($this->publicKeyPath);
        $key = FederationSigningKey::fromPrivateKeyPem(
            (string) file_get_contents($this->privateKeyPath)
        );
        $this->assertNotEmpty($key->kid());
        $this->assertSame(
            $key->publicKeyPem(),
            (string) file_get_contents($this->publicKeyPath),
        );
    }

    public function test_the_written_key_is_the_one_the_sdk_then_publishes(): void
    {
        $this->artisan('iocloud:keys', ['--path' => $this->privateKeyPath])
            ->assertSuccessful();
        config([
            'iocloud.federation.issuer' => 'https://portal.acme.example',
            'iocloud.federation.private_key' => null,
            'iocloud.federation.private_key_path' => $this->privateKeyPath,
        ]);

        $jwks = $this->app->make(\IOCloud\Laravel\IOCloudClient::class)->jwks();

        $expected = FederationSigningKey::fromPrivateKeyPem(
            (string) file_get_contents($this->privateKeyPath)
        );
        $this->assertSame($expected->jwks(), $jwks);
    }

    public function test_it_reports_the_kid_and_the_jwks_to_publish(): void
    {
        $this->artisan('iocloud:keys', ['--path' => $this->privateKeyPath])
            ->expectsOutputToContain('Key id (kid)')
            ->expectsOutputToContain('IOCloud::jwks()')
            ->expectsOutputToContain('"kty": "RSA"')
            ->assertSuccessful();
    }

    public function test_it_refuses_to_replace_existing_keys_without_force(): void
    {
        $this->artisan('iocloud:keys', ['--path' => $this->privateKeyPath])
            ->assertSuccessful();
        $original = (string) file_get_contents($this->privateKeyPath);

        $this->artisan('iocloud:keys', ['--path' => $this->privateKeyPath])
            ->assertFailed();

        $this->assertSame($original, (string) file_get_contents($this->privateKeyPath));
    }

    public function test_force_replaces_the_keypair(): void
    {
        $this->artisan('iocloud:keys', ['--path' => $this->privateKeyPath])
            ->assertSuccessful();
        $original = (string) file_get_contents($this->privateKeyPath);

        $this->artisan('iocloud:keys', [
            '--path' => $this->privateKeyPath,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertNotSame($original, (string) file_get_contents($this->privateKeyPath));
    }

    public function test_show_prints_the_key_instead_of_writing_files(): void
    {
        $this->artisan('iocloud:keys', ['--show' => true])
            ->expectsOutputToContain('PRIVATE KEY')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->privateKeyPath);
    }

    public function test_it_fails_clearly_when_no_path_is_configured(): void
    {
        $this->artisan('iocloud:keys')->assertFailed();
    }

    #[DataProvider('keyPaths')]
    public function test_the_public_key_path_sits_beside_the_private_one(
        string $privateKeyPath,
        string $expectedPublicKeyPath,
    ): void {
        $this->assertSame(
            $expectedPublicKeyPath,
            KeysCommand::publicKeyPathFor($privateKeyPath),
        );
    }

    /** @return array<string, array{string, string}> */
    public static function keyPaths(): array
    {
        return [
            'passport-style name' => [
                '/keys/iocloud-federation-private.key',
                '/keys/iocloud-federation-public.key',
            ],
            'pem extension' => ['/keys/federation-private.pem', '/keys/federation-public.pem'],
            'no -private suffix' => ['/keys/federation.key', '/keys/federation-public.key'],
            'no extension' => ['/keys/federation-private', '/keys/federation-public'],
        ];
    }
}
