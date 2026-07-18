# IOCloud Python SDK

Install the published dependency:

```bash
pip install iocloud-sdk
```

During local development:

```bash
pip install -e ./iocloud-sdk
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
