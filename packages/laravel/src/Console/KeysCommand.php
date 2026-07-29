<?php

namespace IOCloud\Laravel\Console;

use Illuminate\Console\Command;
use IOCloud\Laravel\Federation\FederationConfig;
use IOCloud\Laravel\Federation\FederationSigningKey;

/**
 * Generates the RSA keypair used to sign IOCloud federation tokens.
 *
 * Mirrors `passport:keys`: writes the pair into `storage/`, refuses to clobber an
 * existing key without `--force`, and is the one setup step before federation
 * works. The private key is the source of truth — the JWKS this application
 * publishes is derived from it, so the public file is only for inspection.
 */
final class KeysCommand extends Command
{
    protected $signature = 'iocloud:keys
        {--force : Overwrite existing keys}
        {--show : Print the private key instead of writing it}
        {--path= : Private key path (defaults to the configured path)}';

    protected $description = 'Create the encryption keys for IOCloud federation tokens';

    private const FILE_PERMISSIONS = 0600;

    private const PUBLIC_FILE_PERMISSIONS = 0644;

    private const DIRECTORY_PERMISSIONS = 0700;

    public function handle(FederationConfig $config): int
    {
        $signingKey = FederationSigningKey::generate();

        if ($this->option('show')) {
            $this->components->warn(
                'Store this private key as a secret — it is not written to disk.'
                .' Set it as IOCLOUD_FEDERATION_PRIVATE_KEY.'
            );
            $this->line($signingKey->privateKeyPem());
        } elseif (! $this->writeKeys($signingKey, $config)) {
            return self::FAILURE;
        }

        $this->summarize($signingKey, $config);

        return self::SUCCESS;
    }

    private function writeKeys(
        FederationSigningKey $signingKey,
        FederationConfig $config,
    ): bool {
        $privateKeyPath = $this->resolvePath($config);
        if ($privateKeyPath === null) {
            $this->components->error(
                'No key path is configured. Pass --path=/path/to/key, set'
                .' IOCLOUD_FEDERATION_PRIVATE_KEY_PATH, or use --show.'
            );

            return false;
        }

        $publicKeyPath = self::publicKeyPathFor($privateKeyPath);
        $existing = array_filter(
            [$privateKeyPath, $publicKeyPath],
            static fn (string $path): bool => is_file($path),
        );
        if ($existing !== [] && ! $this->option('force')) {
            $this->components->error(sprintf(
                'Encryption keys already exist (%s). Use --force to replace them —'
                .' every session signed by the current key stops verifying.',
                implode(', ', $existing),
            ));

            return false;
        }

        $directory = dirname($privateKeyPath);
        if (! is_dir($directory)
            && ! mkdir($directory, self::DIRECTORY_PERMISSIONS, recursive: true)) {
            $this->components->error("Could not create the directory {$directory}.");

            return false;
        }

        if (file_put_contents($privateKeyPath, $signingKey->privateKeyPem()) === false) {
            $this->components->error("Could not write {$privateKeyPath}.");

            return false;
        }
        // The private key is this application's whole identity to IOCloud.
        chmod($privateKeyPath, self::FILE_PERMISSIONS);

        if (file_put_contents($publicKeyPath, $signingKey->publicKeyPem()) === false) {
            $this->components->error("Could not write {$publicKeyPath}.");

            return false;
        }
        chmod($publicKeyPath, self::PUBLIC_FILE_PERMISSIONS);

        $this->components->info('Encryption keys generated successfully.');
        $this->components->twoColumnDetail('Private key', $privateKeyPath);
        $this->components->twoColumnDetail('Public key', $publicKeyPath);

        return true;
    }

    private function summarize(
        FederationSigningKey $signingKey,
        FederationConfig $config,
    ): void {
        $issuer = $config->issuer;

        $this->components->twoColumnDetail('Key id (kid)', $signingKey->kid());
        $this->components->twoColumnDetail(
            'Issuer',
            $issuer ?? '<not set: IOCLOUD_FEDERATION_ISSUER>',
        );
        $this->components->twoColumnDetail(
            'Register this jwks_url',
            $issuer === null
                ? '<needs IOCLOUD_FEDERATION_ISSUER>'
                : $issuer.'/.well-known/jwks.json',
        );

        $this->newLine();
        $this->components->info('Publish this document from a route of your choice:');
        $this->line("    Route::get('/.well-known/jwks.json', fn () => IOCloud::jwks());");
        $this->newLine();
        $this->line((string) json_encode(
            $signingKey->jwks(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));
    }

    /** The conventional sibling path, as `passport:keys` uses. */
    public static function publicKeyPathFor(string $privateKeyPath): string
    {
        $extension = pathinfo($privateKeyPath, PATHINFO_EXTENSION);
        $withoutExtension = $extension === ''
            ? $privateKeyPath
            : substr($privateKeyPath, 0, -(strlen($extension) + 1));

        $stem = (string) preg_replace('/-private$/', '', $withoutExtension);

        return $stem.'-public'.($extension === '' ? '' : '.'.$extension);
    }

    private function resolvePath(FederationConfig $config): ?string
    {
        $option = $this->option('path');
        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        return $config->privateKeyPath;
    }
}
