"""IOCloud SDK.

Signing keys and subject-token minting live in :mod:`iocloud_sdk.federation`
and need the optional ``federation`` extra
(``pip install "iocloud-sdk[federation]"``). Importing them from the submodule
keeps that dependency boundary visible, and keeps installations that only call
the HTTP API free of the crypto dependencies::

    from iocloud_sdk.federation import FederationSigningKey, SubjectTokenIssuer
"""

from .client import JWT_TOKEN_TYPE, TOKEN_EXCHANGE_GRANT_TYPE, IOCloudClient
from .exceptions import (
    IOCloudAPIError,
    IOCloudAuthenticationError,
    IOCloudError,
    IOCloudFederationError,
    IOCloudTokenExchangeError,
)
from .models import (
    ExternalTenantMapping,
    FederatedSession,
    IdentityProvider,
    PartnerToken,
    PlanSubscription,
    ProvisionedBalance,
    SubjectTokenClaimNames,
    Tenant,
    TenantCredential,
    TenantPlan,
    TenantSubscription,
    TenantToken,
)

__all__ = [
    "ExternalTenantMapping",
    "FederatedSession",
    "IOCloudAPIError",
    "IOCloudAuthenticationError",
    "IOCloudClient",
    "IOCloudError",
    "IOCloudFederationError",
    "IOCloudTokenExchangeError",
    "IdentityProvider",
    "JWT_TOKEN_TYPE",
    "PartnerToken",
    "PlanSubscription",
    "ProvisionedBalance",
    "SubjectTokenClaimNames",
    "TOKEN_EXCHANGE_GRANT_TYPE",
    "Tenant",
    "TenantCredential",
    "TenantPlan",
    "TenantSubscription",
    "TenantToken",
]
