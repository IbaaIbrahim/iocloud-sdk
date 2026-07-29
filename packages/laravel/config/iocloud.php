<?php

return [
    'base_url' => env('IOCLOUD_BASE_URL', 'https://api.example.com'),
    'client_id' => env('IOCLOUD_CLIENT_ID'),
    'client_secret' => env('IOCLOUD_CLIENT_SECRET'),
    'timeout' => (float) env('IOCLOUD_TIMEOUT', 30),

    /*
    |---------------------------------------------------------------------------
    | Federation
    |---------------------------------------------------------------------------
    |
    | Only needed to federate your users into IOCloud. Your application becomes a
    | small OIDC issuer: it signs a short-lived JWT per login and publishes the
    | matching public key as a JWKS. Generate the keypair with:
    |
    |     php artisan iocloud:keys
    |
    | Then publish the key set from a route of your choosing:
    |
    |     Route::get('/.well-known/jwks.json', fn () => IOCloud::jwks());
    |
    */
    'federation' => [
        // The public base URL this application serves its JWKS from. Must match
        // the `issuer` registered with IOCloud byte for byte — scheme, port and
        // trailing slash all matter.
        'issuer' => env('IOCLOUD_FEDERATION_ISSUER'),

        // The `aud` claim written into every subject token; must be one of the
        // provider's allowed audiences.
        'audience' => env('IOCLOUD_FEDERATION_AUDIENCE', 'ai-ecosystem'),

        // The signing key, as a PEM string. Takes precedence over the path, so a
        // deployment can inject it from a secret manager.
        'private_key' => env('IOCLOUD_FEDERATION_PRIVATE_KEY'),

        // Where `php artisan iocloud:keys` writes the signing key, and where it is
        // read from when no inline PEM is set.
        'private_key_path' => env(
            'IOCLOUD_FEDERATION_PRIVATE_KEY_PATH',
            storage_path('iocloud-federation-private.key'),
        ),

        'private_key_passphrase' => env('IOCLOUD_FEDERATION_PRIVATE_KEY_PASSPHRASE'),

        // Subject-token lifetime. These tokens exist only to be exchanged once,
        // straight after login, so keep it short.
        'token_ttl' => (int) env('IOCLOUD_FEDERATION_TOKEN_TTL', 300),

        // Optional convenience: set a path here and the package registers that
        // route itself. Left null by default — publishing the key set is one line
        // in your own routes file, which keeps the URL and its middleware yours:
        //
        //     Route::get('/.well-known/jwks.json', fn () => IOCloud::jwks());
        'jwks_route' => env('IOCLOUD_FEDERATION_JWKS_ROUTE'),

        // Which claim carries each identity value. Registered with IOCloud and
        // written into every token, so both halves always agree.
        'claims' => [
            'user' => env('IOCLOUD_FEDERATION_USER_CLAIM', 'sub'),
            'tenant' => env('IOCLOUD_FEDERATION_TENANT_CLAIM', 'tenant_id'),
            'email' => env('IOCLOUD_FEDERATION_EMAIL_CLAIM', 'email'),
            'name' => env('IOCLOUD_FEDERATION_NAME_CLAIM', 'name'),
        ],
    ],
];
