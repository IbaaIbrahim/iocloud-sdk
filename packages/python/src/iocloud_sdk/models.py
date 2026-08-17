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


@dataclass(frozen=True, slots=True)
class TenantPlan:
    """A plan the partner offers its own tenants.

    ``credits`` is the tenant's included balance and ``user_credits_cap`` the
    per-user share of it; both become ``quota.child_caps`` rows when a
    subscription to this plan is activated.
    """

    uuid: UUID
    name: str
    monthly_price_cents: int
    yearly_price_cents: int
    tpm: int
    rpm: int
    credits: int
    user_credits_cap: int
    user_tpm: int
    user_rpm: int

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> "TenantPlan":
        return cls(
            uuid=UUID(str(payload["uuid"])),
            name=str(payload["name"]),
            monthly_price_cents=int(payload["monthly_price_cents"]),
            yearly_price_cents=int(payload["yearly_price_cents"]),
            tpm=int(payload["tpm"]),
            rpm=int(payload["rpm"]),
            credits=int(payload["credits"]),
            user_credits_cap=int(payload["user_credits_cap"]),
            user_tpm=int(payload["user_tpm"]),
            user_rpm=int(payload["user_rpm"]),
        )


@dataclass(frozen=True, slots=True)
class PlanSubscription:
    """A subscription linking a subscriber to a plan for a billing period.

    ``status`` is ``pending_payment`` until activated, then ``paid``.
    ``subscribed_from`` / ``subscribed_to`` are None while pending — the
    window is established at activation and is what makes the plan (and the
    balance it provisions) active.
    """

    uuid: UUID
    status: str
    plan_type: str
    billing_cycle: str
    subscribed_from: datetime | None
    subscribed_to: datetime | None
    payment_transaction_uuid: UUID | None
    created_at: datetime

    @property
    def is_active(self) -> bool:
        return self.status == "paid"

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> "PlanSubscription":
        subscribed_from = payload.get("subscribed_from")
        subscribed_to = payload.get("subscribed_to")
        payment_uuid = payload.get("payment_transaction_uuid")
        return cls(
            uuid=UUID(str(payload["uuid"])),
            status=str(payload["status"]),
            plan_type=str(payload["plan_type"]),
            billing_cycle=str(payload["billing_cycle"]),
            subscribed_from=(
                _datetime(subscribed_from) if subscribed_from else None
            ),
            subscribed_to=_datetime(subscribed_to) if subscribed_to else None,
            payment_transaction_uuid=(
                UUID(str(payment_uuid)) if payment_uuid else None
            ),
            created_at=_datetime(payload["created_at"]),
        )


@dataclass(frozen=True, slots=True)
class ProvisionedBalance:
    """The balance rows an activation created.

    A tenant subscription provisions ``caps_created`` (the tenant's own cap
    plus one per active user) and never a pool: tenants draw on their
    partner's credit pool, bounded by those caps.
    """

    pool_created: bool
    pool_credits: int
    caps_created: tuple[dict[str, Any], ...]

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> "ProvisionedBalance":
        return cls(
            pool_created=bool(payload.get("pool_created", False)),
            pool_credits=int(payload.get("pool_credits", 0)),
            caps_created=tuple(payload.get("caps_created") or ()),
        )


@dataclass(frozen=True, slots=True)
class TenantSubscription:
    """A subscription plus whatever its activation provisioned.

    ``provisioned`` is None when nothing was provisioned by this call — the
    subscription is still pending payment, or an already-active subscription
    was activated again (activation is idempotent).
    """

    subscription: PlanSubscription
    provisioned: ProvisionedBalance | None

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> "TenantSubscription":
        provisioning = payload.get("provisioning")
        return cls(
            subscription=PlanSubscription.from_payload(payload["subscription"]),
            provisioned=(
                ProvisionedBalance.from_payload(provisioning)
                if isinstance(provisioning, dict)
                else None
            ),
        )


