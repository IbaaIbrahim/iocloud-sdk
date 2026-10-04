import json
import unittest
import warnings
from datetime import datetime, timedelta, timezone
from pathlib import Path
from urllib.parse import parse_qs

import httpx
import jwt

from iocloud_sdk import (
    JWT_TOKEN_TYPE,
    TOKEN_EXCHANGE_GRANT_TYPE,
    IdentityProvider,
    IOCloudClient,
    IOCloudFederationError,
    IOCloudTokenExchangeError,
    SubjectTokenClaimNames,
    TenantProfile,
)
from iocloud_sdk.federation import FederationSigningKey, SubjectTokenIssuer

BASE_URL = "https://api.example.com"
APPLICATION_UUID = "11111111-1111-4111-8111-111111111111"
TENANT_UUID = "22222222-2222-4222-8222-222222222222"
TENANT_NOT_CREATED = "The token's tenant could not be created."
TENANT_PLAN_MISSING = "The token's tenant plan does not exist."
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
# A platform that stores the key-set location as issuer_origin + jwks_path and
# still sends the derived jwks_url beside them; the next one drops jwks_url.
CURRENT_PROVIDER_BODY = {
    **PROVIDER_BODY,
    "issuer_origin": "https://portal.acme.example",
    "jwks_path": "/.well-known/jwks.json",
    "allow_jit_tenants": False,
}
# The shape every SDK is held to, shared across the three packages.
CONTRACT_FIXTURE = (
    Path(__file__).resolve().parents[3]
    / "contracts"
    / "fixtures"
    / "identity-provider.json"
)
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
    def test_it_sends_the_default_jwks_path_and_no_jwks_url(self) -> None:
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
        self.assertEqual(body["jwks_path"], "/.well-known/jwks.json")
        self.assertNotIn("jwks_url", body)
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

    def test_it_sends_allow_jit_tenants_false_by_default(self) -> None:
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
        )

        body = json.loads(
            transport.request_to("/v1/partner/federation/providers").content
        )
        self.assertIs(body["allow_jit_tenants"], False)

    def test_it_sends_allow_jit_tenants_when_asked_and_reads_it_back(self) -> None:
        client, transport = self.build_client(
            {
                "/v1/partner/auth/token": httpx.Response(200, json=PARTNER_TOKEN_BODY),
                "/v1/partner/federation/providers": httpx.Response(
                    201,
                    json={
                        "data": {
                            "provider": {**PROVIDER_BODY, "allow_jit_tenants": True}
                        }
                    },
                ),
            }
        )

        provider = client.create_identity_provider(
            application_uuid=APPLICATION_UUID,
            name="Acme Portal",
            issuer="https://portal.acme.example",
            allowed_audiences=["ai-ecosystem"],
            allow_jit_users=True,
            allow_jit_tenants=True,
        )

        body = json.loads(
            transport.request_to("/v1/partner/federation/providers").content
        )
        self.assertIs(body["allow_jit_users"], True)
        self.assertIs(body["allow_jit_tenants"], True)
        self.assertTrue(provider.allow_jit_tenants)

    def test_a_provider_without_allow_jit_tenants_reads_as_false(self) -> None:
        # PROVIDER_BODY is what a platform that predates just-in-time tenants
        # sends: no allow_jit_tenants member at all.
        client, _ = self.build_client(
            {
                "/v1/partner/auth/token": httpx.Response(200, json=PARTNER_TOKEN_BODY),
                "/v1/partner/federation/providers": httpx.Response(
                    200, json={"data": {"providers": [PROVIDER_BODY]}}
                ),
            }
        )

        providers = client.list_identity_providers()

        self.assertIs(providers[0].allow_jit_tenants, False)

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


