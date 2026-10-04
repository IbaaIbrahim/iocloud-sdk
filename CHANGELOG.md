# Changelog

All notable SDK changes are documented here. Each ecosystem can be released
independently, so entries identify the affected packages.

## Unreleased

Identity providers register and read back `jwks_path`, for all three packages.
The platform stores where it fetches an issuer's keys as `jwks_path`, a path on
the issuer's own origin, and is dropping `jwks_url`: a request that carries it
is a 422, and a response no longer contains it. Nothing is removed: `jwksUrl`
stays on `createIdentityProvider`, deprecated, and on `IdentityProvider`,
derived.

### Added

- `jwksPath` on `createIdentityProvider`: `jwks_path=` in Python, the
  `jwksPath` option in Node, and in Laravel a `$jwksPath` parameter added last,
  so pass it by name. Omitted, it is `/.well-known/jwks.json`, the platform's
  default. The platform requires a document under `/.well-known/`.
- `issuerOrigin` and `jwksPath` on `IdentityProvider` (`issuer_origin` and
  `jwks_path` in Python). Code that builds a provider itself may leave them
  out: they are derived from the issuer and the JWKS URL.
- `jwksPath` on `SubjectTokenIssuer` (`jwks_path` in Python, `jwksPath()` in
  Laravel) and Laravel's `FederationConfig::jwksPath()`: the path of the
  issuer's own JWKS URL, which is what to register.
- `jwks_path` (`jwksPath` in Node) in `federationDetails()`, beside `jwks_url`.

### Changed

- `createIdentityProvider` sends `jwks_path` and never `jwks_url`. With
  neither argument it sends `/.well-known/jwks.json`, where it used to send
  `<issuer>/.well-known/jwks.json`. The two differ only for an issuer with a
  path, whose JWKS URL the platform refused already, as not under the
  origin's `/.well-known/`: such an issuer now registers, and its keys must be
  served from the origin's `/.well-known/jwks.json`.
- `IdentityProvider.jwksUrl` is the response's `jwks_url` when it has one, and
  `issuer_origin + jwks_path` otherwise. A provider without `jwks_url` used to
  fail to parse — a `KeyError` in Python, a `TypeError` in Node, an undefined
  array key in Laravel — and now parses.
- Laravel's `iocloud:keys` prints the JWKS URL to serve and the `jwks_path` to
  register, instead of a `jwks_url` to register.
- The OpenAPI contract and the shared `identity-provider.json` fixture are the
  platform's current shape: `issuer_origin` and `jwks_path`, no `jwks_url`. The
  READMEs and the Laravel demo register `jwksPath`.

### Deprecated

- `jwksUrl` on `createIdentityProvider` (`jwks_url=` in Python, `$jwksUrl` in
  Laravel, where it keeps its fifth position). It is still accepted: the SDK
  sends its path, after checking that it is on the issuer's origin — scheme,
  host and port, compared canonically — and carries no query or fragment.
  Otherwise, or when `jwksPath` is passed too, it fails before any request:
  `ValueError` in Python, `TypeError` in Node, `InvalidArgumentException` in
  Laravel. Using it warns: a `DeprecationWarning` in Python, a one-time
  `DeprecationWarning` (code `IOCLOUD_JWKS_URL`) in Node, and
  `E_USER_DEPRECATED` in Laravel.

## 0.7.0 - 2026-10-01

`federatedLogin` hands the subject token to your frontend instead of exchanging
it, for all three packages. Your backend signs the token; the chat client
fetches it through the callback you give it and exchanges it with the platform
itself, so the platform session never passes through your backend. Python and
npm have never been published, so their first release, at this version, also
carries 0.6.0's changes.

### Breaking

- `federatedLogin` returns the signed subject token and sends nothing: a `str`
  in Python, a `string` in Node, where it is now synchronous (`await` still
  works, `.then()` does not), and a `string` in Laravel, facade included. It
  used to exchange the token itself and return the `FederatedSession`. Return
  the token to your frontend instead: its chat client exchanges it at
  `/v1/federation/token`. A backend that wants the session still gets it with
  `exchangeSubjectToken(federatedLogin(…))`, exactly what 0.6.0's
  `federatedLogin` did.

### Changed

- The exchange's `tenant_created` now reaches whoever exchanges the token —
  your chat client, not your backend — so a just-in-time tenant should be
  created on its plan by naming it with `planCode`, rather than subscribed with
  `subscribeTenant` once `tenantCreated` comes back.
- The platform's refusals of an exchange, `invalid_target` included ("The
  token's tenant could not be created.", "The token's tenant plan does not
  exist."), reach the chat client that exchanges the token, or are raised by
  `exchangeSubjectToken` as the package's token-exchange error.
