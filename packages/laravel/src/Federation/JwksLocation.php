<?php

namespace IOCloud\Laravel\Federation;

use InvalidArgumentException;

/**
 * Where the platform fetches an identity provider's keys.
 *
 * The platform stores an issuer's key-set location as `jwks_path`, a path it
 * appends to the issuer's own origin: it fetches `issuer_origin + jwks_path`
 * and nothing else. A JWKS URL a partner already has is reduced to that path,
 * and only when it is on the issuer's origin; anywhere else, the URL names keys
 * the platform would never fetch.
 *
 * @internal
 */
final class JwksLocation
{
    /** The platform's own default, and where this package's key set is served. */
    public const DEFAULT_PATH = '/.well-known/jwks.json';

    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * `scheme://host[:port]` of `$url`, canonical as the platform compares it,
     * or null when it has no http or https origin. Scheme and host lowercased,
     * the host without a trailing dot, the scheme's default port dropped.
     */
    public static function originOf(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if ($parts === false) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if (! isset(self::DEFAULT_PORTS[$scheme])) {
            return null;
        }
        $host = rtrim(strtolower($parts['host'] ?? ''), '.');
        if ($host === '') {
            return null;
        }
        $port = $parts['port'] ?? null;

        return $port === null || $port === self::DEFAULT_PORTS[$scheme]
            ? "{$scheme}://{$host}"
            : "{$scheme}://{$host}:{$port}";
    }

    /** The path of `$url` as written: empty when it has none. */
    public static function pathOf(string $url): string
    {
        $path = parse_url(trim($url), PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    /**
     * The `jwks_path` to register: the one given, the default, or `$jwksUrl`'s path.
     *
     * @throws InvalidArgumentException for both at once, or for a `$jwksUrl`
     *                                  off the issuer's origin or carrying a
     *                                  query or a fragment
     */
    public static function resolvePath(string $issuer, ?string $jwksPath, ?string $jwksUrl): string
    {
        if ($jwksUrl === null) {
            return $jwksPath ?? self::DEFAULT_PATH;
        }
        if ($jwksPath !== null) {
            throw new InvalidArgumentException('Pass $jwksPath or $jwksUrl, not both.');
        }

        return self::pathOnOrigin($jwksUrl, $issuer);
    }

    /**
     * The path of `$jwksUrl`, which must be on `$issuer`'s origin. A query or a
     * fragment is refused too: the platform keeps the path alone, so either
     * would be silently dropped.
     */
    private static function pathOnOrigin(string $jwksUrl, string $issuer): string
    {
        $issuerOrigin = self::originOf($issuer);
        if ($issuerOrigin === null) {
            throw new InvalidArgumentException(
                "The issuer '{$issuer}' has no http or https origin to put a JWKS path on."
            );
        }
        if (self::originOf($jwksUrl) !== $issuerOrigin) {
            throw new InvalidArgumentException(
                "\$jwksUrl '{$jwksUrl}' is not on the issuer's origin, {$issuerOrigin}."
                ." The platform fetches an issuer's keys from its own origin only: serve"
                ." them on {$issuerOrigin} and pass that path as \$jwksPath."
            );
        }
        if (str_contains($jwksUrl, '?') || str_contains($jwksUrl, '#')) {
            throw new InvalidArgumentException(
                "\$jwksUrl '{$jwksUrl}' carries a query or a fragment; the platform"
                .' stores a path only. Pass $jwksPath instead.'
            );
        }
        $path = self::pathOf($jwksUrl);
        if ($path === '') {
            throw new InvalidArgumentException(
                "\$jwksUrl '{$jwksUrl}' names no path. Pass \$jwksPath instead."
            );
        }

        return $path;
    }
}