class JwksPathTests(ClientFederationTestCase):
    """The platform takes ``jwks_path`` on the issuer's origin; a ``jwks_url`` is a 422."""

    def build_registering_client(self) -> tuple[IOCloudClient, RecordingTransport]:
        return self.build_client(
            {
                "/v1/partner/auth/token": httpx.Response(200, json=PARTNER_TOKEN_BODY),
                "/v1/partner/federation/providers": httpx.Response(
                    201, json={"data": {"provider": PROVIDER_BODY}}
                ),
            }
        )

    def sent_body(self, transport: RecordingTransport) -> dict:
        return json.loads(
            transport.request_to("/v1/partner/federation/providers").content
        )

    def test_it_sends_the_jwks_path_it_is_given(self) -> None:
        client, transport = self.build_registering_client()

        client.create_identity_provider(
            application_uuid=APPLICATION_UUID,
            name="Acme Portal",
            issuer="https://portal.acme.example",
            jwks_path="/.well-known/acme/keys.json",
            allowed_audiences=["ai-ecosystem"],
        )

        body = self.sent_body(transport)
        self.assertEqual(body["jwks_path"], "/.well-known/acme/keys.json")
        self.assertNotIn("jwks_url", body)

    def test_registering_a_subject_token_issuer_sends_its_jwks_path(self) -> None:
        token_issuer = SubjectTokenIssuer(
            signing_key=FederationSigningKey.generate(),
            issuer="https://portal.acme.example",
            audience="ai-ecosystem",
        )
        client, transport = self.build_registering_client()

        client.create_identity_provider(
            application_uuid=APPLICATION_UUID,
            name="Acme Portal",
            issuer=token_issuer.issuer,
            jwks_path=token_issuer.jwks_path,
            allowed_audiences=[token_issuer.audience],
            claim_names=token_issuer.claim_names,
        )

        body = self.sent_body(transport)
        self.assertEqual(body["jwks_path"], "/.well-known/jwks.json")
        self.assertNotIn("jwks_url", body)

    def test_a_deprecated_jwks_url_on_the_issuers_origin_is_sent_as_its_path(
        self,
    ) -> None:
        client, transport = self.build_registering_client()

        with self.assertWarns(DeprecationWarning):
            client.create_identity_provider(
                application_uuid=APPLICATION_UUID,
                name="Acme Portal",
                issuer="https://portal.acme.example",
                # Same origin, spelled differently: case and the default port.
                jwks_url="HTTPS://Portal.Acme.Example:443/.well-known/acme/keys.json",
                allowed_audiences=["ai-ecosystem"],
            )

        body = self.sent_body(transport)
        self.assertEqual(body["jwks_path"], "/.well-known/acme/keys.json")
        self.assertNotIn("jwks_url", body)

    def test_a_subject_token_issuers_jwks_url_still_registers_as_its_path(
        self,
    ) -> None:
        # What the README showed before jwks_path: it keeps working, deprecated.
        token_issuer = SubjectTokenIssuer(
            signing_key=FederationSigningKey.generate(),
            issuer="https://portal.acme.example",
            audience="ai-ecosystem",
        )
        client, transport = self.build_registering_client()

        with self.assertWarns(DeprecationWarning):
            client.create_identity_provider(
                application_uuid=APPLICATION_UUID,
                name="Acme Portal",
                issuer=token_issuer.issuer,
                jwks_url=token_issuer.jwks_url,
                allowed_audiences=[token_issuer.audience],
            )

        body = self.sent_body(transport)
        self.assertEqual(body["jwks_path"], token_issuer.jwks_path)
        self.assertNotIn("jwks_url", body)

    def test_a_deprecated_jwks_url_off_the_issuers_origin_is_refused(self) -> None:
        off_origin = {
            "another host": "https://keys.example.net/.well-known/jwks.json",
            "a subdomain": "https://keys.portal.acme.example/.well-known/jwks.json",
            "another scheme": "http://portal.acme.example/.well-known/jwks.json",
            "another port": "https://portal.acme.example:8443/.well-known/jwks.json",
            "not a URL": "/.well-known/jwks.json",
        }
        for case, jwks_url in off_origin.items():
            with self.subTest(case):
                client, transport = self.build_registering_client()

                with self.assertWarns(DeprecationWarning):
                    with self.assertRaisesRegex(ValueError, "issuer's origin"):
                        client.create_identity_provider(
                            application_uuid=APPLICATION_UUID,
                            name="Acme Portal",
                            issuer="https://portal.acme.example",
                            jwks_url=jwks_url,
                            allowed_audiences=["ai-ecosystem"],
                        )

                self.assertEqual(transport.requests, [])

    def test_a_deprecated_jwks_url_with_a_query_or_fragment_is_refused(self) -> None:
        for jwks_url in (
            "https://portal.acme.example/.well-known/jwks.json?tenant=acme",
            "https://portal.acme.example/.well-known/jwks.json#keys",
        ):
            with self.subTest(jwks_url):
                client, transport = self.build_registering_client()

                with self.assertWarns(DeprecationWarning):
                    with self.assertRaisesRegex(ValueError, "query or a fragment"):
                        client.create_identity_provider(
                            application_uuid=APPLICATION_UUID,
                            name="Acme Portal",
                            issuer="https://portal.acme.example",
                            jwks_url=jwks_url,
                            allowed_audiences=["ai-ecosystem"],
                        )

                self.assertEqual(transport.requests, [])

    def test_jwks_path_and_jwks_url_together_are_refused(self) -> None:
        client, transport = self.build_registering_client()

        with self.assertWarns(DeprecationWarning):
            with self.assertRaisesRegex(ValueError, "not both"):
                client.create_identity_provider(
                    application_uuid=APPLICATION_UUID,
                    name="Acme Portal",
                    issuer="https://portal.acme.example",
                    jwks_path="/.well-known/jwks.json",
                    jwks_url="https://portal.acme.example/.well-known/jwks.json",
                    allowed_audiences=["ai-ecosystem"],
                )

        self.assertEqual(transport.requests, [])

    def test_no_deprecation_warning_without_jwks_url(self) -> None:
        client, _ = self.build_registering_client()

        with warnings.catch_warnings():
            warnings.simplefilter("error", DeprecationWarning)
            client.create_identity_provider(
                application_uuid=APPLICATION_UUID,
                name="Acme Portal",
                issuer="https://portal.acme.example",
                allowed_audiences=["ai-ecosystem"],
            )


