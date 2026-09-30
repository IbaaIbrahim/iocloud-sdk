import json
import unittest
from datetime import datetime, timedelta, timezone
from urllib.parse import parse_qs

import httpx
import jwt

from iocloud_sdk import (
    JWT_TOKEN_TYPE,
    TOKEN_EXCHANGE_GRANT_TYPE,
    IOCloudClient,
    IOCloudFederationError,
    IOCloudTokenExchangeError,
    SubjectTokenClaimNames,
)
from iocloud_sdk.federation import FederationSigningKey, SubjectTokenIssuer

BASE_URL = "https://api.example.com"
APPLICATION_UUID = "11111111-1111-4111-8111-111111111111"
PARTNER_TOKEN_BODY = {
    "data": {
        "token": {
            "access_token": "partner-token",
            "token_type": "Bearer",
            "expires_at": "2099-01-01T00:00:00Z",
        }
    }
}
PROVIDER_BODY = {
    "uuid": "4be507fc-2a1b-4e19-9f0e-2c7f7f5f8a11",
    "application_uuid": APPLICATION_UUID,
    "name": "Acme Portal",
    "issuer": "https://portal.acme.example",
    "jwks_url": "https://portal.acme.example/.well-known/jwks.json",
    "allowed_audiences": ["ai-ecosystem"],
    "allowed_algorithms": ["RS256"],
    "token_max_age_seconds": 900,
    "require_email_verified": True,
    "user_claim": "sub",
    "tenant_claim": "tenant_id",
    "email_claim": "email",
    "name_claim": "name",
    "allow_jit_users": True,
    "status": "active",
    "created_at": "2026-07-09T10:15:00Z",
}
SESSION_BODY = {
    "access_token": "platform-session-token",
    "issued_token_type": "urn:ietf:params:oauth:token-type:access_token",
    "token_type": "Bearer",
    "expires_in": 3600,
    "user_uuid": "992d64fc-8f2a-4c31-b7e5-1d0a6c9f3b48",
    "name": "Test User",
    "email": "user@customer.example",
}


class RecordingTransport:
    """Captures every request and replies from a path-keyed response map."""

    def __init__(self, responses: dict[str, httpx.Response]) -> None:
        self._responses = responses
        self.requests: list[httpx.Request] = []

    def handler(self, request: httpx.Request) -> httpx.Response:
        self.requests.append(request)
        response = self._responses.get(request.url.path)
        if response is None:
            raise AssertionError(f"unexpected request to {request.url.path}")
        return response

    def request_to(self, path: str) -> httpx.Request:
        for request in self.requests:
            if request.url.path == path:
                return request
        raise AssertionError(f"no request was sent to {path}")


class ClientFederationTestCase(unittest.TestCase):
    def build_client(
        self, responses: dict[str, httpx.Response], **client_options
    ) -> tuple[IOCloudClient, RecordingTransport]:
        transport = RecordingTransport(responses)
        http_client = httpx.Client(transport=httpx.MockTransport(transport.handler))
        self.addCleanup(http_client.close)
        client = IOCloudClient(
            client_id="client-id",
            client_secret="client-secret",
            base_url=BASE_URL,
            http_client=http_client,
            **client_options,
        )
        return client, transport


