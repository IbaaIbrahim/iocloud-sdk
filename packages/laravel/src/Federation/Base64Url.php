<?php

namespace IOCloud\Laravel\Federation;

use IOCloud\Laravel\Exceptions\IOCloudFederationException;

/** Unpadded base64url encoding, the wire format of JWS and JWK (RFC 7515 §2). */
final class Base64Url
{
    /** Encode raw bytes as unpadded base64url, byte for byte. */
    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Encode a big-endian integer as unpadded base64url.
     *
     * Leading NUL bytes are dropped: JWK integer members must use the minimum
     * number of octets needed to represent the value (RFC 7518 §2), and OpenSSL
     * may hand back a zero-padded big-endian integer. Only integer members get
     * this treatment — trimming a signature, whose length is fixed by the key
     * size, would corrupt it.
     */
    public static function encodeInteger(string $bigEndianInteger): string
    {
        return self::encode(ltrim($bigEndianInteger, "\0"));
    }

    /**
     * Serialize a value to the compact JSON that JWS headers and claim sets use.
     *
     * @param array<string, mixed> $value
     */
    public static function json(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new IOCloudFederationException(
                'Could not encode the token payload as JSON: '.json_last_error_msg()
            );
        }

        return $encoded;
    }

    /**
     * Encode a value as a base64url JSON segment.
     *
     * @param array<string, mixed> $value
     */
    public static function encodeJson(array $value): string
    {
        return self::encode(self::json($value));
    }
}