- The READMEs show the endpoint your frontend fetches its token from and the
  exchange the chat client makes. The Laravel demo signs the subject token and
  shows it instead of exchanging it, and its tests verify that token against
  the portal's own JWKS.

## 0.6.0 - 2026-09-30

Just-in-time tenants, and tenant slugs the platform generates, for all three
packages. A partner no longer has to call `createTenant` in a separate flow
before a new organisation's first login: the login carries the tenant's
details, and the platform creates the tenant during the token exchange if it
does not exist yet. `createTenant` loses its slug, and a tenant's contact email
may now be null (see Breaking); every new parameter is optional.

### Breaking

- `createTenant` takes no slug. The platform generates one from the name —
  transliterated to lowercase ASCII and hyphens, at most 91 characters cut at a
  word boundary, or `tenant` when the name has no letters or digits — then a
  hyphen and the first 8 hex characters of the tenant's uuid, as in
  `acme-corp-5d3c1a7e`, and returns it on the tenant. It ignores a slug an
  older client still sends. Python's keyword-only `create_tenant` raises
  `TypeError` for `slug=`, and Node's `CreateTenantInput` has no `slug`:
  TypeScript flags one, and a JavaScript caller's is not sent.
- Laravel keeps `createTenant`'s third parameter as a deprecated
  `?string $slug = null` that is never sent and throws
  `InvalidArgumentException` when given a value. Deleting it would have
  shifted a 0.5.0 positional call, `createTenant($app, $name, $slug, $email)`,
  silently: its slug would have gone out as the contact email and its email
  as the external id. With the slot, that call fails loudly instead. The slot
  goes in 1.0.
- A tenant's `contactEmail` may be null, in every tenant the platform returns,
  so `Tenant.contactEmail` is nullable in all three packages.

### Added

- `createTenant`'s `contactEmail` is optional: omit it, or pass null, for a
  tenant with none, and it is left out of the request.
- `TenantProfile` (`name`, and an optional `contactEmail`, `planCode` and
  `externalTenantId`): what `createTenant` takes, less the application, which
  is the identity provider's, plus the plan code, which `createTenant` does
  not take (below). The tenant claim becomes the new tenant's external id, and
  the platform generates its slug the same way.
- `TenantProfile.externalTenantId` (`external_tenant_id` in Python) is the
  `externalId` `createTenant` takes, so one description of the tenant serves
  both calls. It is sent once, as the token's tenant claim, never inside
  `tenant_profile`, so `federatedLogin` and `SubjectTokenIssuer.issue` make
  their own `externalTenantId` optional: a login names its tenant there or in
  its profile. Given in both, it must be the same id, or the issuer refuses
  the login before signing, because the platform finds and creates the tenant
  by the claim alone and would ignore a profile that said otherwise; given in
  neither, it is refused too. Existing calls that pass `externalTenantId` keep
  working unchanged, positional ones in Laravel included, where
  `$externalTenantId` stays the second parameter and is the profile's fourth.
- `federatedLogin` and `SubjectTokenIssuer.issue` take an optional trailing
  `tenant`, signed as the subject token's `tenant_profile` claim: the name,
  plus `contact_email` and `plan_code` when they are set. The claim's name is
  fixed, whatever the claim-name mapping says, and `tenant_profile` joins the
  reserved claims, so `extraClaims` can neither set nor override it: `tenant`
  is the only way in. The issuer refuses an empty name, or an empty contact
  email, plan code or external tenant id when one is given, before signing;
  lengths are the platform's to judge, and it answers a malformed profile with
  `invalid_grant` when it needs it.
- `createIdentityProvider` takes an optional `allowJitTenants` (default false),
  and `IdentityProvider` carries it back. In Laravel it is the last parameter,
  after `$claimNames`, so a call that passes the arguments positionally keeps
  working; Python takes it by keyword and Node in its input object. With it
  on, a login whose tenant claim names no tenant of the provider's application
  creates that tenant, active, and then its user just in time.
- `FederatedSession` gains `tenantUuid`, the tenant the session belongs to,
  and `tenantCreated`, true only for the login that created it. A platform
  that predates them sends neither, so they read as `null` and `false`, as
  `allowJitTenants` reads as `false`.
- Just-in-time tenants require just-in-time users: the users of a tenant
  created at login can only be created at login, so the platform refuses
  `allowJitTenants` without `allowJitUsers` with a `422`. For the same reason
  the login needs an `email` claim, as every just-in-time login does.
- The profile creates a tenant; it never updates one. Once the tenant exists
  the platform ignores the profile, so a later login with different values
  changes nothing.
- In the rare case the platform cannot create the tenant, it refuses the login
  with `invalid_target` ("The token's tenant could not be created."), surfaced
  as the package's token-exchange error.
