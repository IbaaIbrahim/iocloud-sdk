# Changelog

All notable SDK changes are documented here. Each ecosystem can be released
independently, so entries identify the affected packages.

## 0.4.0 - 2026-08-17

### Shared — top-up packages and tenant top-ups

- Added `listTopupPackages`, `createTopupPackage`, `updateTopupPackage`,
  `grantTenantTopup`, `activateTenantTopup`, and `listTenantTopups` to all
  three packages, with typed `TopupPackage`, `TopupPackagePlan`,
  `TopupPurchase`, `ProvisionedTopup`, and `TenantTopup` models.
- A partner now authors its own top-up catalogue: previously only the platform
  owner could define packages, so a partner had no way to sell its tenants
  credits on top of their plan.
- `plan_uuids` scopes a package to particular tenant plans, so Bronze and
  Silver can carry different top-up menus. A package with no plans is offered
  to every tenant, which is what an unscoped package has always been. The
  platform enforces the scoping on the grant as well as in the listing, so a
  plan-scoped package cannot be granted to a tenant on the wrong plan.
- `grantTenantTopup` defaults to `activate_now`, so one call both records the
  purchase and provisions the credits — a pool the *tenant* owns, spent before
  the partner's own balance. That is the difference from a plan, which
  provisions caps against the partner's pool.
- Activation is the partner's call for the same reason it is for plans, is
  idempotent, and records the optional `reference` in the audit trail.
- Editing a package changes what it sells next, never what it already sold:
  `credits` is snapshotted onto each purchase. On update, omitting `plan_uuids`
  keeps the current scoping and `[]` clears it.
- Documented the paths and payloads in `openapi/iocloud.yaml`.

### Shared — tenant plans and subscriptions

- Added `listTenantPlans`, `subscribeTenant`, `activateTenantSubscription`, and
  `listTenantSubscriptions` to all three packages, with typed `TenantPlan`,
  `PlanSubscription`, `ProvisionedBalance`, and `TenantSubscription` models.
- `subscribeTenant` defaults to `activate_now`, so one call both subscribes the
  tenant and provisions its balance (the tenant's child cap from the plan's
  credits, plus one cap per active user from `user_credits_cap`).
- Activation is the partner's call, never the tenant's: tenants are the
  partner's clients and pay the partner, so the platform exposes no
  tenant-facing payment endpoint. Activation is idempotent, and the optional
  `reference` is recorded in the platform's audit trail.
- Documented the paths and payloads in `openapi/iocloud.yaml`.

## 0.3.0 - 2026-07-29

Released for Laravel only, as `laravel-v0.3.0` (Packagist `iocloud/laravel-sdk`
v0.3.0). The Python and Node packages carry the same code but were never tagged,
so 0.4.0 is their first published release and includes everything below.

Partner-side federation moves into the SDK. Previously a partner had to run its
own OIDC issuer — generate an RSA keypair, serve a JWKS, hand-roll a signed JWT
per login, and drive the RFC 8693 exchange. All of it is now SDK surface.

### Shared

- Added RSA-2048 keypair generation, JWK/JWKS construction, and subject-token
  signing (RS256) to all three packages.
- Key ids are RFC 7638 JWK thumbprints, so a `kid` is stable across restarts and
  identical across the Python, Node, and Laravel SDKs for the same keypair.
- Added `createIdentityProvider` / `listIdentityProviders` for the partner
  federation management endpoints.
- Added `exchangeSubjectToken` (RFC 8693, form-encoded, unauthenticated) and
  `federatedLogin`, which signs and exchanges in one call.
- Added `jwks()` and `federationDetails()` on the client, so publishing the key
  set is one call from a route handler at whatever path the host application
  chooses.
- Added the public-key PEM alongside the private one, so key generation produces
  a full inspectable pair.
- Partner client credentials are now validated when first used instead of when the
  client is constructed. Publishing a JWKS and exchanging a subject token need no
  partner token, so a partner can federate logins before those credentials exist.
  The failure names the missing setting.