class IdentityProviderShapeTests(unittest.TestCase):
    """Every platform's identity provider parses, with or without ``jwks_url``."""

    def test_the_contract_fixture_parses_without_jwks_url(self) -> None:
        payload = json.loads(CONTRACT_FIXTURE.read_text(encoding="utf-8"))
        provider_payload = payload["data"]["provider"]
        self.assertNotIn("jwks_url", provider_payload)

        provider = IdentityProvider.from_payload(provider_payload)

        self.assertEqual(provider.issuer_origin, "https://portal.acme.example")
        self.assertEqual(provider.jwks_path, "/.well-known/jwks.json")
        self.assertEqual(
            provider.jwks_url, "https://portal.acme.example/.well-known/jwks.json"
        )

    def test_a_platform_without_jwks_url_derives_it_from_origin_and_path(
        self,
    ) -> None:
        payload = {
            key: value
            for key, value in CURRENT_PROVIDER_BODY.items()
            if key != "jwks_url"
        }
        payload["jwks_path"] = "/.well-known/acme/keys.json"

        provider = IdentityProvider.from_payload(payload)

        self.assertEqual(provider.issuer_origin, "https://portal.acme.example")
        self.assertEqual(provider.jwks_path, "/.well-known/acme/keys.json")
        self.assertEqual(
            provider.jwks_url, "https://portal.acme.example/.well-known/acme/keys.json"
        )

    def test_a_platform_that_still_sends_jwks_url_parses_unchanged(self) -> None:
        provider = IdentityProvider.from_payload(CURRENT_PROVIDER_BODY)

        self.assertEqual(provider.issuer_origin, "https://portal.acme.example")
        self.assertEqual(provider.jwks_path, "/.well-known/jwks.json")
        self.assertEqual(
            provider.jwks_url, "https://portal.acme.example/.well-known/jwks.json"
        )

    def test_a_platform_from_before_jwks_path_derives_origin_and_path(self) -> None:
        # PROVIDER_BODY carries jwks_url alone, as the SDK first knew it.
        provider = IdentityProvider.from_payload(PROVIDER_BODY)

        self.assertEqual(provider.issuer_origin, "https://portal.acme.example")
        self.assertEqual(provider.jwks_path, "/.well-known/jwks.json")
        self.assertEqual(provider.jwks_url, PROVIDER_BODY["jwks_url"])

    def test_a_payload_naming_no_jwks_location_at_all_is_refused(self) -> None:
        payload = {
            key: value
            for key, value in CURRENT_PROVIDER_BODY.items()
            if key not in {"jwks_url", "jwks_path"}
        }

        with self.assertRaises(KeyError):
            IdentityProvider.from_payload(payload)


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

    def test_it_reads_the_tenant_and_whether_this_login_created_it(self) -> None:
        client, _ = self.build_client(
            {
                "/v1/federation/token": httpx.Response(
                    200,
                    json={
                        **SESSION_BODY,
                        "tenant_uuid": TENANT_UUID,
                        "tenant_created": True,
                    },
                )
            }
        )

        session = client.exchange_subject_token(subject_token="signed.jwt.value")

        self.assertEqual(str(session.tenant_uuid), TENANT_UUID)
        self.assertIs(session.tenant_created, True)

    def test_a_platform_without_the_tenant_members_reads_as_none_and_false(
        self,
    ) -> None:
        # SESSION_BODY is what a platform that predates just-in-time tenants
        # sends: neither tenant_uuid nor tenant_created.
        client, _ = self.build_client(
            {"/v1/federation/token": httpx.Response(200, json=SESSION_BODY)}
        )

        session = client.exchange_subject_token(subject_token="signed.jwt.value")

        self.assertIsNone(session.tenant_uuid)
        self.assertIs(session.tenant_created, False)

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
        self.assertEqual(details["jwks_path"], "/.well-known/jwks.json")
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
        session = client.exchange_subject_token(
            subject_token=client.federated_login(
                subject="acme-user-1", external_tenant_id="acme-tenant-1"
            )
        )
        self.assertEqual(session.access_token, "platform-session-token")

        with self.assertRaises(ValueError):
            client.issue_partner_token()


