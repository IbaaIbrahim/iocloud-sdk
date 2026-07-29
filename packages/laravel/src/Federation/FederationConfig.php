<?php

namespace IOCloud\Laravel\Federation;

use IOCloud\Laravel\Data\SubjectTokenClaimNames;
use IOCloud\Laravel\Exceptions\IOCloudFederationException;

/**
 * The validated `iocloud.federation` configuration.
 *
 * Owns exactly one concern: turning raw configuration into values the rest of
 * the federation code can trust, and saying whether federation is configured at
 * all so the service provider can skip wiring it when it is not.
 */
final readonly class FederationConfig
{
    private const MISSING_KEY_HINT =
        'No federation signing key is configured. Generate one with'
        .' `php artisan iocloud:keys`, then set'
        .' IOCLOUD_FEDERATION_PRIVATE_KEY_PATH (or IOCLOUD_FEDERATION_PRIVATE_KEY).';

    public function __construct(
        public ?string $issuer,
        public string $audience,
        public ?string $privateKey,
        public ?string $privateKeyPath,
        public ?string $privateKeyPassphrase,
        public int $tokenTtlSeconds,
        public ?string $jwksRoute,
        public SubjectTokenClaimNames $claimNames,
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $claims = $config['claims'] ?? [];

        return new self(
            issuer: self::optionalString($config['issuer'] ?? null),
            audience: self::optionalString($config['audience'] ?? null) ?? 'ai-ecosystem',
            privateKey: self::optionalSecret($config['private_key'] ?? null),
            privateKeyPath: self::optionalString($config['private_key_path'] ?? null),
            privateKeyPassphrase: self::optionalSecret(
                $config['private_key_passphrase'] ?? null
            ),
            tokenTtlSeconds: (int) ($config['token_ttl'] ?? SubjectTokenIssuer::DEFAULT_TOKEN_TTL_SECONDS),
            jwksRoute: self::optionalString($config['jwks_route'] ?? null),
            claimNames: SubjectTokenClaimNames::fromArray(is_array($claims) ? $claims : []),
        );
    }

    /**
     * Whether an issuer and a signing key are both available.
     *
     * The service provider consults this before wiring the token issuer, so a
     * host application that only provisions tenants never has to configure
     * federation.
     */
    public function isConfigured(): bool
    {
        return $this->issuer !== null && $this->signingKeySource() !== null;
    }

    /** The issuer URL, or a clear failure when federation is not configured. */
    public function requireIssuer(): string
    {
        if ($this->issuer === null) {
            throw new IOCloudFederationException(
                'No federation issuer is configured. Set IOCLOUD_FEDERATION_ISSUER to'
                .' the public base URL this application serves its JWKS from.'
            );
        }

        return $this->issuer;
    }

    /**
     * The PEM of the signing key, read from the configured value or file.
     *
     * An inline value wins over a path, so a deployment can inject the key as a
     * secret without provisioning a file.
     */
    public function requirePrivateKeyPem(): string
    {
        if ($this->privateKey !== null) {
            return $this->privateKey;
        }
        if ($this->privateKeyPath === null) {
            throw new IOCloudFederationException(self::MISSING_KEY_HINT);
        }
        if (! is_file($this->privateKeyPath) || ! is_readable($this->privateKeyPath)) {
            throw new IOCloudFederationException(sprintf(
                'The federation signing key file "%s" does not exist or is not readable. %s',
                $this->privateKeyPath,
                self::MISSING_KEY_HINT,
            ));
        }

        $contents = file_get_contents($this->privateKeyPath);
        if ($contents === false || trim($contents) === '') {
            throw new IOCloudFederationException(sprintf(
                'The federation signing key file "%s" is empty.',
                $this->privateKeyPath,
            ));
        }

        return $contents;
    }

    /** Where the platform must be told to fetch this issuer's keys. */
    public function jwksUrl(): string
    {
        return $this->requireIssuer().'/.well-known/jwks.json';
    }

    /** 'inline', 'file', or null when no key is configured. */
    private function signingKeySource(): ?string
    {
        if ($this->privateKey !== null) {
            return 'inline';
        }
        if ($this->privateKeyPath !== null && is_file($this->privateKeyPath)) {
            return 'file';
        }

        return null;
    }

    /** Trimmed, for values where surrounding whitespace is never meaningful. */
    private static function optionalString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * Verbatim, for key material and passphrases.
     *
     * A blank value still counts as absent, but anything else is passed through
     * untouched: trimming would drop a PEM's trailing newline, and would silently
     * corrupt a passphrase that legitimately begins or ends with whitespace.
     */
    private static function optionalSecret(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
