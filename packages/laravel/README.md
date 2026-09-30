# IOCloud Laravel SDK

```bash
comlposer require iocloud/laravel-sdk
```

Publish the configuration if it needs customization:

```bash
php artisan vendor:publish --tag=iocloud-config
```

Configure the client:

```dotenv
IOCLOUD_BASE_URL=https://api.example.com
IOCLOUD_CLIENT_ID=partner-client-id
IOCLOUD_CLIENT_SECRET=partner-client-secret
```

The package is auto-discovered by Laravel. Resolve the client with dependency
injection or use the facade:

```php
use IOCloud\Laravel\Facades\IOCloud;

$tenant = IOCloud::createTenant(
    applicationUuid: '11111111-1111-1111-1111-111111111111',
    name: 'Acme workspace',
    contactEmail: 'ops@acme.example', // optional
    externalId: 'acme-tenant-1', // optional: the tenant claim your tokens carry
);
```

The platform generates the tenant's slug from its name and returns it as
`$tenant->slug` (`acme-workspace-5d3c1a7e`, say), so `createTenant()` takes none.
Its third parameter, `$slug`, remains only to refuse a positional call written
for 0.5.0, which would otherwise shift its contact email and external id: any
value throws `InvalidArgumentException`, and the slot goes in 1.0. Pass the
other arguments by name. `$tenant->contactEmail` is null for a tenant created
without one.

Partner and tenant tokens are cached until shortly before expiration. A `401`
causes one token refresh and retry. API failures throw
`IOCloudAPIException`; authentication failures throw
`IOCloudAuthenticationException`.

## Federation: signing your users into IOCloud

Federating means your application acts as a small OIDC issuer — it signs a
short-lived JWT for each user who logs in, and publishes the matching public key
so IOCloud can verify it. The SDK owns all the cryptography; what is left is one
artisan command, a little configuration, and two one-line calls:

```php
// A route of your choosing publishes the public keys.
Route::get('/.well-known/jwks.json', fn () => IOCloud::jwks());

// Your login controller signs a token and exchanges it for a platform session.
$session = IOCloud::federatedLogin(subject: $user->id, externalTenantId: $user->tenant_id);
```

### 1. Generate the signing keypair

Same shape as `passport:keys`:

```bash
php artisan iocloud:keys
```

Writes the pair into `storage/`:

```text
storage/iocloud-federation-private.key   chmod 0600 — the secret
storage/iocloud-federation-public.key    chmod 0644 — for reference
```

and prints the key id, the `jwks_url` to register, and the public key set. It
refuses to overwrite existing keys without `--force`. Use `--show` to print the
private key for a secret manager instead of writing it, or `--path=` to put it
elsewhere.

The private key is the source of truth — the JWKS is derived from it, so the
public file only exists to inspect or hand to another tool.

The key id is the RFC 7638 thumbprint of the key itself, so it stays stable
across restarts and deployments — a published JWKS and a signed token always
agree on it.

### 2. Configure the issuer

```dotenv
# The URL IOCloud can reach your app on. Must match the registered issuer byte
# for byte: scheme, host, port, no trailing slash.
IOCLOUD_FEDERATION_ISSUER=https://portal.acme.example
IOCLOUD_FEDERATION_AUDIENCE=ai-ecosystem
IOCLOUD_FEDERATION_TOKEN_TTL=300
```

Keys can also come from a PEM string via `IOCLOUD_FEDERATION_PRIVATE_KEY`, which
takes precedence over the path.

### 3. Publish the JWKS from your own route

`IOCloud::jwks()` returns the document. One line in `routes/web.php`:

```php
use IOCloud\Laravel\Facades\IOCloud;

Route::get('/.well-known/jwks.json', fn () => IOCloud::jwks());
```

Laravel serializes the returned array as JSON. The path is yours — it only has to
match the `jwks_url` you register below — so wrap it in whatever middleware,
caching, or rate limiting you use for public endpoints.

```jsonc
// GET /.well-known/jwks.json
{
  "keys": [
    {
      "kty": "RSA",
      "use": "sig",
      "alg": "RS256",
      "kid": "Z8uGuexwoImnwxMB4E86A4vRhCSmQJR21rLH9jhG9Gw",
      "n": "vFj4wRAEcZaUUyFro_pZSzLU…",
      "e": "AQAB"
    }
  ]
}
```

