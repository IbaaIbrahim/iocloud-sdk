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
  slug: "acme",
  contactEmail: "ops@acme.example",
});
```

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
  name: "Acme Portal",
  issuer: tokenIssuer.issuer,
  allowedAudiences: [tokenIssuer.audience],
  jwksUrl: tokenIssuer.jwksUrl,
  requireEmailVerified: true,
  allowJitUsers: true,
  claimNames: tokenIssuer.claimNames,
});

// Point one of your organisation ids at an IOCloud tenant. Without this, logins
// fail with `invalid_target`.
await client.mapExternalTenant({
  providerUuid: provider.uuid,
  tenantUuid: tenant.uuid,
  externalTenantId: "acme-tenant-1",
});
```

Passing the issuer's own `jwksUrl` and `claimNames` is what keeps the registration
and the tokens you sign from drifting apart. `listIdentityProviders()` reads back
what IOCloud has stored.

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
session.expiresAt;     // no refresh tokens; sign and exchange again
```

One call signs the subject token and exchanges it. Use
`exchangeSubjectToken(token)` if the token was signed elsewhere.

`subject` is the identity key IOCloud stores. It must be stable across logins and
never reused for a different person — an email change at your end must not
change it.

A rejected exchange throws `IOCloudTokenExchangeError` with the RFC 6749 body:

| `error` | Cause |
| --- | --- |
| `invalid_grant` | Unknown or disabled issuer, signature does not verify against the published JWKS, wrong audience, token expired or replayed, missing claims, unverified email where required, or an unknown subject with JIT provisioning off. |
| `invalid_target` | The tenant claim is not mapped to an IOCloud tenant, or the mapped tenant is not active. |

`IOCloudFederationError` signals local misconfiguration — an unreadable PEM, a
missing token issuer — before any request is made.

### Rotating keys

Publish both keys while tokens signed by the old one are still in flight;
verifiers select by `kid`:

```ts
import { buildJwks } from "@iocloud/sdk";

buildJwks(currentKey, retiringKey);
```
