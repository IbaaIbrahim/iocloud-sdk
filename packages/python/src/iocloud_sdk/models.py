from dataclasses import dataclass
from datetime import datetime
from typing import Any
from uuid import UUID


def _datetime(value: str | datetime) -> datetime:
    if isinstance(value, datetime):
        return value
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


@dataclass(frozen=True, slots=True)
class PartnerToken:
    access_token: str
    token_type: str
    expires_at: datetime

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> "PartnerToken":
        return cls(
            access_token=str(payload["access_token"]),
            token_type=str(payload["token_type"]),
            expires_at=_datetime(payload["expires_at"]),
        )


@dataclass(frozen=True, slots=True)
class TenantToken:
    access_token: str
    token_type: str
    expires_at: datetime

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> "TenantToken":
        return cls(
            access_token=str(payload["access_token"]),
            token_type=str(payload["token_type"]),
            expires_at=_datetime(payload["expires_at"]),
        )


@dataclass(frozen=True, slots=True)
class TenantCredential:
    credential_uuid: UUID
    tenant_uuid: UUID
    client_id: str
    client_secret: str

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> "TenantCredential":
        return cls(
            credential_uuid=UUID(str(payload["credential_uuid"])),
            tenant_uuid=UUID(str(payload["tenant_uuid"])),
            client_id=str(payload["client_id"]),
            client_secret=str(payload["client_secret"]),
        )


@dataclass(frozen=True, slots=True)
class Tenant:
    uuid: UUID
    application_uuid: UUID
    name: str
    slug: str
    contact_email: str
    status: str
    created_at: datetime

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> "Tenant":
        return cls(
            uuid=UUID(str(payload["uuid"])),
            application_uuid=UUID(str(payload["application_uuid"])),
            name=str(payload["name"]),
            slug=str(payload["slug"]),
            contact_email=str(payload["contact_email"]),
            status=str(payload["status"]),
            created_at=_datetime(payload["created_at"]),
        )


@dataclass(frozen=True, slots=True)
class ExternalTenantMapping:
    identity_provider_uuid: UUID
    tenant_uuid: UUID
    external_tenant_id: str
    created_at: datetime

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> "ExternalTenantMapping":
        return cls(
            identity_provider_uuid=UUID(str(payload["identity_provider_uuid"])),
            tenant_uuid=UUID(str(payload["tenant_uuid"])),
            external_tenant_id=str(payload["external_tenant_id"]),
            created_at=_datetime(payload["created_at"]),
        )
