<?php

namespace IOCloud\Laravel\Federation;

use IOCloud\Laravel\Exceptions\IOCloudFederationException;
use OpenSSLAsymmetricKey;
use SensitiveParameter;

/**
 * An RSA keypair with the JWK views the partner's OIDC endpoints serve.
 *
 * Built on ext-openssl only, so federating users adds no JWT dependency to the
 * host application.
 */
final class FederationSigningKey
{
    /**
     * RFC 7518 §3.1 recommends RSASSA-PKCS1-v1_5 with SHA-256 as the baseline;
     * the platform accepts it for every provider without extra configuration.
     */
    public const SIGNING_ALGORITHM = 'RS256';

    public const KEY_TYPE = 'RSA';

    public const KEY_USE = 'sig';

    private const RSA_KEY_SIZE_BITS = 2048;

    private readonly string $modulus;

    private readonly string $exponent;

    private readonly string $kid;

    private function __construct(private readonly OpenSSLAsymmetricKey $privateKey)
    {
        $details = openssl_pkey_get_details($privateKey);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new IOCloudFederationException(
                'Federation signing keys must be RSA keys.'
            );
        }
        if (($details['bits'] ?? 0) < self::RSA_KEY_SIZE_BITS) {
            throw new IOCloudFederationException(sprintf(
                'Federation signing keys must be at least %d bits; this key is %d bits.',
                self::RSA_KEY_SIZE_BITS,
                (int) ($details['bits'] ?? 0),
            ));
        }

        $this->modulus = Base64Url::encodeInteger((string) $details['rsa']['n']);
        $this->exponent = Base64Url::encodeInteger((string) $details['rsa']['e']);
        $this->kid = self::thumbprint($this->modulus, $this->exponent);
    }

    /** Generate a fresh 2048-bit RSA keypair. */
    public static function generate(): self
    {
        $arguments = [
            'private_key_bits' => self::RSA_KEY_SIZE_BITS,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $privateKey = openssl_pkey_new($arguments);
        if ($privateKey === false) {
            self::discardOpensslErrors();
            $privateKey = openssl_pkey_new(
                $arguments + ['config' => self::fallbackOpensslConfigPath()]
            );
        }
        if ($privateKey === false) {
            throw new IOCloudFederationException(
                'Could not generate an RSA keypair: '.self::opensslErrors()
            );
        }

        return new self($privateKey);
    }

    /** Load a persisted key so the published `kid` survives a restart. */
    public static function fromPrivateKeyPem(
        #[SensitiveParameter] string $privateKeyPem,
        #[SensitiveParameter] ?string $passphrase = null,
    ): self {
        $privateKey = $passphrase === null
            ? openssl_pkey_get_private($privateKeyPem)
            : openssl_pkey_get_private($privateKeyPem, $passphrase);
        if ($privateKey === false) {
            throw new IOCloudFederationException(
                'The federation private key is not a readable PEM private key.'
            );
        }

        return new self($privateKey);
    }

    /** The key id published in the JWKS and set in every token header. */
    public function kid(): string
    {
        return $this->kid;
    }

    /** PKCS#8 PEM of the private half. Store it as a secret. */
    public function privateKeyPem(): string
    {
        if (openssl_pkey_export($this->privateKey, $privateKeyPem)) {
            return (string) $privateKeyPem;
        }

        self::discardOpensslErrors();
        $exported = openssl_pkey_export(
            $this->privateKey,
            $privateKeyPem,
            null,
            ['config' => self::fallbackOpensslConfigPath()],
        );
        if (! $exported) {
            throw new IOCloudFederationException(
                'Could not export the federation private key: '.self::opensslErrors()
            );
        }

        return (string) $privateKeyPem;
    }

    /**
     * PEM of the public half.
     *
     * Not needed to publish a JWKS — that is derived from the private key — but
     * written alongside it so the pair can be inspected or handed to another tool.
     */
    public function publicKeyPem(): string
    {
        $details = openssl_pkey_get_details($this->privateKey);
        if ($details === false || ! isset($details['key'])) {
            throw new IOCloudFederationException(
                'Could not export the federation public key: '.self::opensslErrors()
            );
        }

        return (string) $details['key'];
    }

    /**
     * The public key as a JWK (RFC 7517).
     *
     * @return array<string, string>
     */
    public function publicJwk(): array
    {
        return [
            'kty' => self::KEY_TYPE,
            'use' => self::KEY_USE,
            'alg' => self::SIGNING_ALGORITHM,
            'kid' => $this->kid,
            'n' => $this->modulus,
            'e' => $this->exponent,
        ];
    }

    /**
     * The document to serve at `<issuer>/.well-known/jwks.json`.
     *
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array
    {
        return ['keys' => [$this->publicJwk()]];
    }

    /**
     * Sign `$claims` into a compact JWS naming this key's `kid`.
     *
     * @param array<string, mixed> $claims
     */
    public function sign(array $claims): string
    {
        $header = [
            'alg' => self::SIGNING_ALGORITHM,
            'kid' => $this->kid,
            'typ' => 'JWT',
        ];
        $signingInput = Base64Url::encodeJson($header).'.'.Base64Url::encodeJson($claims);

        if (! openssl_sign($signingInput, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new IOCloudFederationException(
                'Could not sign the subject token: '.self::opensslErrors()
            );
        }

        return $signingInput.'.'.Base64Url::encode((string) $signature);
    }

    /**
     * Merge several keys into one JWKS, which is how a rotation is published.
     *
     * During a rotation the retiring key stays in the document until every
     * token it signed has expired; verifiers select by `kid`.
     *
     * @return array{keys: list<array<string, string>>}
     */
    public static function buildJwks(self ...$signingKeys): array
    {
        if ($signingKeys === []) {
            throw new IOCloudFederationException('A JWKS must publish at least one key.');
        }

        return [
            'keys' => array_map(
                static fn (self $signingKey): array => $signingKey->publicJwk(),
                $signingKeys,
            ),
        ];
    }

    /**
     * Compute the RFC 7638 thumbprint used as the key id.
     *
     * Deriving the `kid` from the key itself — rather than a random value —
     * keeps it stable across process restarts and across the Python, Node, and
     * Laravel SDKs, so a published JWKS and a signed token always agree on it.
     */
    private static function thumbprint(string $modulus, string $exponent): string
    {
        $canonicalMembers = ['e' => $exponent, 'kty' => self::KEY_TYPE, 'n' => $modulus];

        return Base64Url::encode(
            hash('sha256', Base64Url::json($canonicalMembers), binary: true)
        );
    }

    /**
     * The minimal `openssl.cnf` bundled with this package.
     *
     * Windows PHP builds frequently ship without a resolvable `openssl.cnf` and
     * without `OPENSSL_CONF` set, which fails key generation and export with
     * "configuration file routines::no such file". Nothing in that file affects an
     * RSA keypair — the algorithm and modulus size are stated explicitly — so
     * retrying against a bundled copy keeps `iocloud:keys` portable. The platform's
     * own configuration is always tried first and never overridden.
     */
    private static function fallbackOpensslConfigPath(): string
    {
        return __DIR__.'/../../resources/openssl.cnf';
    }

    private static function opensslErrors(): string
    {
        $messages = [];
        while (($message = openssl_error_string()) !== false) {
            $messages[] = $message;
        }

        return $messages === [] ? 'no OpenSSL error reported' : implode('; ', $messages);
    }

    /**
     * Empty the OpenSSL error queue.
     *
     * Called between a failed attempt and its retry so a later failure reports
     * only its own cause, not the first attempt's noise.
     */
    private static function discardOpensslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Discarded on purpose.
        }
    }
}