- Added a dedicated token-exchange error type carrying the RFC 6749
  `error` / `error_description`, and a federation error type for local
  misconfiguration, raised before any request.
- Reserved claims (`iss`, `aud`, `iat`, `nbf`, `exp`, `jti`) cannot be overridden
  through extra claims, so a caller cannot widen the audience or extend a token's
  lifetime.
- Extended the OpenAPI contract with the provider, token-exchange, and JWKS
  schemas, plus matching response fixtures.
- Added `examples/laravel-demo`: a runnable partner portal whose tests verify
  SDK-signed tokens against the SDK-served JWKS using an independent JWT library.

### Python

- Added `iocloud_sdk.federation` with `FederationSigningKey`,
  `SubjectTokenIssuer`, and `build_jwks`, behind a new `federation` extra
  (`pip install "iocloud-sdk[federation]"`). The HTTP client keeps its
  single dependency.
- Added `IdentityProvider`, `FederatedSession`, and `SubjectTokenClaimNames`
  models.

### Node.js

- Added `FederationSigningKey`, `SubjectTokenIssuer`, and `buildJwks`, built on
  `node:crypto` with no new dependencies.
- Bodiless `GET` requests no longer send a JSON content type or body.

### Laravel

- Added `php artisan iocloud:keys`, modelled on `passport:keys`: it writes
  `storage/iocloud-federation-private.key` (0600) and
  `storage/iocloud-federation-public.key` (0644), refuses to clobber existing keys
  without `--force`, supports `--show` and `--path=`, and prints the `kid`, the
  `jwks_url` to register, and the public key set.
- Added `IOCloud::jwks()`, which returns the key set to publish from a route of
  the application's own choosing — the whole JWKS endpoint is
  `Route::get('/.well-known/jwks.json', fn () => IOCloud::jwks())`. The package
  claims no URL by default, so it cannot collide with an application's routing.
- Added `IOCloud::federationDetails()` reporting the issuer, audience, `jwks_url`,
  and `kid` in use.
- Added an optional `iocloud.federation.jwks_route`: set a path and the package
  registers that route itself, using `JwksController` (which is also routable by
  hand and sets `Cache-Control`).
- Added an `iocloud.federation` config block: issuer, audience, key source
  (inline PEM or path), token TTL, and claim mapping.
- Federation is entirely optional: applications that only provision tenants need
  no signing key, and resolving the client never loads one.
- Signing is built on `ext-openssl`; no JWT library is added to host apps.
- Key generation and export fall back to a minimal `openssl.cnf` bundled with the
  package. Windows PHP builds frequently ship without a resolvable one, which
  otherwise fails with `configuration file routines::no such file`. The platform's
  own OpenSSL configuration is tried first and never overridden.
- Added `IOCloudConfigurationException` for local setup problems, with
  `IOCloudFederationException` extending it — one `catch` now covers a missing
  signing key, a missing issuer, and absent partner credentials.
- Inline key material and passphrases are no longer trimmed when read from
  configuration. Trimming dropped a PEM's trailing newline and would have
  corrupted a passphrase with leading or trailing whitespace.

## 0.2.0 - never released under its own tag

No `laravel-v0.2.0`, `python-v0.2.0`, or `node-v0.2.0` tag was ever pushed. The
Laravel package shipped this work inside v0.3.0, which was cut from a commit well
above it; for Python and Node it forms part of their first release, 0.4.0. The
section is kept because it is the only record of the initial multi-package split.

### Python

- Moved the existing SDK into the multi-package repository layout.
- Preserved the existing public client and model API.
- Added client behavior tests and publishing metadata.

### Node.js

- Added the initial typed Node.js SDK with partner and tenant token caching.
- Added automatic authentication refresh, DTOs, typed errors, tests, and npm
  publishing metadata.

### Laravel

- Added the initial Laravel SDK with configuration, service-provider
  auto-discovery, facade support, immutable data objects, and typed errors.
- Added an automated subtree-split release for Packagist.

### Shared

- Added an OpenAPI 3.1 contract, shared response fixtures, CI, and independent
  release workflows.
