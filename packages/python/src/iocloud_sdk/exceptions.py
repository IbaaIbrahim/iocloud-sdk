class IOCloudError(Exception):
    """Base exception for all SDK failures."""


class IOCloudAPIError(IOCloudError):
    """An API response reported a failure."""

    def __init__(self, status_code: int, code: str, message: str) -> None:
        super().__init__(f"{status_code} {code}: {message}")
        self.status_code = status_code
        self.code = code
        self.message = message


class IOCloudAuthenticationError(IOCloudAPIError):
    """Partner credentials or a supplied bearer token were rejected."""
