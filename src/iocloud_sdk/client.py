from datetime import datetime, timedelta, timezone
from typing import Any
from uuid import UUID

import httpx

from .exceptions import IOCloudAPIError, IOCloudAuthenticationError
from .models import ExternalTenantMapping, PartnerToken, Tenant


class IOCloudClient:
    """Synchronous client authenticated by partner client credentials."""

    def __init__(
        self,
        *,
        client_id: str,
        client_secret: str,
        base_url: str,
        timeout: float = 30.0,
        http_client: httpx.Client | None = None,
    ) -> None:
        if not client_id.strip():
            raise ValueError("client_id must not be empty")
        if not client_secret.strip():
            raise ValueError("client_secret must not be empty")
        if not base_url.strip():
            raise ValueError("base_url must not be empty")

        self._client_id = client_id
        self._client_secret = client_secret
        self._base_url = base_url.rstrip("/")
        self._http = http_client or httpx.Client(timeout=timeout)
        self._owns_http_client = http_client is None
        self._partner_token: PartnerToken | None = None

    def __enter__(self) -> "IOCloudClient":
        return self

    def __exit__(self, *_: object) -> None:
        self.close()

    def __repr__(self) -> str:
        return (
            f"IOCloudClient(client_id={self._client_id!r}, "
            f"client_secret='***', base_url={self._base_url!r})"
        )

    def close(self) -> None:
        if self._owns_http_client:
            self._http.close()

    def issue_partner_token(self, *, force_refresh: bool = False) -> PartnerToken:
        """Exchange client credentials for a partner token and cache it."""
        if not force_refresh and self._token_is_fresh(self._partner_token):
            assert self._partner_token is not None
            return self._partner_token

        data = self._request(
            "POST",
            "/v1/partner/auth/token",
            json={
                "client_id": self._client_id,
                "client_secret": self._client_secret,
            },
        )
        self._partner_token = PartnerToken.from_payload(data["token"])
        return self._partner_token

    def create_tenant(
        self,
        *,
        application_uuid: UUID | str,
        name: str,
        slug: str,
        contact_email: str,
    ) -> Tenant:
        """Create a tenant (space) inside an application owned by the partner."""
        data = self._partner_request(
            "POST",
            f"/v1/partner/applications/{application_uuid}/tenants",
            json={
                "name": name,
                "slug": slug,
                "contact_email": contact_email,
            },
        )
        return Tenant.from_payload(data["tenant"])

    def map_external_tenant(
        self,
        *,
        provider_uuid: UUID | str,
        tenant_uuid: UUID | str,
        external_tenant_id: str,
        access_token: str | None = None,
    ) -> ExternalTenantMapping:
        """Map an external tenant id to an internal tenant.

        By default the cached partner token is used. ``access_token`` may be a
        tenant token instead; the API then permits mapping only that token's own
        tenant.
        """
        path = f"/v1/partner/federation/providers/{provider_uuid}/tenants"
        body = {
            "tenant_uuid": str(tenant_uuid),
            "external_tenant_id": external_tenant_id,
        }
        if access_token is not None:
            data = self._request(
                "POST",
                path,
                json=body,
                headers={"Authorization": f"Bearer {access_token}"},
            )
        else:
            data = self._partner_request("POST", path, json=body)
        return ExternalTenantMapping.from_payload(data["mapping"])

    def _partner_request(
        self,
        method: str,
        path: str,
        *,
        json: dict[str, Any],
    ) -> dict[str, Any]:
        token = self.issue_partner_token()
        try:
            return self._request(
                method,
                path,
                json=json,
                headers={"Authorization": f"Bearer {token.access_token}"},
            )
        except IOCloudAuthenticationError:
            token = self.issue_partner_token(force_refresh=True)
            return self._request(
                method,
                path,
                json=json,
                headers={"Authorization": f"Bearer {token.access_token}"},
            )

    def _request(
        self,
        method: str,
        path: str,
        *,
        json: dict[str, Any],
        headers: dict[str, str] | None = None,
    ) -> dict[str, Any]:
        response = self._http.request(
            method,
            f"{self._base_url}{path}",
            json=json,
            headers=headers,
        )
        try:
            body = response.json()
        except ValueError:
            body = {}

        if response.is_success:
            return body.get("data", body)

        error_type = (
            IOCloudAuthenticationError
            if response.status_code == 401
            else IOCloudAPIError
        )
        raise error_type(
            status_code=response.status_code,
            code=str(body.get("code", "IOCLOUD_API_ERROR")),
            message=str(body.get("message", response.text or "IOCloud API request failed.")),
        )

    @staticmethod
    def _token_is_fresh(token: PartnerToken | None) -> bool:
        if token is None:
            return False
        expires_at = token.expires_at
        if expires_at.tzinfo is None:
            expires_at = expires_at.replace(tzinfo=timezone.utc)
        return expires_at > datetime.now(timezone.utc) + timedelta(seconds=30)