class CreateIdentityProviderTests(ClientFederationTestCase):
    def test_it_derives_the_jwks_url_from_the_issuer(self) -> None:
        client, transport = self.build_client(
            {
                "/v1/partner/auth/token": httpx.Response(200, json=PARTNER_TOKEN_BODY),
                "/v1/partner/federation/providers": httpx.Response(
                    201, json={"data": {"provider": PROVIDER_BODY}}
                ),
            }
        )

        provider = client.create_identity_provider(
            application_uuid=APPLICATION_UUID,
            name="Acme Portal",
            issuer="https://portal.acme.example/",
            allowed_audiences=["ai-ecosystem"],
            require_email_verified=True,
            allow_jit_users=True,
        )

        sent = transport.request_to("/v1/partner/federation/providers")
        body = json.loads(sent.content)
        self.assertEqual(body["application_uuid"], APPLICATION_UUID)
        self.assertEqual(body["issuer"], "https://portal.acme.example")
        self.assertEqual(
            body["jwks_url"], "https://portal.acme.example/.well-known/jwks.json"
        )
        self.assertEqual(body["allowed_algorithms"], ["RS256"])
        self.assertEqual(body["token_max_age_seconds"], 900)
        self.assertTrue(body["require_email_verified"])
        self.assertTrue(body["allow_jit_users"])
        self.assertEqual(sent.headers["authorization"], "Bearer partner-token")
        self.assertEqual(str(provider.uuid), PROVIDER_BODY["uuid"])
        self.assertEqual(str(provider.application_uuid), APPLICATION_UUID)
        self.assertTrue(provider.is_active)

    def test_it_registers_the_claim_names_the_issuer_will_emit(self) -> None:
        client, transport = self.build_client(
            {
                "/v1/partner/auth/token": httpx.Response(200, json=PARTNER_TOKEN_BODY),
                "/v1/partner/federation/providers": httpx.Response(
                    201, json={"data": {"provider": PROVIDER_BODY}}
                ),
            }
        )

        client.create_identity_provider(
            application_uuid=APPLICATION_UUID,
            name="Acme Portal",
            issuer="https://portal.acme.example",
            allowed_audiences=["ai-ecosystem"],
            claim_names=SubjectTokenClaimNames(user="user_id", tenant="org_id"),
        )

        body = json.loads(
            transport.request_to("/v1/partner/federation/providers").content
        )
        self.assertEqual(body["user_claim"], "user_id")
        self.assertEqual(body["tenant_claim"], "org_id")
        self.assertEqual(body["email_claim"], "email")
        self.assertEqual(body["name_claim"], "name")

    def test_provider_exposes_the_claim_mapping_the_platform_stored(self) -> None:
        client, _ = self.build_client(
            {
                "/v1/partner/auth/token": httpx.Response(200, json=PARTNER_TOKEN_BODY),
                "/v1/partner/federation/providers": httpx.Response(
                    200, json={"data": {"providers": [PROVIDER_BODY]}}
                ),
            }
        )

        providers = client.list_identity_providers()

        self.assertEqual(len(providers), 1)
        self.assertEqual(str(providers[0].application_uuid), APPLICATION_UUID)
        self.assertEqual(
            providers[0].claim_names,
            SubjectTokenClaimNames(
                user="sub", tenant="tenant_id", email="email", name="name"
            ),
        )

    def test_listing_providers_sends_no_request_body(self) -> None:
        client, transport = self.build_client(
            {
                "/v1/partner/auth/token": httpx.Response(200, json=PARTNER_TOKEN_BODY),
                "/v1/partner/federation/providers": httpx.Response(
                    200, json={"data": {"providers": []}}
                ),
            }
        )

        client.list_identity_providers()

        sent = transport.request_to("/v1/partner/federation/providers")
        self.assertEqual(sent.method, "GET")
        self.assertEqual(sent.content, b"")


class ExchangeSubjectTokenTests(ClientFederationTestCase):
    def test_it_posts_the_rfc_8693_form_grammar_without_a_partner_token(self) -> None:
        client, transport = self.build_client(
            {"/v1/federation/token": httpx.Response(200, json=SESSION_BODY)}
        )

        session = client.exchange_subject_token(subject_token="signed.jwt.value")

        sent = transport.request_to("/v1/federation/token")
        form = parse_qs(sent.content.decode())
        self.assertEqual(form["grant_type"], [TOKEN_EXCHANGE_GRANT_TYPE])
        self.assertEqual(form["subject_token"], ["signed.jwt.value"])
        self.assertEqual(form["subject_token_type"], [JWT_TOKEN_TYPE])
        self.assertNotIn("authorization", sent.headers)
        self.assertEqual(
            sent.headers["content-type"], "application/x-www-form-urlencoded"
        )
        self.assertEqual(session.access_token, "platform-session-token")
        self.assertEqual(session.expires_in, 3600)
        self.assertEqual(str(session.user_uuid), SESSION_BODY["user_uuid"])
        self.assertEqual(session.email, "user@customer.example")

    def test_it_turns_expires_in_into_an_absolute_instant(self) -> None:
        client, _ = self.build_client(
            {"/v1/federation/token": httpx.Response(200, json=SESSION_BODY)}
        )

        session = client.exchange_subject_token(subject_token="signed.jwt.value")

        expected = datetime.now(timezone.utc) + timedelta(seconds=3600)
        self.assertLess(abs(session.expires_at - expected), timedelta(seconds=5))

    def test_a_rejected_token_raises_the_rfc_6749_error(self) -> None:
        client, _ = self.build_client(
            {
                "/v1/federation/token": httpx.Response(
                    400,
                    json={
                        "error": "invalid_target",
                        "error_description": "The token's tenant is not mapped.",
                    },
                )
            }
        )

        with self.assertRaises(IOCloudTokenExchangeError) as raised:
            client.exchange_subject_token(subject_token="signed.jwt.value")

        self.assertEqual(raised.exception.error, "invalid_target")
        self.assertEqual(
            raised.exception.error_description, "The token's tenant is not mapped."
        )
        self.assertEqual(raised.exception.status_code, 400)

    def test_an_empty_subject_token_never_reaches_the_network(self) -> None:
        client, transport = self.build_client({})

        with self.assertRaises(ValueError):
            client.exchange_subject_token(subject_token="   ")

        self.assertEqual(transport.requests, [])