def _claims_of(subject_token: str, signing_key: FederationSigningKey) -> dict:
    """A token's claims, verified the way the platform verifies them."""
    return jwt.decode(
        subject_token,
        jwt.PyJWK(signing_key.public_jwk(), algorithm="RS256").key,
        algorithms=["RS256"],
        audience="ai-ecosystem",
        issuer="https://portal.acme.example",
    )


class FederatedLoginTests(ClientFederationTestCase):
    def signing_client(
        self, routes: dict[str, httpx.Response] | None = None
    ) -> tuple[IOCloudClient, RecordingTransport, FederationSigningKey]:
        signing_key = FederationSigningKey.generate()
        client, transport = self.build_client(
            routes or {},
            token_issuer=SubjectTokenIssuer(
                signing_key=signing_key,
                issuer="https://portal.acme.example",
                audience="ai-ecosystem",
            ),
        )
        return client, transport, signing_key

    def test_it_returns_the_signed_subject_token_and_sends_nothing(self) -> None:
        client, transport, signing_key = self.signing_client()

        subject_token = client.federated_login(
            subject="acme-user-1",
            external_tenant_id="acme-tenant-1",
            email="user@customer.example",
            name="Test User",
            email_verified=True,
        )

        claims = _claims_of(subject_token, signing_key)
        self.assertEqual(claims["sub"], "acme-user-1")
        self.assertEqual(claims["tenant_id"], "acme-tenant-1")
        self.assertTrue(claims["email_verified"])
        self.assertNotIn("tenant_profile", claims)
        self.assertEqual(transport.requests, [], "the frontend exchanges it, not the SDK")

    def test_the_tenant_profile_is_signed_into_the_token(self) -> None:
        client, _, signing_key = self.signing_client()

        subject_token = client.federated_login(
            subject="acme-user-1",
            external_tenant_id="acme-tenant-1",
            email="user@customer.example",
            tenant=TenantProfile(name="Acme Ltd", contact_email="ops@acme.example"),
        )

        claims = _claims_of(subject_token, signing_key)
        self.assertEqual(claims["tenant_id"], "acme-tenant-1")
        self.assertEqual(
            claims["tenant_profile"],
            {"name": "Acme Ltd", "contact_email": "ops@acme.example"},
        )

    def test_a_tenant_profile_carrying_the_external_tenant_id_names_the_tenant(
        self,
    ) -> None:
        client, _, signing_key = self.signing_client()

        subject_token = client.federated_login(
            subject="acme-user-1",
            email="user@customer.example",
            tenant=TenantProfile(name="Acme Ltd", external_tenant_id="acme-tenant-1"),
        )

        claims = _claims_of(subject_token, signing_key)
        self.assertEqual(claims["tenant_id"], "acme-tenant-1")
        self.assertEqual(claims["tenant_profile"], {"name": "Acme Ltd"})

    def test_a_backend_that_wants_the_session_exchanges_the_token_itself(self) -> None:
        client, transport, _ = self.signing_client(
            {
                "/v1/federation/token": httpx.Response(
                    200,
                    json={**SESSION_BODY, "tenant_uuid": TENANT_UUID, "tenant_created": True},
                )
            }
        )

        subject_token = client.federated_login(
            subject="acme-user-1",
            external_tenant_id="acme-tenant-1",
            email="user@customer.example",
            tenant=TenantProfile(name="Acme Ltd"),
        )
        session = client.exchange_subject_token(subject_token=subject_token)

        form = parse_qs(transport.request_to("/v1/federation/token").content.decode())
        self.assertEqual(form["subject_token"][0], subject_token)
        self.assertEqual(session.access_token, "platform-session-token")
        self.assertEqual(str(session.tenant_uuid), TENANT_UUID)
        self.assertIs(session.tenant_created, True)

    def test_a_tenant_the_platform_could_not_create_refuses_the_exchange(self) -> None:
        client, _, _ = self.signing_client(
            {
                "/v1/federation/token": httpx.Response(
                    400,
                    json={
                        "error": "invalid_target",
                        "error_description": TENANT_NOT_CREATED,
                    },
                )
            }
        )
        subject_token = client.federated_login(
            subject="acme-user-1",
            external_tenant_id="acme-tenant-1",
            email="user@customer.example",
            tenant=TenantProfile(name="Acme Ltd"),
        )

        with self.assertRaises(IOCloudTokenExchangeError) as raised:
            client.exchange_subject_token(subject_token=subject_token)

        self.assertEqual(raised.exception.status_code, 400)
        self.assertEqual(raised.exception.error, "invalid_target")
        self.assertEqual(raised.exception.error_description, TENANT_NOT_CREATED)

    def test_a_plan_code_no_plan_has_refuses_the_exchange(self) -> None:
        client, _, signing_key = self.signing_client(
            {
                "/v1/federation/token": httpx.Response(
                    400,
                    json={
                        "error": "invalid_target",
                        "error_description": TENANT_PLAN_MISSING,
                    },
                )
            }
        )
        subject_token = client.federated_login(
            subject="acme-user-1",
            external_tenant_id="acme-tenant-1",
            email="user@customer.example",
            tenant=TenantProfile(name="Acme Ltd", plan_code="no-such-plan"),
        )
        self.assertEqual(
            _claims_of(subject_token, signing_key)["tenant_profile"]["plan_code"],
            "no-such-plan",
        )

        with self.assertRaises(IOCloudTokenExchangeError) as raised:
            client.exchange_subject_token(subject_token=subject_token)

        self.assertEqual(raised.exception.status_code, 400)
        self.assertEqual(raised.exception.error, "invalid_target")
        self.assertEqual(raised.exception.error_description, TENANT_PLAN_MISSING)

    def test_it_reports_a_missing_issuer_instead_of_failing_at_the_api(self) -> None:
        client, transport = self.build_client({})

        with self.assertRaises(IOCloudFederationError):
            client.federated_login(subject="user-1", external_tenant_id="tenant-1")

        self.assertEqual(transport.requests, [])

if __name__ == "__main__":
    unittest.main()
