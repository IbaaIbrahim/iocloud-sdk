# IOCloud Node.js SDK

> **Not on npm yet.** `@iocloud/sdk` has never been published — no `node-v*` tag
> has been pushed — so the command below will not resolve. Until the first
> release, depend on `packages/node` from a checkout. See
> [Releases](../../README.md#releases).

```bash
npm install @iocloud/sdk
```

```ts
import { IOCloudClient } from "@iocloud/sdk";

const client = new IOCloudClient({
  clientId: process.env.IOCLOUD_CLIENT_ID!,
  clientSecret: process.env.IOCLOUD_CLIENT_SECRET!,
  baseUrl: process.env.IOCLOUD_BASE_URL!,
});

const tenant = await client.createTenant({
  applicationUuid: "11111111-1111-1111-1111-111111111111",
  name: "Acme workspace",
  contactEmail: "ops@acme.example", // optional
  externalId: "acme-tenant-1", // optional: the tenant claim your tokens carry
});
```

The platform generates the tenant's slug from its name and returns it as
`tenant.slug` (`acme-workspace-5d3c1a7e`, say), so `createTenant` takes none.
`tenant.contactEmail` is `null` for a tenant created without one.

Partner and tenant tokens are cached until shortly before expiration. An
authenticated request that returns `401` is retried once with a refreshed
token. API failures throw `IOCloudAPIError`; authentication failures throw
`IOCloudAuthenticationError`.

## Federation: signing your users into IOCloud

Federating means your application acts as a small OIDC issuer — it signs a
short-lived JWT for each user who logs in, and publishes the matching public key
so IOCloud can verify it. The SDK owns the keypair, the JWKS document, the token,
and the RFC 8693 exchange, on `node:crypto` alone — no extra dependency.

### Generate and persist a keypair

```ts
import { writeFileSync } from "node:fs";
import { FederationSigningKey } from "@iocloud/sdk";

const signingKey = FederationSigningKey.generate();

// Store this as a secret; reload it on boot so the published kid stays stable.
writeFileSync("federation-private-key.pem", signingKey.privateKeyPem);

signingKey.kid;      // RFC 7638 thumbprint, derived from the key itself
signingKey.jwks();   // serve this at <issuer>/.well-known/jwks.json
```

The key id is derived from the key, not random, so a reloaded key keeps its `kid`
and a published JWKS always matches the tokens you sign.

### Publish the JWKS from your own route

`signingKey.jwks()` — or `client.jwks()` once a token issuer is configured —
returns the document. Route it wherever you like; the path only has to match the
`jwksUrl` you register. With Express:

```ts
app.get("/.well-known/jwks.json", (_request, response) => {
  response.json(client.jwks());
});
```

```jsonc
{
  "keys": [
    {"kty": "RSA", "use": "sig", "alg": "RS256", "kid": "Z8uGuex…", "n": "vFj4…", "e": "AQAB"}
  ]
}
```

Public key material only — safe to serve publicly and to cache.
`client.federationDetails()` returns the `issuer`, `audience`, `jwksUrl`, and
`kid` in use.

### Register the issuer, once

```ts
import { IOCloudClient, SubjectTokenIssuer } from "@iocloud/sdk";

const tokenIssuer = new SubjectTokenIssuer({
  signingKey,
  issuer: "https://portal.acme.example",
  audience: "ai-ecosystem",
  tokenTtlSeconds: 300,
});

const client = new IOCloudClient({
  clientId: process.env.IOCLOUD_CLIENT_ID!,
  clientSecret: process.env.IOCLOUD_CLIENT_SECRET!,
  baseUrl: process.env.IOCLOUD_BASE_URL!,
  tokenIssuer,
});

const provider = await client.createIdentityProvider({
  applicationUuid: "11111111-1111-1111-1111-111111111111",
  name: "Acme Portal",
  issuer: tokenIssuer.issuer,
  allowedAudiences: [tokenIssuer.audience],
  jwksUrl: tokenIssuer.jwksUrl,
  requireEmailVerified: true,
  allowJitUsers: true,
  claimNames: tokenIssuer.claimNames,
});

// Give a tenant of that application the organisation id your tokens carry, or
// pass `externalId` to createTenant. Without it, logins fail with
// `invalid_target`; `externalId: null` clears it again.
await client.setTenantExternalId({
  tenantUuid: tenant.uuid,
  externalId: "acme-tenant-1",
});
```

The provider belongs to that application: a token it signs logs users into the
application's tenants only. An application may have several providers, and any
of them logs in any of its users, so they must all sign the same tenant and user
ids. A tenant's `externalId` is unique within its application; one another
tenant holds is refused with `TENANT_EXTERNAL_ID_TAKEN`.

Passing the issuer's own `jwksUrl` and `claimNames` is what keeps the registration
and the tokens you sign from drifting apart. `listIdentityProviders()` reads back
what IOCloud has stored. Pass `allowJitTenants: true` as well to create tenants at
their first login (see below).

### Log a user in

```ts
const session = await client.federatedLogin({
  subject: user.id,                  // stable and never reused
  externalTenantId: user.tenantId,
  email: user.email,
  name: user.name,
  emailVerified: true,
});

session.accessToken;   // opaque platform token — Authorization: Bearer …
session.userUuid;      // the IOCloud user this session belongs to
session.tenantUuid;    // and its tenant
session.expiresAt;     // no refresh tokens; sign and exchange again
```

One call signs the subject token and exchanges it. Use
`exchangeSubjectToken(token)` if the token was signed elsewhere.

`subject` is the identity key IOCloud stores — the user's `externalId` within
its tenant. It must be stable across logins and never reused for a different
person — an email change at your end must not change it.

A rejected exchange throws `IOCloudTokenExchangeError` with the RFC 6749 body:

| `error` | Cause |
| --- | --- |
| `invalid_grant` | Unknown or disabled issuer, signature does not verify against the published JWKS, wrong audience, token expired or replayed, missing claims, unverified email where required, a subject no user of the tenant has as its `externalId` with JIT provisioning off, a user that is not active — a pre-created user is `pending` until activated — or a malformed `tenant_profile` when the tenant must be created. |
| `invalid_target` | No tenant of the provider's application has the tenant claim as its `externalId` and this login may not create one, that tenant is not active, or, rarely, the tenant could not be created. |

`IOCloudFederationError` signals local misconfiguration — an unreadable PEM, a
missing token issuer — before any request is made.

### Federation with just-in-time provisioning off

With `allowJitUsers: false`, a login reaches only a user that already exists.
Create each one with the `sub` your tokens will carry as its `externalId`, then
activate it — a user created this way starts `pending`. The user endpoints are
tenant-scoped, so these calls take a tenant credential instead of the partner
token, and refresh the tenant token once on a `401`:

```ts
// The secret is returned once, at creation: persist it for later user calls.
const credential = await client.createTenantCredentials(tenant.uuid);
const tenantCredential = {
  tenantClientId: credential.clientId,
  tenantClientSecret: credential.clientSecret,
};

const user = await client.createUser({
  name: "Dana Okafor",
  email: "dana.okafor@acme.example",
  externalId: "acme-user-1001", // the `sub` your tokens carry
  ...tenantCredential,
});
user.status; // "pending"

await client.updateUserStatus({
  userUuid: user.uuid,
  status: "active",
  ...tenantCredential,
});

await client.federatedLogin({
  subject: "acme-user-1001",
  externalTenantId: "acme-tenant-1",
});
```

An `externalId` another user of the tenant already holds is refused with
`USER_EXTERNAL_ID_TAKEN`. `status: "deactivated"` stops a user's logins without
deleting it.

### Creating the tenant at its first login

With `allowJitTenants: true` — which requires `allowJitUsers: true`, or the
platform answers `422` — a login whose tenant claim names no tenant of the
provider's application creates that tenant from the `TenantProfile` you pass as
`tenant`: the name and optional contact email `createTenant` takes. The tenant
claim becomes its `externalId`, and the login's user is created just in time in
it, so the login needs `email`:

```ts
import type { TenantProfile } from "@iocloud/sdk";

const tenant: TenantProfile = {
  name: "Acme Ltd",
  contactEmail: "ops@acme.example",
};

const session = await client.federatedLogin({
  subject: "acme-user-1001",
  externalTenantId: "acme-tenant-1",
  email: "dana.okafor@acme.example",
  tenant,
});

if (session.tenantCreated && session.tenantUuid !== null) {
  // A new tenant has no plan, so it draws on your credits uncapped.
  await client.subscribeTenant({ tenantUuid: session.tenantUuid, planUuid: plan.uuid });
}
```

The profile is signed as the subject token's `tenant_profile` claim, under that
name whatever `claimNames` say, and `extraClaims` can neither set nor override
it. Leave out `contactEmail` and the claim carries the name alone. The profile
creates a tenant and never updates one: once the tenant exists it is ignored,
so passing it on every login is harmless. The SDK refuses an empty name, or an
empty contact email when one is given, with `IOCloudFederationError` before
signing; the platform judges the rest and answers a malformed profile with
`invalid_grant`.

`tenantCreated` is true only for the login that created the tenant. A platform
that predates just-in-time tenants sends neither session member, so
`tenantUuid` reads as `null` and `tenantCreated` as `false` — and a provider's
`allowJitTenants` as `false`.

### Rotating keys

Publish both keys while tokens signed by the old one are still in flight;
verifiers select by `kid`:

```ts
import { buildJwks } from "@iocloud/sdk";

buildJwks(currentKey, retiringKey);
```