Public key material only — safe to serve publicly and to cache.

Two alternatives if you would rather not write that line:

```php
// Set iocloud.federation.jwks_route (or IOCLOUD_FEDERATION_JWKS_ROUTE) and the
// package registers the route itself, named `iocloud.federation.jwks`.

// Or route the ready-made controller, which adds a Cache-Control header:
Route::get('/.well-known/jwks.json', IOCloud\Laravel\Http\Controllers\JwksController::class);
```

`IOCloud::federationDetails()` returns the `issuer`, `audience`, `jwks_url`, and
`kid` this application signs under — handy for a diagnostics page.

### 4. Register your issuer with IOCloud, once

```php
use IOCloud\Laravel\Facades\IOCloud;
use IOCloud\Laravel\Federation\FederationConfig;

$federation = app(FederationConfig::class);

$provider = IOCloud::createIdentityProvider(
    applicationUuid: '11111111-1111-1111-1111-111111111111',
    name: 'Acme Portal',
    issuer: $federation->requireIssuer(),
    allowedAudiences: [$federation->audience],
    jwksUrl: $federation->jwksUrl(),
    requireEmailVerified: true,
    allowJitUsers: true,
    claimNames: $federation->claimNames,
);

// Give a tenant of that application the organisation id your tokens carry, or
// pass `externalId` to createTenant(). Without it, logins fail with
// `invalid_target`; `externalId: null` clears it again.
IOCloud::setTenantExternalId(
    tenantUuid: '22222222-2222-2222-2222-222222222222',
    externalId: 'acme-tenant-1',
);
```

The provider belongs to that application: a token it signs logs users into the
application's tenants only. An application may have several providers, and any
of them logs in any of its users, so they must all sign the same tenant and user
ids. A tenant's `externalId` is unique within its application; one another
tenant holds is refused with `TENANT_EXTERNAL_ID_TAKEN`.

Passing the config's `claimNames` and `jwksUrl()` is what keeps the registration
and the tokens you sign from drifting apart. `listIdentityProviders()` reads back
what IOCloud has stored. Pass `allowJitTenants: true` as well to create tenants at
their first login (see below).

### 5. Log a user in

```php
$session = IOCloud::federatedLogin(
    subject: $user->id,                  // stable and never reused
    externalTenantId: $user->tenant_id,
    email: $user->email,
    name: $user->name,
    emailVerified: $user->hasVerifiedEmail(),
);

$session->accessToken;   // opaque platform token — Authorization: Bearer …
$session->userUuid;      // the IOCloud user this session belongs to
$session->tenantUuid;    // and its tenant
$session->expiresAt;     // no refresh tokens; sign and exchange again
```

One call signs the subject token with your private key and exchanges it. Use
`exchangeSubjectToken()` instead if a token was signed elsewhere.

`subject` is the identity key IOCloud stores — the user's `externalId` within
its tenant. It must be stable across logins and never reused for a different
person — an email change at your end must not change it.

A rejected exchange throws `IOCloudTokenExchangeException`, carrying the RFC 6749
`error` and `errorDescription`:

| `error`          | Cause                                                                                                                                                                                                                                                                                                                                                                                                                  |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `invalid_grant`  | Unknown or disabled issuer, signature does not verify against the published JWKS, wrong audience, token expired or replayed, missing claims, unverified email where required, a subject no user of the tenant has as its`externalId` with JIT provisioning off, a user that is not active — a pre-created user is `pending` until activated — or a malformed `tenant_profile` when the tenant must be created. |
| `invalid_target` | No tenant of the provider's application has the tenant claim as its `externalId` and this login may not create one, that tenant is not active, the profile's `planCode` names none of your tenant plans, or, rarely, the tenant could not be created.                                                                                                                                                                  |

`IOCloudFederationException` is thrown for local misconfiguration — no signing
key, no issuer, an unreadable PEM — before any request is made.

### Federation with just-in-time provisioning off

With `allowJitUsers: false`, a login reaches only a user that already exists.
Create each one with the `sub` your tokens will carry as its `externalId`, then
activate it — a user created this way starts `pending`. The user endpoints are
tenant-scoped, so these calls take a tenant credential instead of the partner
token, and refresh the tenant token once on a `401`:

```php
// The secret is returned once, at creation: persist it for later user calls.
$credential = IOCloud::createTenantCredentials($tenant->uuid);

$user = IOCloud::createUser(
    name: $portalUser->name,
    email: $portalUser->email,
    tenantClientId: $credential->clientId,
    tenantClientSecret: $credential->clientSecret,
    externalId: (string) $portalUser->id,   // the `sub` your tokens carry
);
$user->status;   // 'pending'

IOCloud::updateUserStatus(
    userUuid: $user->uuid,
    status: 'active',
    tenantClientId: $credential->clientId,
    tenantClientSecret: $credential->clientSecret,
);
```

An `externalId` another user of the tenant already holds is refused with
`USER_EXTERNAL_ID_TAKEN`. `status: 'deactivated'` stops a user's logins without
deleting it.

### Creating the tenant at its first login

With `allowJitTenants: true` — which requires `allowJitUsers: true`, or the
platform answers `422` — a login whose tenant claim names no tenant of the
provider's application creates that tenant from the `TenantProfile` you pass as
`tenant`: the external id, name and optional contact email `createTenant()`
takes, plus an optional plan (below). The external id is the login's tenant
claim, and becomes the tenant's `externalId`; the login's user is created just
in time in it, so the login needs `email`:

```php
use IOCloud\Laravel\Data\TenantProfile;
use IOCloud\Laravel\Facades\IOCloud;

$session = IOCloud::federatedLogin(
    subject: $user->id,
    email: $user->email,
    tenant: new TenantProfile(
        name: $organisation->name,
        contactEmail: $organisation->contact_email,
        externalTenantId: $organisation->id,
    ),
);

if ($session->tenantCreated) {
    // Created without a planCode, a new tenant has no plan, so it draws on
    // your credits uncapped.
    IOCloud::subscribeTenant(tenantUuid: $session->tenantUuid, planUuid: $planUuid);
}
```

Or name one of your tenant plans by its code, and the login creates the tenant
on that plan, with nothing to subscribe afterwards:

```php
$session = IOCloud::federatedLogin(
    subject: $user->id,
    email: $user->email,
    tenant: new TenantProfile(
        name: $organisation->name,
        planCode: 'growth',
        externalTenantId: $organisation->id,
    ),
);
```

The tenant is then subscribed to that plan, on a monthly billing cycle and
active at once, as `subscribeTenant()` leaves it by default, with its cap and
the cap of the login's user provisioned from the plan, all in the same
transaction as the tenant: it exists on its plan or not at all. The code is
matched exactly, case included, against the `planCode` you set on the plan in
the Admin Dashboard, which `listTenantPlans()` returns as `$plan->planCode`
(null for a plan without one). A code none of your plans has refuses the login
with `invalid_target` and creates nothing. `$planCode` is the constructor's
third parameter, after `$contactEmail`, and `$externalTenantId` the fourth.

The profile's `externalTenantId` is sent once, as the tenant claim, so
`federatedLogin()` needs no `$externalTenantId` of its own; pass both and they
must be the same id, or the SDK refuses the login before signing, since the
platform finds and creates the tenant by the claim alone. The rest is signed as
the subject token's `tenant_profile` claim, under that name whatever
`claimNames` say, and `extraClaims` can neither set nor override it. Leave out
`contactEmail` and `planCode` and the claim carries the name alone. The profile creates a tenant and never updates one: once the tenant
exists it is ignored, so passing it on every login is harmless, and it never
changes an existing tenant's plan. The SDK throws `IOCloudFederationException`
for an empty name, or an empty contact email, plan code or external tenant id
when one is given, before signing; the platform judges the rest and answers a malformed profile
with `invalid_grant`.

`tenantCreated` is true only for the login that created the tenant. A platform
that predates just-in-time tenants sends neither session member, so
`tenantUuid` reads as null and `tenantCreated` as false — and a provider's
`allowJitTenants` as false.

### Rotating keys

Generate a replacement with `--force`, and publish both keys while tokens signed
by the old one are still in flight:

```php
use IOCloud\Laravel\Federation\FederationSigningKey;

return response()->json(FederationSigningKey::buildJwks($current, $retiring));
```

Verifiers select by `kid`, so a retiring key keeps working until it is dropped
from the document.

### Working example

[`examples/laravel-demo`](../../examples/laravel-demo) is a runnable portal with
tests that verify the SDK's tokens against its own JWKS endpoint using an
independent JWT library.
