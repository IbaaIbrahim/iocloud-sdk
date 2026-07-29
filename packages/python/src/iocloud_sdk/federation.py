"""Partner-side federation: signing keys, the JWKS document, subject tokens.

A partner that federates users into IOCloud has to act as a small OIDC issuer:
hold an RSA keypair, publish the public half as a JWKS at its issuer URL, and
sign a short-lived JWT for every user who logs in. This module provides those
three pieces so a partner never has to touch a JWS library.

Requires the ``federation`` extra::

    pip install "iocloud-sdk[federation]"
"""

import base64
import hashlib
import json
from datetime import datetime, timedelta, timezone
from typing import Any, Optional
from uuid import uuid4

from .exceptions import IOCloudFederationError
from .models import SubjectTokenClaimNames

try:
    import jwt
    from cryptography.hazmat.primitives import serialization
    from cryptography.hazmat.primitives.asymmetric import rsa
except ModuleNotFoundError as exc:  # pragma: no cover - import guard
    raise IOCloudFederationError(
        "Federation support needs the optional dependencies. Install them with:"
        ' pip install "iocloud-sdk[federation]"'
    ) from exc

# RFC 7518 §3.1 recommends RSASSA-PKCS1-v1_5 with SHA-256 as the baseline; the
# platform accepts it for every provider without extra configuration.
SIGNING_ALGORITHM = "RS256"
KEY_TYPE = "RSA"
KEY_USE = "sig"

_RSA_PUBLIC_EXPONENT = 65537
_RSA_KEY_SIZE_BITS = 2048

# Subject tokens exist only to be exchanged once, immediately after login.
DEFAULT_TOKEN_TTL_SECONDS = 300

# Claims whose values the issuer alone decides.
_RESERVED_CLAIMS = frozenset({"iss", "aud", "iat", "nbf", "exp", "jti"})


def _base64url_encode(raw: bytes) -> str:
    """Encode bytes as unpadded base64url (RFC 7515 §2)."""
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode("ascii")


def _integer_to_base64url(value: int) -> str:
    """Encode a big-endian integer as unpadded base64url (RFC 7518 §6.3)."""
    byte_length = (value.bit_length() + 7) // 8
    return _base64url_encode(value.to_bytes(byte_length, byteorder="big"))


def _jwk_thumbprint(modulus: str, exponent: str) -> str:
    """Compute the RFC 7638 thumbprint used as the key id.

    Deriving the ``kid`` from the key itself — rather than a random value —
    keeps it stable across process restarts and across the three SDKs, so a
    published JWKS and a signed token always agree on the key id.
    """
    canonical_members = {"e": exponent, "kty": KEY_TYPE, "n": modulus}
    canonical_json = json.dumps(
        canonical_members, separators=(",", ":"), sort_keys=True
    )
    return _base64url_encode(hashlib.sha256(canonical_json.encode("utf-8")).digest())


class FederationSigningKey:
    """An RSA keypair with the JWK views the partner's OIDC endpoints serve."""

    def __init__(self, private_key: rsa.RSAPrivateKey) -> None:
        public_numbers = private_key.public_key().public_numbers()
        if public_numbers.n.bit_length() < _RSA_KEY_SIZE_BITS:
            raise IOCloudFederationError(
                f"Federation signing keys must be at least {_RSA_KEY_SIZE_BITS}"
                f" bits; this key is {public_numbers.n.bit_length()} bits."
            )

        self._private_key = private_key
        self._modulus = _integer_to_base64url(public_numbers.n)
        self._exponent = _integer_to_base64url(public_numbers.e)
        self._kid = _jwk_thumbprint(self._modulus, self._exponent)

    @classmethod
    def generate(cls) -> "FederationSigningKey":
        """Generate a fresh 2048-bit RSA keypair."""
        return cls(
            rsa.generate_private_key(
                public_exponent=_RSA_PUBLIC_EXPONENT, key_size=_RSA_KEY_SIZE_BITS
            )
        )

    @classmethod
    def from_private_key_pem(
        cls, private_key_pem: str | bytes, password: Optional[bytes] = None
    ) -> "FederationSigningKey":
        """Load a persisted key so the published ``kid`` survives a restart."""
        pem_bytes = (
            private_key_pem.encode("utf-8")
            if isinstance(private_key_pem, str)
            else private_key_pem
        )
        try:
            private_key = serialization.load_pem_private_key(pem_bytes, password)
        except (ValueError, TypeError) as exc:
            raise IOCloudFederationError(
                "The federation private key is not a readable PEM private key."
            ) from exc
        if not isinstance(private_key, rsa.RSAPrivateKey):
            raise IOCloudFederationError(
                "The federation private key must be an RSA key."
            )
        return cls(private_key)

    def __repr__(self) -> str:
        return f"FederationSigningKey(kid={self._kid!r}, private_key='***')"

    @property
    def kid(self) -> str:
        """The key id published in the JWKS and set in every token header."""
        return self._kid

    @property
    def private_key_pem(self) -> str:
        """PKCS#8 PEM of the private half. Store it as a secret."""
        return self._private_key.private_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PrivateFormat.PKCS8,
            encryption_algorithm=serialization.NoEncryption(),
        ).decode("ascii")

    @property
    def public_key_pem(self) -> str:
        """PEM of the public half.

        Not needed to publish a JWKS — that is derived from the private key — but
        available so the pair can be inspected or handed to another tool.
        """
        return (
            self._private_key.public_key()
            .public_bytes(
                encoding=serialization.Encoding.PEM,
                format=serialization.PublicFormat.SubjectPublicKeyInfo,
            )
            .decode("ascii")
        )

    def public_jwk(self) -> dict[str, str]:
        """The public key as a JWK (RFC 7517)."""
        return {
            "kty": KEY_TYPE,
            "use": KEY_USE,
            "alg": SIGNING_ALGORITHM,
            "kid": self._kid,
            "n": self._modulus,
            "e": self._exponent,
        }

    def jwks(self) -> dict[str, list[dict[str, str]]]:
        """The document to serve at ``<issuer>/.well-known/jwks.json``."""
        return {"keys": [self.public_jwk()]}

    def sign(self, claims: dict[str, Any]) -> str:
        """Sign ``claims`` into a compact JWS naming this key's ``kid``."""
        return jwt.encode(
            claims,
            self.private_key_pem,
            algorithm=SIGNING_ALGORITHM,
            headers={"kid": self._kid},
        )


