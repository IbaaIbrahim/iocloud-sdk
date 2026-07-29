<?php

namespace IOCloud\Laravel\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Verifies a compact JWS the way a JWKS consumer does: from the published JWK
 * alone, never from the private key. Test-only — the SDK signs but never
 * verifies.
 */
final class JwsVerifier
{
    /** DER AlgorithmIdentifier for rsaEncryption with absent parameters. */
    private const RSA_ENCRYPTION_ALGORITHM_ID = '300d06092a864886f70d0101010500';

    /**
     * @param array{keys: list<array<string, string>>} $jwks
     * @return array{header: array<string, mixed>, claims: array<string, mixed>}
     */
    public static function verify(string $token, array $jwks): array
    {
        $segments = explode('.', $token);
        Assert::assertCount(3, $segments, 'a compact JWS has three segments');
        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;

        $header = self::decodeJson($encodedHeader);
        $jwk = self::findKey($jwks, (string) ($header['kid'] ?? ''));

        $verified = openssl_verify(
            "{$encodedHeader}.{$encodedPayload}",
            self::base64UrlDecode($encodedSignature),
            self::jwkToPublicKeyPem($jwk),
            OPENSSL_ALGO_SHA256,
        );
        Assert::assertSame(1, $verified, 'the signature must verify against the JWKS');

        return ['header' => $header, 'claims' => self::decodeJson($encodedPayload)];
    }

    /** @param array{keys: list<array<string, string>>} $jwks */
    public static function hasKey(array $jwks, string $kid): bool
    {
        foreach ($jwks['keys'] as $jwk) {
            if (($jwk['kid'] ?? null) === $kid) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{keys: list<array<string, string>>} $jwks
     * @return array<string, string>
     */
    private static function findKey(array $jwks, string $kid): array
    {
        foreach ($jwks['keys'] as $jwk) {
            if (($jwk['kid'] ?? null) === $kid) {
                return $jwk;
            }
        }

        Assert::fail("the JWKS has no entry for kid {$kid}");
    }

    /**
     * Rebuild a SubjectPublicKeyInfo PEM from a JWK's modulus and exponent.
     *
     * @param array<string, string> $jwk
     */
    private static function jwkToPublicKeyPem(array $jwk): string
    {
        $rsaPublicKey = self::derSequence(
            self::derInteger(self::base64UrlDecode($jwk['n']))
            .self::derInteger(self::base64UrlDecode($jwk['e']))
        );
        $subjectPublicKeyInfo = self::derSequence(
            (string) hex2bin(self::RSA_ENCRYPTION_ALGORITHM_ID)
            .self::derBitString($rsaPublicKey)
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xFF).$encoded;
            $length >>= 8;
        }

        return chr(0x80 | strlen($encoded)).$encoded;
    }

    private static function derInteger(string $raw): string
    {
        $trimmed = ltrim($raw, "\0");
        if ($trimmed === '') {
            $trimmed = "\0";
        }
        // DER integers are signed: a leading high bit needs a zero byte so the
        // value is not read as negative.
        if ((ord($trimmed[0]) & 0x80) !== 0) {
            $trimmed = "\0".$trimmed;
        }

        return chr(0x02).self::derLength(strlen($trimmed)).$trimmed;
    }

    private static function derSequence(string $contents): string
    {
        return chr(0x30).self::derLength(strlen($contents)).$contents;
    }

    private static function derBitString(string $contents): string
    {
        $payload = "\0".$contents;

        return chr(0x03).self::derLength(strlen($payload)).$payload;
    }

    /** @return array<string, mixed> */
    private static function decodeJson(string $segment): array
    {
        $decoded = json_decode(self::base64UrlDecode($segment), associative: true);
        Assert::assertIsArray($decoded, 'a JWS segment must decode to a JSON object');

        return $decoded;
    }

    private static function base64UrlDecode(string $value): string
    {
        $padded = str_pad($value, (int) (ceil(strlen($value) / 4) * 4), '=');

        return (string) base64_decode(strtr($padded, '-_', '+/'), strict: true);
    }
}
