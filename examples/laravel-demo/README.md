# Acme Portal — IOCloud federation demo

A minimal Laravel portal that federates its own users into IOCloud with
[`iocloud/laravel-sdk`](../../packages/laravel). It exists to exercise the SDK
end to end: key generation, the JWKS endpoint, provider registration, the
tenant's external id, and the RFC 8693 token exchange.

The demo consumes the SDK from a Composer `path` repository, so any local change
to `packages/laravel` is picked up immediately.

## What the SDK does, and what is left for you

| Step | Who does it |
| --- | --- |
| Generate the RSA keypair | `php artisan iocloud:keys` (SDK) |
| Build the JWKS document | SDK — `IOCloud::jwks()` |
| Choose the JWKS URL and route it | You — one line in [`routes/web.php`](routes/web.php) |
| Sign a subject token per login | SDK, inside `IOCloud::federatedLogin()` |
| Exchange it for a platform session | SDK, same call |
| Register the issuer for an application, set the tenant's external id | SDK calls, driven by [`RegisterFederationCommand`](app/Console/Commands/RegisterFederationCommand.php) |
| Look up the logged-in user | You — [`PartnerFederation`](app/Services/PartnerFederation.php) |

The JWKS endpoint, in full:

```php
Route::get('/.well-known/jwks.json', fn (): array => IOCloud::jwks());
```

The whole partner-side login, in full:

```php
$session = $iocloud->federatedLogin(
    subject: $user->subject,             // stable, never-reused partner user id
    externalTenantId: $user->tenantId,   // your organisation id
    email: $user->email,
    name: $user->name,
    emailVerified: true,
);
```

## Setup

Requires PHP 8.2+ with `ext-openssl`, and Composer.

```bash
cd examples/laravel-demo
cp .env.example .env
composer install
php artisan key:generate
```

The SDK is required as `"iocloud/laravel-sdk": "@dev"` from a Composer `path`
repository — the `@dev` stability flag is what lets an unreleased local package
satisfy the constraint.

Fill in the partner credentials in `.env`:

```dotenv
IOCLOUD_BASE_URL=http://127.0.0.1:8001      # the IOCloud Admin API
IOCLOUD_CLIENT_ID=...                       # partner client credentials
IOCLOUD_CLIENT_SECRET=...
IOCLOUD_FEDERATION_ISSUER=http://127.0.0.1:8010
DEMO_IOCLOUD_APPLICATION_UUID=...           # the application the provider belongs to
DEMO_IOCLOUD_TENANT_UUID=...                # its tenant that carries DEMO_EXTERNAL_TENANT_ID
```

`IOCLOUD_FEDERATION_ISSUER` must be the URL **IOCloud** can reach this portal
on, and it must match the registered issuer byte for byte — scheme, host, port,
no trailing slash. It is where IOCloud fetches the JWKS.

Generate the signing keypair:

```bash
php artisan iocloud:keys
```

It writes `storage/iocloud-federation-private.key` (owner-only) and
`storage/iocloud-federation-public.key`, then prints the `kid`, the `jwks_url` to
register, and the public key set.

Register the issuer and set the tenant's external id:

```bash
php artisan demo:federation:register \
    --application=<iocloud-application-uuid> \
    --tenant=<iocloud-tenant-uuid>
```

Both options fall back to `DEMO_IOCLOUD_APPLICATION_UUID` and
`DEMO_IOCLOUD_TENANT_UUID`. The provider belongs to that application: a token it
signs logs users into that application's tenants only, so the tenant must be one
of them. The command sets the tenant's external id to `DEMO_EXTERNAL_TENANT_ID`,
the value this portal's tokens carry in the tenant claim.

Idempotent on the provider: if the issuer is already registered it reuses the
existing row rather than tripping `ISSUER_ALREADY_TRUSTED`, and needs no
application.

Run it:

```bash
php artisan serve --port=8010
```

Open <http://127.0.0.1:8010>, pick a user, and press **Continue to IOCloud**.
The result page shows the platform session; a rejection page shows the RFC error
and what each one means.

Check what IOCloud will fetch:

```bash
curl -s http://127.0.0.1:8010/.well-known/jwks.json
```

## Tests

```bash
composer install
vendor/bin/phpunit
```

The tests are the real point of the demo. Nothing is stubbed on the SDK side:
the fake IOCloud in [`FederatedLoginTest`](tests/Feature/FederatedLoginTest.php)
does what the platform does — fetches this portal's own JWKS endpoint over HTTP
and verifies the subject token against it with `firebase/php-jwt`, an
independent JWT library. A signing, JWKS, or claim-mapping regression in the SDK
fails these tests.

Covered:

- the SDK-published JWKS parses as a standard key set and leaks no private
  members (`d`, `p`, `q`, …);
- every subject token verifies against that key set, with the issuer, audience,
  subject, tenant, email-verified and lifetime claims the platform checks;
- each login gets a distinct `jti`, so a token cannot be replayed;
- the exchange uses the RFC 8693 form grammar and sends no `Authorization`
  header — the subject token is the credential;
- a rejected exchange surfaces its `error`/`error_description`;
- an unconfigured signing key fails before any network call;
- provider registration sends exactly the application, issuer, JWKS URL, and
  claim names the SDK signs with, and refuses to register without an
  application;
- the tenant gets the portal's organisation id as its external id.

## The three failure modes worth knowing

| Symptom | Cause |
| --- | --- |
| `invalid_grant` | Issuer not registered or disabled, signature does not verify against the published JWKS, wrong audience, token expired or replayed, or an unverified email where the provider requires one. |
| `invalid_target` | No tenant of the provider's application carries the token's tenant claim as its external id. Run `demo:federation:register --tenant=…`. |
| `federation_not_configured` | No signing key or no `IOCLOUD_FEDERATION_ISSUER`. |

IOCloud deliberately keeps `error_description` generic; the precise reason is in
its `user.federated_login_failed` audit event.

Reference: [`docs/FEDERATION.md`](../../../docs/FEDERATION.md) in the
AI-EcoSystem repository documents every claim and provider field.