- A tenant created at login has no plan, unless its profile names one by
  `planCode` (below), so it draws on your credits uncapped. When
  `tenantCreated` is true and the profile named no plan, subscribe it with
  `subscribeTenant`.
- Tenant plans carry an optional `planCode`: your own code for the plan, 1 to
  100 characters, unique among your tenant plans and matched exactly, case
  included. You set it on the plan in the Admin Dashboard, or with
  `POST /v1/partner/plans/tenant` and
  `PATCH /v1/partner/plans/tenant/{plan_uuid}`; the SDKs only read it.
  `TenantPlan`, which `listTenantPlans` returns, gains `planCode` (`plan_code`
  in Python): null for a plan without one, and from a platform that predates
  plan codes. It is a defaulted last field in Python and a defaulted last
  constructor parameter in Laravel, so code that builds a `TenantPlan` itself
  keeps working.
- `TenantProfile.planCode` names the plan a just-in-time tenant is created on.
  In Laravel it is the third constructor parameter, after `$contactEmail`, so
  a positional call keeps working. The login that creates the tenant
  subscribes it to your tenant plan with that code, on a monthly billing cycle
  and activated at once, as `subscribeTenant` does by default, and provisions
  the tenant's cap and the login user's cap from the plan. The subscription is
  written in the same transaction as the tenant, so the tenant exists with its
  plan or not at all. A code that names none of your tenant plans refuses the
  login with `invalid_target` ("The token's tenant plan does not exist."),
  surfaced as the package's token-exchange error, and creates nothing; one
  that is not a string of 1 to 100 characters makes the profile malformed
  (`invalid_grant`). Like the rest of the profile, it is never read once the
  tenant exists, so it never changes an existing tenant's plan.
- Documented the provider flag, the `tenant_profile` claim and its
  `plan_code`, a tenant plan's `plan_code`, the two new exchange members, the
  `invalid_target` an unknown plan code causes, the generated slug and the
  optional contact email in `openapi/iocloud.yaml`.

## 0.5.0 - 2026-09-30

A clean break for all three packages, made for a platform that ties every
identity provider to one of the partner's applications. SDK 0.4.0's
`createIdentityProvider` and `mapExternalTenant` stop working against it.

### Breaking

- `createIdentityProvider` takes a required `applicationUuid`, now its first
  parameter, and `IdentityProvider` carries it back. Every identity provider
  belongs to one of your applications, and a token it signs logs users into
  that application only. The platform answers `APPLICATION_NOT_FOUND` (404)
  for an application that does not exist or is not yours.
- `mapExternalTenant` and `ExternalTenantMapping` are removed, with the route
  behind them. The id your tokens carry in the tenant claim is now a column of
  the tenant itself, unique within its application: pass it as `externalId`
  to `createTenant`, or set, change, and clear it with `setTenantExternalId`.
- The platform no longer links users per provider. A user's external id — the
  id your tokens carry as `sub`, or your configured user claim — is unique
  within its tenant. The exchange resolves the token's tenant within the
  provider's application, then the user within that tenant, never by email.
- With the ids on the rows they identify, an application may now have several
  providers, and replacing an issuer loses no tenant and no user. The price is
  that every provider of one application must use the same tenant and user
  ids, because any of them logs in any of that application's users.
- `examples/laravel-demo`: `demo:federation:register` registers the provider
  for `--application=` (or `DEMO_IOCLOUD_APPLICATION_UUID`), refuses to
  register one without it, and sets the tenant's external id instead of
  mapping it.

### Added

- `setTenantExternalId(tenantUuid, externalId)` sets or changes the id a
  tenant is reached by; `null` clears it, which stops federated logins into
  that tenant. An id another tenant of the application holds is refused with
  `TENANT_EXTERNAL_ID_TAKEN` (409). `Tenant` gains `externalId`, null when
  none is set.
- `createTenant` takes an optional trailing `externalId`, so a tenant can be
  created ready to federate in one call.
- Added `createUser` and `updateUserStatus` to all three packages, with a
  typed `User` model. They are what makes federation with just-in-time
  provisioning off usable: pre-create each user with `externalId` set to the
  `sub` your tokens will carry, then activate it. A pre-created user starts
  `pending` and cannot log in until `updateUserStatus` makes it `active`.
- Both user calls are tenant-scoped: they authenticate with a tenant
  credential from `createTenantCredentials` rather than the partner token,
  and refresh the tenant token once on a `401`. An external id another user of
  the tenant holds is refused with `USER_EXTERNAL_ID_TAKEN` (409).
- Documented the new paths and payloads in `openapi/iocloud.yaml`, and removed
  the mapping path and its schemas.

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
