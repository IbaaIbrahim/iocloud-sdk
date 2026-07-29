# IOCloud Python SDK

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
        slug="acme",
        contact_email="ops@acme.example",
    )

    mapping = client.map_external_tenant(
        provider_uuid=UUID("22222222-2222-2222-2222-222222222222"),
        tenant_uuid=tenant.uuid,
        external_tenant_id="acme-external-id",
    )
```

Partner tokens are issued lazily and cached until shortly before expiration.
An authenticated request that returns `401` triggers one token refresh and retry.

## Mapping with a tenant token

The mapping endpoint accepts either a partner token or a tenant token. A tenant
token can map only its own internal tenant:

```python
mapping = client.map_external_tenant(
    provider_uuid=provider_uuid,
    tenant_uuid=tenant_uuid,
    external_tenant_id="customer-42",
    access_token=tenant_access_token,
)
```

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
    name="Acme Portal",
    issuer=token_issuer.issuer,
    allowed_audiences=[token_issuer.audience],
    jwks_url=token_issuer.jwks_url,
    require_email_verified=True,
    allow_jit_users=True,
    claim_names=token_issuer.claim_names,
)

# Point one of your organisation ids at an IOCloud tenant. Without this, logins
# fail with `invalid_target`.
client.map_external_tenant(
    provider_uuid=provider.uuid,
    tenant_uuid=tenant.uuid,
    external_tenant_id="acme-tenant-1",
)
```

Passing the issuer's own `jwks_url` and `claim_names` is what keeps the
registration and the tokens you sign from drifting apart.
`list_identity_providers()` reads back what IOCloud has stored.

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
session.expires_at     # no refresh tokens; sign and exchange again
```

One call signs the subject token and exchanges it. Use
`exchange_subject_token(subject_token=...)` if the token was signed elsewhere.

`subject` is the identity key IOCloud stores. It must be stable across logins and
never reused for a different person — an email change at your end must not
change it.

A rejected exchange raises `IOCloudTokenExchangeError` with the RFC 6749 body:

| `error` | Cause |
| --- | --- |
| `invalid_grant` | Unknown or disabled issuer, signature does not verify against the published JWKS, wrong audience, token expired or replayed, missing claims, unverified email where required, or an unknown subject with JIT provisioning off. |
| `invalid_target` | The tenant claim is not mapped to an IOCloud tenant, or the mapped tenant is not active. |

`IOCloudFederationError` signals local misconfiguration — no signing key, an
unreadable PEM — before any request is made.

### Rotating keys

Publish both keys while tokens signed by the old one are still in flight;
verifiers select by `kid`:

```python
from iocloud_sdk.federation import build_jwks

build_jwks(current_key, retiring_key)
```
