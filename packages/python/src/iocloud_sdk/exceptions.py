class IOCloudError(Exception):
    """Base exception for all SDK failures."""


class IOCloudAPIError(IOCloudError):
    """An API response reported a failure.

    ``code`` is the response's machine-readable ``code`` (``TENANT_NOT_FOUND``,
    ``STANDING_CONTEXT_FULL``, …). ``reason`` is its ``error.reason`` when the
    platform gives one — the finer cause of a refusal, such as
    ``STANDING_ENTRY_TOO_LONG`` under ``STANDING_ENTRY_INVALID`` — and ``None``
    otherwise. ``fields`` is a ``422 VALIDATION_ERROR``'s ``error.fields``, each
    invalid field's path mapped to its messages, and empty otherwise.
    """

    def __init__(
        self,
        status_code: int,
        code: str,
        message: str,
        *,
        reason: str | None = None,
        fields: dict[str, list[str]] | None = None,
    ) -> None:
        super().__init__(f"{status_code} {code}: {message}")
        self.status_code = status_code
        self.code = code
        self.message = message
        self.reason = reason
        self.fields: dict[str, list[str]] = dict(fields or {})


class IOCloudAuthenticationError(IOCloudAPIError):
    """Partner credentials or a supplied bearer token were rejected."""


class IOCloudTokenExchangeError(IOCloudAPIError):
    """The token exchange endpoint rejected a partner-signed subject token.

    Carries the RFC 6749 error body. ``error`` is the machine-readable reason
    (``invalid_grant``, ``invalid_target``, …); the platform deliberately keeps
    ``error_description`` generic — its audit log holds the precise cause.
    """

    def __init__(self, status_code: int, error: str, error_description: str) -> None:
        super().__init__(status_code=status_code, code=error, message=error_description)
        self.error = error
        self.error_description = error_description


class IOCloudFederationError(IOCloudError):
    """Partner-side federation is misconfigured: no signing key, bad PEM, …"""
