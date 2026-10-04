"""Where the platform fetches an identity provider's keys.

The platform stores an issuer's key-set location as ``jwks_path``, a path it
appends to the issuer's own origin: it fetches ``issuer_origin + jwks_path``
and nothing else. A JWKS URL a partner already has is reduced to that path, and
only when it is on the issuer's origin; anywhere else, the URL names keys the
platform would never fetch.
"""

import ipaddress
from urllib.parse import urlsplit

# The platform's own default, and where the SDK's key set is meant to be served.
DEFAULT_JWKS_PATH = "/.well-known/jwks.json"

_DEFAULT_PORTS = {"http": 80, "https": 443}


def origin_of(url: str) -> str:
    """``scheme://host[:port]`` of ``url``, canonical as the platform compares it.

    Scheme and host lowercased, the host without a trailing dot and in IDNA
    ASCII, the scheme's default port dropped. Raises :class:`ValueError` for a
    URL with no http or https origin.
    """
    parts = urlsplit(url.strip())
    scheme = parts.scheme.lower()
    if scheme not in _DEFAULT_PORTS:
        raise ValueError(f"'{url}' is not an http or https URL")
    host = (parts.hostname or "").rstrip(".")
    if not host:
        raise ValueError(f"'{url}' has no host")
    host = _ascii_host(host, url)
    port = parts.port  # ValueError for a port that is not a number in range
    rendered_host = f"[{host}]" if ":" in host else host
    if port is None or port == _DEFAULT_PORTS[scheme]:
        return f"{scheme}://{rendered_host}"
    return f"{scheme}://{rendered_host}:{port}"


def resolve_jwks_path(
    issuer: str, jwks_path: str | None, jwks_url: str | None
) -> str:
    """The ``jwks_path`` to register: the one given, the default, or ``jwks_url``'s path."""
    if jwks_url is None:
        return DEFAULT_JWKS_PATH if jwks_path is None else jwks_path
    if jwks_path is not None:
        raise ValueError("Pass jwks_path or jwks_url, not both.")
    return jwks_path_of(jwks_url, issuer=issuer)


def jwks_path_of(jwks_url: str, *, issuer: str) -> str:
    """The path of ``jwks_url``, which must be on ``issuer``'s origin.

    Raises :class:`ValueError` for a URL on any other origin, or one carrying a
    query or a fragment: the platform keeps the path alone, so either would be
    silently dropped.
    """
    try:
        issuer_origin = origin_of(issuer)
    except ValueError as exc:
        raise ValueError(
            f"The issuer '{issuer}' has no http or https origin to put a JWKS path on."
        ) from exc
    try:
        on_origin = origin_of(jwks_url) == issuer_origin
    except ValueError:
        on_origin = False
    if not on_origin:
        raise ValueError(
            f"jwks_url '{jwks_url}' is not on the issuer's origin, {issuer_origin}. "
            "The platform fetches an issuer's keys from its own origin only: serve "
            f"them on {issuer_origin} and pass that path as jwks_path."
        )
    parts = urlsplit(jwks_url.strip())
    if parts.query or parts.fragment or "?" in jwks_url or "#" in jwks_url:
        raise ValueError(
            f"jwks_url '{jwks_url}' carries a query or a fragment; the platform "
            "stores a path only. Pass jwks_path instead."
        )
    if not parts.path:
        raise ValueError(
            f"jwks_url '{jwks_url}' names no path. Pass jwks_path instead."
        )
    return parts.path


def _ascii_host(host: str, url: str) -> str:
    """``host`` as DNS spells it: an IP literal as it is, a name as IDNA ASCII."""
    try:
        ipaddress.ip_address(host)
        return host
    except ValueError:
        pass
    try:
        return host.encode("idna").decode("ascii")
    except UnicodeError as exc:
        raise ValueError(f"'{url}' does not have a valid host name") from exc