@dataclass(frozen=True, slots=True)
class TopupPackagePlan:
    """One plan a top-up package is offered to."""

    plan_type: str
    plan_uuid: UUID
    plan_name: str

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> "TopupPackagePlan":
        return cls(
            plan_type=str(payload["plan_type"]),
            plan_uuid=UUID(str(payload["plan_uuid"])),
            plan_name=str(payload["plan_name"]),
        )


@dataclass(frozen=True, slots=True)
class TopupPackage:
    """A credit bundle the partner sells to its own tenants.

    ``plans`` is what makes the offer differ per plan: empty means every
    tenant sees the package, and one entry confines it to that tenant plan.
    ``validity_days`` is None when the purchased credits never expire.
    """

    uuid: UUID
    name: str
    credits: int
    price_cents: int
    validity_days: int | None
    status: str
    audience: str | None
    plans: tuple[TopupPackagePlan, ...]

    @property
    def is_active(self) -> bool:
        return self.status == "active"

    @property
    def is_offered_to_every_plan(self) -> bool:
        return not self.plans

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> "TopupPackage":
        validity_days = payload.get("validity_days")
        audience = payload.get("audience")
        return cls(
            uuid=UUID(str(payload["uuid"])),
            name=str(payload["name"]),
            credits=int(payload["credits"]),
            price_cents=int(payload["price_cents"]),
            validity_days=int(validity_days) if validity_days is not None else None,
            status=str(payload["status"]),
            audience=str(audience) if audience else None,
            plans=tuple(
                TopupPackagePlan.from_payload(item)
                for item in payload.get("plans") or ()
            ),
        )


@dataclass(frozen=True, slots=True)
class TopupPurchase:
    """One tenant's purchase of a top-up package.

    ``credits`` is snapshotted at purchase time, so editing the package later
    never changes what an existing purchase granted. ``valid_to`` is None when
    the credits never expire.
    """

    uuid: UUID
    tenant_uuid: UUID
    tenant_name: str
    package_uuid: UUID | None
    package_name: str | None
    credits: int
    status: str
    valid_from: datetime | None
    valid_to: datetime | None
    created_at: datetime

    @property
    def is_active(self) -> bool:
        return self.status == "active"

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> "TopupPurchase":
        package_uuid = payload.get("package_uuid")
        package_name = payload.get("package_name")
        valid_from = payload.get("valid_from")
        valid_to = payload.get("valid_to")
        return cls(
            uuid=UUID(str(payload["uuid"])),
            tenant_uuid=UUID(str(payload["tenant_uuid"])),
            tenant_name=str(payload["tenant_name"]),
            package_uuid=UUID(str(package_uuid)) if package_uuid else None,
            package_name=str(package_name) if package_name else None,
            credits=int(payload["credits"]),
            status=str(payload["status"]),
            valid_from=_datetime(valid_from) if valid_from else None,
            valid_to=_datetime(valid_to) if valid_to else None,
            created_at=_datetime(payload["created_at"]),
        )


@dataclass(frozen=True, slots=True)
class ProvisionedTopup:
    """The credit pool an activated top-up created.

    Unlike a tenant plan — which provisions caps against the partner's pool —
    a tenant top-up creates a pool the tenant owns outright, spent before the
    partner's own credits.
    """

    pool_created: bool
    pool_credits: int

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> "ProvisionedTopup":
        return cls(
            pool_created=bool(payload.get("pool_created", False)),
            pool_credits=int(payload.get("pool_credits", 0)),
        )


@dataclass(frozen=True, slots=True)
class TenantTopup:
    """A tenant's top-up plus whatever its activation provisioned.

    ``provisioned`` is None when nothing was provisioned by this call — the
    purchase is still pending, or an already-active one was activated again
    (activation is idempotent).
    """

    purchase: TopupPurchase
    provisioned: ProvisionedTopup | None

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> "TenantTopup":
        provisioning = payload.get("provisioning")
        return cls(
            purchase=TopupPurchase.from_payload(payload["purchase"]),
            provisioned=(
                ProvisionedTopup.from_payload(provisioning)
                if isinstance(provisioning, dict)
                else None
            ),
        )