class PublishJwksTests(ClientFederationTestCase):
    """`jwks()` is the JWKS endpoint: return it from a route of your own."""

    def build_federating_client(self) -> tuple[IOCloudClient, FederationSigningKey]:
        signing_key = FederationSigningKey.generate()
        client, _ = self.build_client(
            {},
            token_issuer=SubjectTokenIssuer(
                signing_key=signing_key,
                issuer="https://portal.acme.example",
                audience="ai-ecosystem",
            ),
        )
        return client, signing_key

    def test_it_returns_the_key_set_the_platform_will_fetch(self) -> None:
        client, signing_key = self.build_federating_client()

        self.assertEqual(client.jwks(), signing_key.jwks())

    def test_the_published_document_holds_only_public_members(self) -> None:
        client, _ = self.build_federating_client()

        published = client.jwks()

        self.assertEqual(len(published["keys"]), 1)
        self.assertEqual(
            set(published["keys"][0]), {"kty", "use", "alg", "kid", "n", "e"}
        )

    def test_federation_details_report_what_to_register(self) -> None:
        client, signing_key = self.build_federating_client()

        details = client.federation_details()

        self.assertEqual(details["issuer"], "https://portal.acme.example")
        self.assertEqual(details["audience"], "ai-ecosystem")
        self.assertEqual(
            details["jwks_url"], "https://portal.acme.example/.well-known/jwks.json"
        )
        self.assertEqual(details["kid"], signing_key.kid)

    def test_it_reports_a_missing_issuer_rather_than_an_empty_document(self) -> None:
        client, _ = self.build_client({})

        with self.assertRaises(IOCloudFederationError):
            client.jwks()
        with self.assertRaises(IOCloudFederationError):
            client.federation_details()

    def test_publishing_and_exchanging_need_no_partner_credentials(self) -> None:
        # A partner publishes keys and federates logins before it has API
        # credentials; only partner-authenticated calls require them.
        signing_key = FederationSigningKey.generate()
        transport = RecordingTransport(
            {"/v1/federation/token": httpx.Response(200, json=SESSION_BODY)}
        )
        http_client = httpx.Client(transport=httpx.MockTransport(transport.handler))
        self.addCleanup(http_client.close)
        client = IOCloudClient(
            client_id="",
            client_secret="",
            base_url=BASE_URL,
            http_client=http_client,
            token_issuer=SubjectTokenIssuer(
                signing_key=signing_key,
                issuer="https://portal.acme.example",
                audience="ai-ecosystem",
            ),
        )

        self.assertEqual(client.jwks(), signing_key.jwks())
        session = client.federated_login(
            subject="acme-user-1", external_tenant_id="acme-tenant-1"
        )
        self.assertEqual(session.access_token, "platform-session-token")

        with self.assertRaises(ValueError):
            client.issue_partner_token()


class FederatedLoginTests(ClientFederationTestCase):
    def test_it_signs_and_exchanges_in_one_call(self) -> None:
        signing_key = FederationSigningKey.generate()
        issuer = SubjectTokenIssuer(
            signing_key=signing_key,
            issuer="https://portal.acme.example",
            audience="ai-ecosystem",
        )
        client, transport = self.build_client(
            {"/v1/federation/token": httpx.Response(200, json=SESSION_BODY)},
            token_issuer=issuer,
        )

        session = client.federated_login(
            subject="acme-user-1",
            external_tenant_id="acme-tenant-1",
            email="user@customer.example",
            name="Test User",
            email_verified=True,
        )

        form = parse_qs(transport.request_to("/v1/federation/token").content.decode())
        claims = jwt.decode(
            form["subject_token"][0],
            jwt.PyJWK(signing_key.public_jwk(), algorithm="RS256").key,
            algorithms=["RS256"],
            audience="ai-ecosystem",
            issuer="https://portal.acme.example",
        )
        self.assertEqual(claims["sub"], "acme-user-1")
        self.assertEqual(claims["tenant_id"], "acme-tenant-1")
        self.assertTrue(claims["email_verified"])
        self.assertEqual(session.access_token, "platform-session-token")

    def test_it_reports_a_missing_issuer_instead_of_failing_at_the_api(self) -> None:
        client, transport = self.build_client({})

        with self.assertRaises(IOCloudFederationError):
            client.federated_login(subject="user-1", external_tenant_id="tenant-1")

        self.assertEqual(transport.requests, [])


if __name__ == "__main__":
    unittest.main()
