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
    ExternalTenantMapping,
    FederatedSession,
    IdentityProvider,
    PartnerToken,
    SubjectTokenClaimNames,
    Tenant,
    TenantCredential,
    TenantToken,
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

    def create_identity_provider(
        self,
        *,
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
        json: dict[str, Any] | None = None,
        headers: dict[str, str] | None = None,
    ) -> dict[str, Any]:
        response = self._http.request(
            method,
            f"{self._base_url}{path}",
            json=json,
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
