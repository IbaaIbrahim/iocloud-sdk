<?php

return [
    /*
    |---------------------------------------------------------------------------
    | Demo user directory
    |---------------------------------------------------------------------------
    |
    | Stands in for the partner's own user table. `subject` is the value written
    | into the token's user claim: it must be stable and never reused, because
    | IOCloud keys the platform account off it. `external_tenant_id` is the
    | partner's own organisation id, which an IOCloud tenant of the provider's
    | application must carry as its external id.
    |
    */
    'users' => [
        [
            'subject' => 'acme-user-1001',
            'name' => 'Dana Okafor',
            'email' => 'dana.okafor@acme.example',
            'email_verified' => true,
            'external_tenant_id' => env('DEMO_EXTERNAL_TENANT_ID', 'acme-tenant-1'),
        ],
        [
            'subject' => 'acme-user-1002',
            'name' => 'Milo Fernandes',
            'email' => 'milo.fernandes@acme.example',
            'email_verified' => true,
            'external_tenant_id' => env('DEMO_EXTERNAL_TENANT_ID', 'acme-tenant-1'),
        ],
        [
            // Kept unverified on purpose: with require_email_verified on the
            // provider, IOCloud rejects this login. That rejection is the point.
            'subject' => 'acme-user-1003',
            'name' => 'Unverified Ulla',
            'email' => 'ulla@acme.example',
            'email_verified' => false,
            'external_tenant_id' => env('DEMO_EXTERNAL_TENANT_ID', 'acme-tenant-1'),
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Provider registration defaults
    |---------------------------------------------------------------------------
    |
    | Used by `php artisan demo:federation:register`.
    |
    */
    'provider_name' => env('DEMO_PROVIDER_NAME', 'Acme Portal (demo)'),
    'require_email_verified' => (bool) env('DEMO_REQUIRE_EMAIL_VERIFIED', true),
    'allow_jit_users' => (bool) env('DEMO_ALLOW_JIT_USERS', true),

    // The IOCloud application the identity provider belongs to: a token it signs
    // logs users into that application's tenants only.
    'iocloud_application_uuid' => env('DEMO_IOCLOUD_APPLICATION_UUID'),

    // The IOCloud tenant (one of that application's) whose external id is set
    // to DEMO_EXTERNAL_TENANT_ID.
    'iocloud_tenant_uuid' => env('DEMO_IOCLOUD_TENANT_UUID'),
];
