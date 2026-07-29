from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
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


@dataclass(frozen=True, slots=True)
class SubjectTokenClaimNames:
    """Which claim carries each identity value.

    Shared by the two halves of a federation setup: the issuer that writes the
    claims and the identity provider row that tells the platform where to read
    them. Pass one instance to both and they cannot drift apart.
    """

    user: str = "sub"
    tenant: str = "tenant_id"
    email: str = "email"
    name: str = "name"


@dataclass(frozen=True, slots=True)
class IdentityProvider:
    """The platform's trust anchor for one partner issuer.

    Every field is an instruction to the platform's token validator; a subject
    token overrides none of them.
    """

    uuid: UUID
    name: str
    issuer: str
    jwks_url: str
    allowed_audiences: tuple[str, ...]
    allowed_algorithms: tuple[str, ...]
    token_max_age_seconds: int
    require_email_verified: bool
    user_claim: str
    tenant_claim: str
    email_claim: str
    name_claim: str
    allow_jit_users: bool
    status: str
    created_at: datetime

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> "IdentityProvider":
        return cls(
            uuid=UUID(str(payload["uuid"])),
            name=str(payload["name"]),
            issuer=str(payload["issuer"]),
            jwks_url=str(payload["jwks_url"]),
            allowed_audiences=tuple(
                str(audience) for audience in payload["allowed_audiences"]
            ),
            allowed_algorithms=tuple(
                str(algorithm) for algorithm in payload["allowed_algorithms"]
            ),
            token_max_age_seconds=int(payload["token_max_age_seconds"]),
            require_email_verified=bool(payload["require_email_verified"]),
            user_claim=str(payload["user_claim"]),
            tenant_claim=str(payload["tenant_claim"]),
            email_claim=str(payload["email_claim"]),
            name_claim=str(payload["name_claim"]),
            allow_jit_users=bool(payload["allow_jit_users"]),
            status=str(payload["status"]),
            created_at=_datetime(payload["created_at"]),
        )

    @property
    def is_active(self) -> bool:
        return self.status == "active"

    @property
    def claim_names(self) -> SubjectTokenClaimNames:
        """The claim mapping the platform will read tokens from."""
        return SubjectTokenClaimNames(
            user=self.user_claim,
            tenant=self.tenant_claim,
            email=self.email_claim,
            name=self.name_claim,
        )


@dataclass(frozen=True, slots=True)
class FederatedSession:
    """The platform session a subject token was exchanged for.

    ``access_token`` is opaque — not a JWT — and is presented as a bearer
    credential on the Gateway job APIs. There are no refresh tokens: when it
    expires, the partner signs a new subject token and exchanges again.
    """

    access_token: str
    token_type: str
    issued_token_type: str
    expires_in: int
    expires_at: datetime
    user_uuid: UUID
    name: str
    email: str

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> "FederatedSession":
        expires_in = int(payload["expires_in"])
        return cls(
            access_token=str(payload["access_token"]),
            token_type=str(payload["token_type"]),
            issued_token_type=str(payload["issued_token_type"]),
            expires_in=expires_in,
            # The wire format is a relative lifetime; an absolute instant is
            # what callers need to store alongside a persisted session.
            expires_at=datetime.now(timezone.utc) + timedelta(seconds=expires_in),
            user_uuid=UUID(str(payload["user_uuid"])),
            name=str(payload["name"]),
            email=str(payload["email"]),
        )