def build_jwks(*signing_keys: FederationSigningKey) -> dict[str, list[dict[str, str]]]:
    """Merge several keys into one JWKS, which is how a rotation is published.

    During a rotation the retiring key stays in the document until every token
    it signed has expired; verifiers select by ``kid``.
    """
    if not signing_keys:
        raise IOCloudFederationError("A JWKS must publish at least one key.")
    return {"keys": [signing_key.public_jwk() for signing_key in signing_keys]}


class SubjectTokenIssuer:
    """Mints the short-lived OIDC JWTs a partner exchanges for a session."""

    def __init__(
        self,
        *,
        signing_key: FederationSigningKey,
        issuer: str,
        audience: str,
        token_ttl_seconds: int = DEFAULT_TOKEN_TTL_SECONDS,
        claim_names: SubjectTokenClaimNames | None = None,
    ) -> None:
        if not issuer.strip():
            raise IOCloudFederationError("issuer must not be empty")
        if not audience.strip():
            raise IOCloudFederationError("audience must not be empty")
        if token_ttl_seconds <= 0:
            raise IOCloudFederationError("token_ttl_seconds must be positive")

        self._signing_key = signing_key
        self._issuer = issuer.rstrip("/")
        self._audience = audience
        self._token_ttl_seconds = token_ttl_seconds
        self._claim_names = claim_names or SubjectTokenClaimNames()

    @property
    def issuer(self) -> str:
        """The ``iss`` value; also the base of the JWKS URL."""
        return self._issuer

    @property
    def audience(self) -> str:
        return self._audience

    @property
    def claim_names(self) -> SubjectTokenClaimNames:
        return self._claim_names

    @property
    def signing_key(self) -> FederationSigningKey:
        return self._signing_key

    @property
    def jwks_url(self) -> str:
        """Where the platform must be told to fetch this issuer's keys."""
        return f"{self._issuer}/.well-known/jwks.json"

    def jwks(self) -> dict[str, list[dict[str, str]]]:
        """The JWKS document to serve at :attr:`jwks_url`."""
        return self._signing_key.jwks()

    def issue(
        self,
        *,
        subject: str,
        external_tenant_id: str,
        email: Optional[str] = None,
        name: Optional[str] = None,
        email_verified: bool = False,
        extra_claims: dict[str, Any] | None = None,
    ) -> str:
        """Sign a subject token for one logged-in partner user.

        ``subject`` must be the partner's stable, never-reused user id: it is
        the identity key the platform stores, so reusing it for a different
        person hands over that person's account.
        """
        if not subject.strip():
            raise IOCloudFederationError("subject must not be empty")
        if not external_tenant_id.strip():
            raise IOCloudFederationError("external_tenant_id must not be empty")

        claims = self._standard_claims(
            subject=subject,
            external_tenant_id=external_tenant_id,
            email=email,
            name=name,
            email_verified=email_verified,
        )
        # Reserved claims stay under the issuer's control: a caller cannot
        # widen the audience or extend the lifetime through extra_claims.
        for claim_name, claim_value in (extra_claims or {}).items():
            if claim_name in _RESERVED_CLAIMS:
                raise IOCloudFederationError(
                    f"extra_claims may not override the '{claim_name}' claim."
                )
            claims[claim_name] = claim_value

        return self._signing_key.sign(claims)

    def with_claim_names(
        self, claim_names: SubjectTokenClaimNames
    ) -> "SubjectTokenIssuer":
        """A copy that emits identity values under different claim names."""
        return SubjectTokenIssuer(
            signing_key=self._signing_key,
            issuer=self._issuer,
            audience=self._audience,
            token_ttl_seconds=self._token_ttl_seconds,
            claim_names=claim_names,
        )

    def _standard_claims(
        self,
        *,
        subject: str,
        external_tenant_id: str,
        email: Optional[str],
        name: Optional[str],
        email_verified: bool,
    ) -> dict[str, Any]:
        issued_at = datetime.now(timezone.utc)
        expires_at = issued_at + timedelta(seconds=self._token_ttl_seconds)
        claims: dict[str, Any] = {
            "iss": self._issuer,
            "aud": self._audience,
            "iat": int(issued_at.timestamp()),
            "nbf": int(issued_at.timestamp()),
            "exp": int(expires_at.timestamp()),
            # A unique id per token: the platform registers it so the same
            # token cannot be exchanged twice.
            "jti": uuid4().hex,
            self._claim_names.user: subject,
            self._claim_names.tenant: external_tenant_id,
        }
        if email is not None:
            claims[self._claim_names.email] = email
            claims["email_verified"] = email_verified
        if name is not None:
            claims[self._claim_names.name] = name
        return claims


__all__ = [
    "DEFAULT_TOKEN_TTL_SECONDS",
    "FederationSigningKey",
    "KEY_TYPE",
    "KEY_USE",
    "SIGNING_ALGORITHM",
    "SubjectTokenClaimNames",
    "SubjectTokenIssuer",
    "build_jwks",
]
