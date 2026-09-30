# IOCloud Python SDK

> **Not on PyPI yet.** `iocloud-sdk` has never been published — no `python-v*`
> tag has been pushed — so the command below will not resolve. Until the first
> release, install from a checkout with the editable command further down. See
> [Releases](../../README.md#releases).

Install the published dependency:

```bash
pip install iocloud-sdk
```

During local development:

```bash
pip install -e ./packages/python
```

## Partner usage

```python
from uuid import UUID

from iocloud_sdk import IOCloudClient

with IOCloudClient(
    client_id="partner-client-id",
    client_secret="partner-client-secret",
    base_url="https://api.example.com",
) as client:
    token = client.issue_partner_token()

    tenant = client.create_tenant(
        application_uuid=UUID("11111111-1111-1111-1111-111111111111"),
        name="Acme workspace",
        contact_email="ops@acme.example",  # optional
        external_id="acme-external-id",  # optional: see below
    )
```

The platform generates the tenant's slug from its name and returns it as
`tenant.slug` (`acme-workspace-5d3c1a7e`, say), so `create_tenant` takes
none; passing `slug=` raises `TypeError`. `tenant.contact_email` is `None`
for a tenant created without one.

Partner tokens are issued lazily and cached until shortly before expiration.
An authenticated request that returns `401` triggers one token refresh and retry.

## A tenant's external id

`external_id` is your own id for the organisation — the value your subject
tokens carry in the tenant claim — and is what a federated login resolves to,
within the identity provider's application. Set it at creation, as above, or
later; `None` clears it, which stops federated logins into the tenant:

```python
tenant = client.set_tenant_external_id(
    tenant_uuid=tenant.uuid,
    external_id="customer-42",
)

client.set_tenant_external_id(tenant_uuid=tenant.uuid, external_id=None)
```

The id is unique within the application: one another tenant already holds is
refused with an `IOCloudAPIError` whose `code` is `TENANT_EXTERNAL_ID_TAKEN`.

The SDK raises `IOCloudAuthenticationError` for rejected bearer/client
credentials and `IOCloudAPIError` for all other non-success API responses.

## Updating a user's persona

After onboarding, push a user's resolved persona onto the mapped tenant's
federated user. The tenant-scoped persona endpoint needs a tenant token, so the
partner first provisions a tenant credential (once), then the SDK issues and
caches a tenant token per `client_id`:

```python
credential = client.create_tenant_credentials(tenant_uuid=tenant.uuid)

client.set_user_persona(
    user_uuid="the-ai-ecosystem-user-uuid",
    persona="DRIVER AND/OR GUARDIAN",
    tenant_client_id=credential.client_id,
    tenant_client_secret=credential.client_secret,
)
```

`client_secret` is returned only once, at creation — persist it to reuse for
later persona updates. `set_user_persona` refreshes the tenant token once and
retries on a `401`.

## Federation: signing your users into IOCloud

Federating means your application acts as a small OIDC issuer — it signs a
short-lived JWT for each user who logs in, and publishes the matching public key
so IOCloud can verify it. The SDK owns the keypair, the JWKS document, the token,
and the RFC 8693 exchange.

Signing needs the optional extra:

```bash
pip install "iocloud-sdk[federation]"
```

### Generate and persist a keypair

```python
from iocloud_sdk.federation import FederationSigningKey

signing_key = FederationSigningKey.generate()

# Store this as a secret; reload it on boot so the published kid stays stable.
open("federation-private-key.pem", "w").write(signing_key.private_key_pem)

signing_key.kid       # RFC 7638 thumbprint, derived from the key itself
signing_key.jwks()    # serve this at <issuer>/.well-known/jwks.json
```

The key id is derived from the key, not random, so a reloaded key keeps its `kid`
and a published JWKS always matches the tokens you sign.

### Publish the JWKS from your own route

`signing_key.jwks()` — or `client.jwks()` once a token issuer is configured —
returns the document. Route it wherever you like; the path only has to match the
`jwks_url` you register. With FastAPI:

```python
@app.get("/.well-known/jwks.json")
def jwks() -> dict:
    return client.jwks()
```

```jsonc
{
  "keys": [
    {"kty": "RSA", "use": "sig", "alg": "RS256", "kid": "Z8uGuex…", "n": "vFj4…", "e": "AQAB"}
  ]
}
```

Public key material only — safe to serve publicly and to cache.
`client.federation_details()` returns the `issuer`, `audience`, `jwks_url`, and
`kid` in use.

### Register the issuer, once

```python
from iocloud_sdk import IOCloudClient
from iocloud_sdk.federation import SubjectTokenIssuer

token_issuer = SubjectTokenIssuer(
    signing_key=signing_key,
    issuer="https://portal.acme.example",
    audience="ai-ecosystem",
    token_ttl_seconds=300,
)

client = IOCloudClient(
    client_id="partner-client-id",
    client_secret="partner-client-secret",
    base_url="https://api.example.com",
    token_issuer=token_issuer,
)

provider = client.create_identity_provider(
    application_uuid=UUID("11111111-1111-1111-1111-111111111111"),
    name="Acme Portal",
    issuer=token_issuer.issuer,
    allowed_audiences=[token_issuer.audience],
    jwks_url=token_issuer.jwks_url,
    require_email_verified=True,
    allow_jit_users=True,
    claim_names=token_issuer.claim_names,
)

# Give a tenant of that application the organisation id your tokens carry.
# Without it, logins fail with `invalid_target`.
client.set_tenant_external_id(tenant_uuid=tenant.uuid, external_id="acme-tenant-1")
```

The provider belongs to that application: a token it signs logs users into the
application's tenants only. An application may have several providers, and any
of them logs in any of its users, so they must all sign the same tenant and user
ids.

Passing the issuer's own `jwks_url` and `claim_names` is what keeps the
registration and the tokens you sign from drifting apart.
`list_identity_providers()` reads back what IOCloud has stored. Pass
`allow_jit_tenants=True` as well to create tenants at their first login
(see below).

### Log a user in

```python
session = client.federated_login(
    subject=user.id,                 # stable and never reused
    external_tenant_id=user.tenant_id,
    email=user.email,
    name=user.name,
    email_verified=True,
)

session.access_token   # opaque platform token — Authorization: Bearer …
session.user_uuid      # the IOCloud user this session belongs to
session.tenant_uuid    # and its tenant
session.expires_at     # no refresh tokens; sign and exchange again
```

One call signs the subject token and exchanges it. Use
`exchange_subject_token(subject_token=...)` if the token was signed elsewhere.

`subject` is the identity key IOCloud stores — the user's `external_id` within
its tenant. It must be stable across logins and never reused for a different
person — an email change at your end must not change it.

A rejected exchange raises `IOCloudTokenExchangeError` with the RFC 6749 body:

| `error` | Cause |
| --- | --- |
| `invalid_grant` | Unknown or disabled issuer, signature does not verify against the published JWKS, wrong audience, token expired or replayed, missing claims, unverified email where required, a subject no user of the tenant has as its `external_id` with JIT provisioning off, a user that is not active — a pre-created user is `pending` until activated — or a malformed `tenant_profile` when the tenant must be created. |
| `invalid_target` | No tenant of the provider's application has the tenant claim as its `external_id` and this login may not create one, that tenant is not active, or, rarely, the tenant could not be created. |

`IOCloudFederationError` signals local misconfiguration — no signing key, an
unreadable PEM — before any request is made.

### Federation with just-in-time provisioning off

With `allow_jit_users=False`, a login reaches only a user that already exists.
Create each one with the `sub` your tokens will carry as its `external_id`, then
activate it — a user created this way starts `pending`. The user endpoints are
tenant-scoped, so these calls take a tenant credential instead of the partner
token, and refresh the tenant token once on a `401`:

```python
# The secret is returned once, at creation: persist it for later user calls.
credential = client.create_tenant_credentials(tenant_uuid=tenant.uuid)

user = client.create_user(
    name="Dana Okafor",
    email="dana.okafor@acme.example",
    external_id="acme-user-1001",       # the `sub` your tokens carry
    tenant_client_id=credential.client_id,
    tenant_client_secret=credential.client_secret,
)
user.status                             # "pending"

client.update_user_status(
    user_uuid=user.uuid,
    status="active",
    tenant_client_id=credential.client_id,
    tenant_client_secret=credential.client_secret,
)

client.federated_login(subject="acme-user-1001", external_tenant_id="acme-tenant-1")
```

An `external_id` another user of the tenant already holds is refused with
`USER_EXTERNAL_ID_TAKEN`. `update_user_status(..., status="deactivated")` stops
a user's logins without deleting it.

### Creating the tenant at its first login

With `allow_jit_tenants=True` — which requires `allow_jit_users=True`, or the
platform answers `422` — a login whose tenant claim names no tenant of the
provider's application creates that tenant from the `TenantProfile` you pass as
`tenant`: the name and optional contact email `create_tenant` takes. The tenant
claim becomes its `external_id`, and the login's user is created just in time
in it, so the login needs `email`:

```python
from iocloud_sdk import TenantProfile

session = client.federated_login(
    subject="acme-user-1001",
    external_tenant_id="acme-tenant-1",
    email="dana.okafor@acme.example",
    tenant=TenantProfile(name="Acme Ltd", contact_email="ops@acme.example"),
)

if session.tenant_created:
    # A new tenant has no plan, so it draws on your credits uncapped.
    client.subscribe_tenant(tenant_uuid=session.tenant_uuid, plan_uuid=plan.uuid)
```

The profile is signed as the subject token's `tenant_profile` claim, under that
name whatever `claim_names` say, and `extra_claims` can neither set nor
override it. Leave out `contact_email` and the claim carries the name alone.
The profile creates a tenant and never updates one: once the tenant exists it
is ignored, so passing it on every login is harmless. The SDK refuses an empty
name, or an empty contact email when one is given, with
`IOCloudFederationError` before signing; the platform judges the rest and
answers a malformed profile with `invalid_grant`.

`tenant_created` is true only for the login that created the tenant. A
platform that predates just-in-time tenants sends neither session member, so
`tenant_uuid` reads as `None` and `tenant_created` as `False` — and a provider's
`allow_jit_tenants` as `False`.

### Rotating keys

Publish both keys while tokens signed by the old one are still in flight;
verifiers select by `kid`:

```python
from iocloud_sdk.federation import build_jwks

build_jwks(current_key, retiring_key)
```
