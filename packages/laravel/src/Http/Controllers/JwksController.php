<?php

namespace IOCloud\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use IOCloud\Laravel\Federation\FederationSigningKey;

/**
 * Optional ready-made controller for the JWKS endpoint.
 *
 * Only needed when `iocloud.federation.jwks_route` is set, or when routing this
 * class by hand. Most applications return `IOCloud::jwks()` from their own route
 * instead, which keeps the URL and its middleware theirs.
 *
 * This is the one endpoint the platform fetches from a partner: it reads the URL
 * from the identity provider row, never from a token, and caches the key set
 * until a token names an unknown `kid`. The document is public by design — it
 * contains only public key material.
 */
final class JwksController
{
    /**
     * How long the key set may be cached. Short enough that a rotation
     * propagates on its own, long enough to keep the endpoint cheap.
     */
    private const CACHE_SECONDS = 300;

    public function __construct(private readonly FederationSigningKey $signingKey)
    {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(
            data: $this->signingKey->jwks(),
            headers: ['Cache-Control' => 'public, max-age='.self::CACHE_SECONDS],
        );
    }
}
