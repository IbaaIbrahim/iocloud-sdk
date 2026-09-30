import pathlib, time, uuid
import jwt
from iocloud_sdk.federation import FederationSigningKey

pem = pathlib.Path("iocloud-sdk/examples/laravel-demo/storage/iocloud-federation-private.key").read_text()
kid = FederationSigningKey.from_private_key_pem(pem).kid
assert kid == "OSdaNlG6m9CPvT6XsLBldauKwXwikHh5wQQ97OV4GE8", f"not the key your JWKS serves: {kid}"

now = int(time.time())
print(jwt.encode(
    {
        "iss": "https://shakira-stockholm-programmers-interact.trycloudflare.com",
        "aud": "http://127.0.0.1:8001",
        "sub": "57d0a689-6aa2-4b2d-9cc1-f58a97493323",
        "nonce": "KYSgcbdccJxr1XlyhHhgm4WQbXMRPu2dfk6qm4w-jFc",
        "iat": now,
        "exp": min(now + 120, 1790788021),
        "jti": uuid.uuid4().hex,
    },
    pem,
    algorithm="RS256",
    headers={"kid": kid, "typ": "issuer-proof+jwt"},
))