from datetime import datetime, timedelta, timezone
from typing import TYPE_CHECKING, Any, Optional, Sequence
from uuid import UUID

import httpx

from .exceptions import (
    IOCloudAPIError,
    IOCloudAuthenticationError,
    IOCloudFederationError,
    IOCloudTokenExchangeError,
)
from .models import (
    FederatedSession,
    IdentityProvider,
    PartnerToken,
    PlanSubscription,
    SubjectTokenClaimNames,
    Tenant,
    TenantCredential,
    TenantPlan,
    TenantSubscription,
    TenantToken,
    TenantTopup,
    TopupPackage,
    TopupPurchase,
    User,
)

if TYPE_CHECKING:  # Signing needs the optional federation extra; keep it lazy.
    from .federation import SubjectTokenIssuer

# RFC 8693 / RFC 7519 URNs that identify the exchange grant and token types.
TOKEN_EXCHANGE_GRANT_TYPE = "urn:ietf:params:oauth:grant-type:token-exchange"
JWT_TOKEN_TYPE = "urn:ietf:params:oauth:token-type:jwt"

_DEFAULT_ALLOWED_ALGORITHMS = ("RS256",)
_DEFAULT_TOKEN_MAX_AGE_SECONDS = 900


class IOCloudClient:
    """Synchronous client authenticated by partner client credentials.

    ``token_issuer`` is only needed for :meth:`federated_login`; supply it and
    a partner's login controller becomes a single call.
    """

    def __init__(
        self,
        *,
        client_id: str,
        client_secret: str,
        base_url: str,
        timeout: float = 30.0,
        http_client: httpx.Client | None = None,
        token_issuer: Optional["SubjectTokenIssuer"] = None,
    ) -> None:
        # The partner credentials are checked when they are first used rather than
        # here: publishing a JWKS and exchanging a subject token need no partner
        # token, so federation works before those credentials are configured.
        if not base_url.strip():
            raise ValueError("base_url must not be empty")

        self._client_id = client_id
        self._client_secret = client_secret
        self._base_url = base_url.rstrip("/")
        self._http = http_client or httpx.Client(timeout=timeout)
        self._owns_http_client = http_client is None
        self._token_issuer = token_issuer
        self._partner_token: PartnerToken | None = None
        self._tenant_tokens: dict[str, TenantToken] = {}

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
        if not self._client_id.strip() or not self._client_secret.strip():
            raise ValueError(
                "This call needs partner client credentials. Construct the client"
                " with client_id and client_secret."
            )

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
        external_id: str | None = None,
    ) -> Tenant:
        """Create a tenant (space) inside an application owned by the partner.

        ``external_id`` is your own id for the organisation — the value your
        subject tokens carry in the tenant claim — and must be unique within
        the application. Omit it for a tenant nobody logs into yet and set it
        later with :meth:`set_tenant_external_id`.
        """
        payload: dict[str, Any] = {
            "name": name,
            "slug": slug,
            "contact_email": contact_email,
        }
        if external_id is not None:
            payload["external_id"] = external_id
        data = self._partner_request(
            "POST",
            f"/v1/partner/applications/{application_uuid}/tenants",
            json=payload,
        )
        return Tenant.from_payload(data["tenant"])

    def set_tenant_external_id(
        self,
        *,
        tenant_uuid: UUID | str,
        external_id: str | None,
    ) -> Tenant:
        """Set, change or clear the id your subject tokens carry for a tenant.

        A federated login resolves its tenant claim to the tenant of the
        identity provider's application whose ``external_id`` equals it, so
        this is what makes a tenant reachable. The id is unique within the
        application, and every provider of that application must sign the
        same one. ``external_id=None`` clears it, which stops federated logins
        into the tenant.
        """
        data = self._partner_request(
            "PATCH",
            f"/v1/partner/tenants/{tenant_uuid}/external-id",
            json={"external_id": external_id},
        )
        return Tenant.from_payload(data["tenant"])

    # ------------------------------------------------------------------
    # Tenant plans and subscriptions
    # ------------------------------------------------------------------

    def list_tenant_plans(
        self, *, page: int = 1, limit: int = 25
    ) -> list[TenantPlan]:
        """List the tenant plans this partner offers."""
        data = self._partner_request(
            "GET",
            "/v1/partner/plans/tenant",
            params={"page": page, "limit": limit},
        )
        return [TenantPlan.from_payload(item) for item in data.get("list", [])]

    def subscribe_tenant(
        self,
        *,
        tenant_uuid: UUID | str,
        plan_uuid: UUID | str,
        billing_cycle: str = "monthly",
        activate_now: bool = True,
        reference: str | None = None,
    ) -> TenantSubscription:
        """Put one of the partner's tenants on one of the partner's plans.

        Tenants are the partner's clients and never pay this platform, so the
        partner owns both halves of the flow. With ``activate_now`` (the
        default) the subscription is created AND activated in one call: the
        billing window opens and the tenant's plan balance is provisioned as
        ``quota.child_caps`` rows — the tenant's own cap from the plan's
        ``credits`` and one per active user from ``user_credits_cap``.

        Pass ``activate_now=False`` to record the intent first (status
        ``pending_payment``) and call :meth:`activate_tenant_subscription`
        once the client has actually paid. ``reference`` is free text kept in
        the platform's audit trail (invoice number, "included in retainer").
        """
        data = self._partner_request(
            "POST",
            "/v1/partner/plans/tenant/subscriptions",
            json={
                "tenant_uuid": str(tenant_uuid),
                "plan_uuid": str(plan_uuid),
                "billing_cycle": billing_cycle,
                "activate_now": activate_now,
                "reference": reference,
            },
        )
        return TenantSubscription.from_payload(data)

    def activate_tenant_subscription(
        self,
        *,
        subscription_uuid: UUID | str,
        reference: str | None = None,
    ) -> TenantSubscription:
        """Activate a pending tenant subscription and provision its balance.

        Call this after collecting payment from the tenant in your own
        billing system. Idempotent: activating an already-active subscription
        returns it unchanged with ``provisioned`` set to None.
        """
        data = self._partner_request(
            "POST",
            f"/v1/partner/plans/tenant/subscriptions/{subscription_uuid}/activate",
            json={"reference": reference},
        )
        return TenantSubscription.from_payload(data)

    def list_tenant_subscriptions(self) -> list[PlanSubscription]:
        """List every subscription held by this partner's tenants."""
        data = self._partner_request(
            "GET", "/v1/partner/plans/tenant/subscriptions"
        )
        return [
            PlanSubscription.from_payload(item)
            for item in data.get("subscriptions", [])
        ]

    # ------------------------------------------------------------------
    # Top-up packages: the credit bundles you sell your tenants
    # ------------------------------------------------------------------

    def list_topup_packages(
        self, *, page: int = 1, limit: int = 25
    ) -> list[TopupPackage]:
        """List the top-up packages this partner offers its tenants.

        These are the packages you authored. What the *platform* sells you is
        a separate catalogue, reached through the dashboard.
        """
        data = self._partner_request(
            "GET",
            "/v1/partner/topup-packages",
            params={"page": page, "limit": limit},
        )
        return [TopupPackage.from_payload(item) for item in data.get("list", [])]

    def create_topup_package(
        self,
        *,
        name: str,
        credits: int,
        price_cents: int,
        validity_days: int | None = None,
        plan_uuids: Sequence[UUID | str] = (),
    ) -> TopupPackage:
        """Create a credit bundle your tenants can buy.

        ``plan_uuids`` names your own tenant plans and is what lets two plans
        carry different offers: a package scoped to Bronze is invisible to a
        tenant on Silver. Pass none to offer it to every tenant.

        ``validity_days`` is how long the purchased credits stay spendable;
        omit it for credits that never expire.
        """
        data = self._partner_request(
            "POST",
            "/v1/partner/topup-packages",
            json={
                "name": name,
                "credits": credits,
                "price_cents": price_cents,
                "validity_days": validity_days,
                "plan_uuids": [str(plan_uuid) for plan_uuid in plan_uuids],
            },
        )
        return TopupPackage.from_payload(data["package"])

    def update_topup_package(
        self,
        *,
        package_uuid: UUID | str,
        name: str | None = None,
        credits: int | None = None,
        price_cents: int | None = None,
        validity_days: int | None = None,
        status: str | None = None,
        plan_uuids: Sequence[UUID | str] | None = None,
    ) -> TopupPackage:
        """Update one of your packages. Omitted fields are left unchanged.

        Editing changes what the package sells next, never what it already
        sold: existing purchases keep the credits snapshotted at purchase
        time. ``status="inactive"`` withdraws it from the catalogue.

        ``plan_uuids=None`` keeps the current scoping; ``plan_uuids=[]``
        clears it, putting the package back on offer to every tenant.
        """
        payload: dict[str, Any] = {
            key: value
            for key, value in (
                ("name", name),
                ("credits", credits),
                ("price_cents", price_cents),
                ("validity_days", validity_days),
                ("status", status),
            )
            if value is not None
        }
        if plan_uuids is not None:
            payload["plan_uuids"] = [str(plan_uuid) for plan_uuid in plan_uuids]
        data = self._partner_request(
            "PATCH", f"/v1/partner/topup-packages/{package_uuid}", json=payload
        )
        return TopupPackage.from_payload(data["package"])

    def grant_tenant_topup(
        self,
        *,
        tenant_uuid: UUID | str,
        package_uuid: UUID | str,
        activate_now: bool = True,
        reference: str | None = None,
    ) -> TenantTopup:
        """Sell one of your tenants a top-up.

        Same shape as :meth:`subscribe_tenant`, and for the same reason:
        tenants are your clients and never pay this platform, so you own both
        halves. With ``activate_now`` (the default) the credits are spendable
        when this returns — a ``topup`` credit pool owned by the tenant, drawn
        on before your own balance.

        Pass ``activate_now=False`` to record the purchase first (status
        ``pending``) and call :meth:`activate_tenant_topup` once the client
        has paid. ``reference`` is free text kept in the platform's audit
        trail (invoice number, "goodwill credit").

        The package must be one that tenant is actually offered, so a
        plan-scoped package cannot be granted to a tenant on the wrong plan.
        """
        data = self._partner_request(
            "POST",
            "/v1/partner/topups/tenant/purchases",
            json={
                "tenant_uuid": str(tenant_uuid),
                "package_uuid": str(package_uuid),
                "activate_now": activate_now,
                "reference": reference,
            },
        )
        return TenantTopup.from_payload(data)

    def activate_tenant_topup(
        self,
        *,
        transaction_uuid: UUID | str,
        reference: str | None = None,
    ) -> TenantTopup:
        """Activate a pending tenant top-up and provision its credit pool.

        Call this after collecting payment in your own billing system.
        Idempotent: activating an already-active purchase returns it unchanged
        with ``provisioned`` set to None, so a retry never grants the credits
        twice.
        """
        data = self._partner_request(
            "POST",
            f"/v1/partner/topups/tenant/purchases/{transaction_uuid}/activate",
            json={"reference": reference},
        )
        return TenantTopup.from_payload(data)

    def list_tenant_topups(self) -> list[TopupPurchase]:
        """List every top-up bought by one of this partner's tenants."""
        data = self._partner_request("GET", "/v1/partner/topups/tenant/purchases")
        return [
            TopupPurchase.from_payload(item) for item in data.get("purchases", [])
        ]

    def create_identity_provider(
        self,
        *,
        application_uuid: UUID | str,
        name: str,
        issuer: str,
        jwks_url: str | None = None,
        allowed_audiences: Sequence[str],
        allowed_algorithms: Sequence[str] = _DEFAULT_ALLOWED_ALGORITHMS,
        token_max_age_seconds: int = _DEFAULT_TOKEN_MAX_AGE_SECONDS,
        require_email_verified: bool = False,
        allow_jit_users: bool = False,
        claim_names: SubjectTokenClaimNames | None = None,
    ) -> IdentityProvider:
        """Register the partner's own issuer as a trusted identity provider.

        ``application_uuid`` names the application the provider belongs to: a
        token it signs logs users into that application's tenants only. An
        application may have several providers, and any of them logs in any of
        its users, so they must all sign the same tenant and user ids.

        ``jwks_url`` defaults to ``<issuer>/.well-known/jwks.json``, the path
        the SDK's JWKS document is meant to be served from. Pass the same
        ``claim_names`` as the :class:`~iocloud_sdk.SubjectTokenIssuer` that
        signs the tokens, so the two configurations cannot drift apart.
        """
        normalized_issuer = issuer.rstrip("/")
        claims = claim_names or SubjectTokenClaimNames()
        data = self._partner_request(
            "POST",
            "/v1/partner/federation/providers",
            json={
                "application_uuid": str(application_uuid),
                "name": name,
                "issuer": normalized_issuer,
                "jwks_url": jwks_url
                or f"{normalized_issuer}/.well-known/jwks.json",
                "allowed_audiences": list(allowed_audiences),
                "allowed_algorithms": list(allowed_algorithms),
                "token_max_age_seconds": token_max_age_seconds,
                "require_email_verified": require_email_verified,
                "user_claim": claims.user,
                "tenant_claim": claims.tenant,
                "email_claim": claims.email,
                "name_claim": claims.name,
                "allow_jit_users": allow_jit_users,
            },
        )
        return IdentityProvider.from_payload(data["provider"])

    def list_identity_providers(self) -> list[IdentityProvider]:
        """List the identity providers registered by this partner."""
        data = self._partner_request("GET", "/v1/partner/federation/providers")
        return [
            IdentityProvider.from_payload(provider)
            for provider in data["providers"]
        ]

    def jwks(self) -> dict[str, list[dict[str, str]]]:
        """The public key set to publish at ``<issuer>/.well-known/jwks.json``.

        Return it straight from a route handler — this is the whole JWKS
        endpoint. The path is yours to choose; it only has to match the
        ``jwks_url`` registered with the platform. Contains public key material
        only, and is safe to cache.
        """
        return self._require_token_issuer("jwks").jwks()

    def federation_details(self) -> dict[str, str]:
        """The issuer, audience, JWKS URL, and key id this client signs under."""
        token_issuer = self._require_token_issuer("federation_details")
        return {
            "issuer": token_issuer.issuer,
            "audience": token_issuer.audience,
            "jwks_url": token_issuer.jwks_url,
            "kid": token_issuer.signing_key.kid,
        }

    def exchange_subject_token(self, *, subject_token: str) -> FederatedSession:
        """Exchange a partner-signed OIDC JWT for a platform session (RFC 8693).

        Needs no partner token: the subject token is the credential, and trust
        is decided by the identity provider its ``iss`` resolves to. Raises
        :class:`~iocloud_sdk.IOCloudTokenExchangeError` when the platform
        rejects the token.
        """
        if not subject_token.strip():
            raise ValueError("subject_token must not be empty")

        response = self._http.post(
            f"{self._base_url}/v1/federation/token",
            data={
                "grant_type": TOKEN_EXCHANGE_GRANT_TYPE,
                "subject_token": subject_token,
                "subject_token_type": JWT_TOKEN_TYPE,
            },
        )
        body = _json_object(response)
        if not response.is_success:
            raise IOCloudTokenExchangeError(
                status_code=response.status_code,
                error=str(body.get("error", "invalid_grant")),
                error_description=str(
                    body.get(
                        "error_description",
                        response.text or "The subject token was rejected.",
                    )
                ),
            )
        return FederatedSession.from_payload(body)

    def federated_login(
        self,
        *,
        subject: str,
        external_tenant_id: str,
        email: str | None = None,
        name: str | None = None,
        email_verified: bool = False,
        extra_claims: dict[str, Any] | None = None,
    ) -> FederatedSession:
        """Sign a subject token for a logged-in partner user and exchange it.

        The whole partner-side login integration, in one call. Requires a
        ``token_issuer`` on the client.
        """
        subject_token = self._require_token_issuer("federated_login").issue(
            subject=subject,
            external_tenant_id=external_tenant_id,
            email=email,
            name=name,
            email_verified=email_verified,
            extra_claims=extra_claims,
        )
        return self.exchange_subject_token(subject_token=subject_token)

    def create_tenant_credentials(
        self,
        *,
        tenant_uuid: UUID | str,
        name: str = "realestate-persona-sync",
    ) -> TenantCredential:
        """Issue a client_id/client_secret pair for a tenant owned by the partner.

        The ``client_secret`` is returned exactly once, at creation; the caller
        must persist it to issue tenant tokens later.
        """
        data = self._partner_request(
            "POST",
            f"/v1/partner/tenants/{tenant_uuid}/credentials",
            json={"name": name},
        )
        return TenantCredential.from_payload(data["credential"])

    def issue_tenant_token(
        self,
        *,
        client_id: str,
        client_secret: str,
        force_refresh: bool = False,
    ) -> TenantToken:
        """Exchange tenant client credentials for a tenant token and cache it."""
        cached = self._tenant_tokens.get(client_id)
        if not force_refresh and self._token_is_fresh(cached):
            assert cached is not None
            return cached

        data = self._request(
            "POST",
            "/v1/tenant/auth/token",
            json={"client_id": client_id, "client_secret": client_secret},
        )
        token = TenantToken.from_payload(data["token"])
        self._tenant_tokens[client_id] = token
        return token

    def set_user_persona(
        self,
        *,
        user_uuid: UUID | str,
        persona: str,
        tenant_client_id: str,
        tenant_client_secret: str,
    ) -> dict[str, Any]:
        """Persist a tenant user's onboarding persona via a tenant token.

        Uses the tenant credentials to obtain a tenant token, then PATCHes the
        user's persona. Refreshes the token once on a 401 before giving up.
        """
        path = f"/v1/tenant/users/{user_uuid}/persona"
        body = {"persona": persona}
        token = self.issue_tenant_token(
            client_id=tenant_client_id, client_secret=tenant_client_secret
        )
        try:
            return self._request(
                "PATCH",
                path,
                json=body,
                headers={"Authorization": f"Bearer {token.access_token}"},
            )
        except IOCloudAuthenticationError:
            token = self.issue_tenant_token(
                client_id=tenant_client_id,
                client_secret=tenant_client_secret,
                force_refresh=True,
            )
            return self._request(
                "PATCH",
                path,
                json=body,
                headers={"Authorization": f"Bearer {token.access_token}"},
            )

    def create_user(
        self,
        *,
        name: str,
        email: str,
        tenant_client_id: str,
        tenant_client_secret: str,
        external_id: str | None = None,
    ) -> User:
        """Create a user inside the tenant a tenant credential belongs to.

        ``external_id`` is your own id for the person — the value your subject
        tokens carry in the user claim (``sub`` by default) — and is how a
        federated login finds the user within the token's tenant. Pre-create
        users this way when the identity provider does not provision them just
        in time (``allow_jit_users=False``).

        The route is tenant-scoped, so the call authenticates with the
        credential from :meth:`create_tenant_credentials`, refreshing the
        tenant token once on a 401. The user starts ``pending``: activate it
        with :meth:`update_user_status` before it can log in.
        """
        payload: dict[str, Any] = {"name": name, "email": email}
        if external_id is not None:
            payload["external_id"] = external_id
        data = self._tenant_request(
            "POST",
            "/v1/tenant/users",
            json=payload,
            tenant_client_id=tenant_client_id,
            tenant_client_secret=tenant_client_secret,
        )
        return User.from_payload(data["user"])

    def update_user_status(
        self,
        *,
        user_uuid: UUID | str,
        status: str,
        tenant_client_id: str,
        tenant_client_secret: str,
    ) -> User:
        """Activate (``"active"``) or deactivate (``"deactivated"``) a user.

        Tenant-scoped like :meth:`create_user`, and authenticated the same
        way. A pending user cannot log in until this activates it.
        """
        data = self._tenant_request(
            "PATCH",
            f"/v1/tenant/users/{user_uuid}/status",
            json={"status": status},
            tenant_client_id=tenant_client_id,
            tenant_client_secret=tenant_client_secret,
        )
        return User.from_payload(data["user"])

    def _require_token_issuer(self, called_method: str) -> "SubjectTokenIssuer":
        """The configured token issuer, or a message naming what to configure.

        Federation is optional, so every federation entry point checks here
        rather than failing when the client is constructed.
        """
        if self._token_issuer is None:
            raise IOCloudFederationError(
                f"{called_method}() needs a token_issuer. Construct the client"
                " with token_issuer=SubjectTokenIssuer(...)."
            )
        return self._token_issuer

    def _partner_request(
        self,
        method: str,
        path: str,
        *,
        json: dict[str, Any] | None = None,
        params: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        token = self.issue_partner_token()
        try:
            return self._request(
                method,
                path,
                json=json,
                params=params,
                headers={"Authorization": f"Bearer {token.access_token}"},
            )
        except IOCloudAuthenticationError:
            token = self.issue_partner_token(force_refresh=True)
            return self._request(
                method,
                path,
                json=json,
                params=params,
                headers={"Authorization": f"Bearer {token.access_token}"},
            )

    def _tenant_request(
        self,
        method: str,
        path: str,
        *,
        json: dict[str, Any],
        tenant_client_id: str,
        tenant_client_secret: str,
    ) -> dict[str, Any]:
        """The tenant-token twin of :meth:`_partner_request`.

        Issues (or reuses) the tenant token for the credential and refreshes
        it once when the request is rejected with a 401.
        """
        token = self.issue_tenant_token(
            client_id=tenant_client_id, client_secret=tenant_client_secret
        )
        try:
            return self._request(
                method,
                path,
                json=json,
                headers={"Authorization": f"Bearer {token.access_token}"},
            )
        except IOCloudAuthenticationError:
            token = self.issue_tenant_token(
                client_id=tenant_client_id,
                client_secret=tenant_client_secret,
                force_refresh=True,
            )
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
        json: dict[str, Any] | None = None,
        params: dict[str, Any] | None = None,
        headers: dict[str, str] | None = None,
    ) -> dict[str, Any]:
        response = self._http.request(
            method,
            f"{self._base_url}{path}",
            json=json,
            params=params,
            headers=headers,
        )
        body = _json_object(response)

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
    def _token_is_fresh(token: PartnerToken | TenantToken | None) -> bool:
        if token is None:
            return False
        expires_at = token.expires_at
        if expires_at.tzinfo is None:
            expires_at = expires_at.replace(tzinfo=timezone.utc)
        return expires_at > datetime.now(timezone.utc) + timedelta(seconds=30)


def _json_object(response: httpx.Response) -> dict[str, Any]:
    """Decode a response body, tolerating empty or non-object payloads."""
    try:
        body = response.json()
    except ValueError:
        return {}
    return body if isinstance(body, dict) else {}
