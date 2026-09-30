import unittest

import jwt

from iocloud_sdk import IOCloudFederationError, SubjectTokenClaimNames, TenantProfile
from iocloud_sdk.federation import (
    SIGNING_ALGORITHM,
    FederationSigningKey,
    SubjectTokenIssuer,
    build_jwks,
)

ISSUER = "https://portal.acme.example"
AUDIENCE = "ai-ecosystem"
TENANT_PROFILE = TenantProfile(name="Acme Ltd", contact_email="ops@acme.example")


def signing_key() -> FederationSigningKey:
    return FederationSigningKey.generate()


def token_issuer(key: FederationSigningKey, **overrides) -> SubjectTokenIssuer:
    options = {
        "signing_key": key,
        "issuer": ISSUER,
        "audience": AUDIENCE,
        "token_ttl_seconds": 300,
    }
    options.update(overrides)
    return SubjectTokenIssuer(**options)


class FederationSigningKeyTests(unittest.TestCase):
    def test_public_jwk_carries_the_fields_a_verifier_needs(self) -> None:
        jwk = signing_key().public_jwk()

        self.assertEqual(jwk["kty"], "RSA")
        self.assertEqual(jwk["use"], "sig")
        self.assertEqual(jwk["alg"], SIGNING_ALGORITHM)
        self.assertEqual(jwk["e"], "AQAB")
        self.assertNotIn("=", jwk["n"])
        self.assertTrue(jwk["kid"])

    def test_jwks_publishes_only_the_public_half(self) -> None:
        jwks = signing_key().jwks()

        self.assertEqual(len(jwks["keys"]), 1)
        self.assertEqual(set(jwks["keys"][0]), {"kty", "use", "alg", "kid", "n", "e"})

    def test_kid_is_derived_from_the_key_so_it_survives_a_reload(self) -> None:
        key = signing_key()

        reloaded = FederationSigningKey.from_private_key_pem(key.private_key_pem)

        self.assertEqual(reloaded.kid, key.kid)
        self.assertEqual(reloaded.public_jwk(), key.public_jwk())

    def test_distinct_keys_get_distinct_kids(self) -> None:
        self.assertNotEqual(signing_key().kid, signing_key().kid)

    def test_repr_does_not_leak_the_private_key(self) -> None:
        key = signing_key()

        self.assertNotIn("PRIVATE", repr(key))
        self.assertIn(key.kid, repr(key))

    def test_the_public_key_pem_verifies_what_the_private_half_signed(self) -> None:
        key = signing_key()

        public_key_pem = key.public_key_pem

        self.assertIn("BEGIN PUBLIC KEY", public_key_pem)
        self.assertNotIn("PRIVATE", public_key_pem)
        token = key.sign({"sub": "user-1", "iat": 1783589342, "exp": 4102444800})
        claims = jwt.decode(token, public_key_pem, algorithms=[SIGNING_ALGORITHM])
        self.assertEqual(claims["sub"], "user-1")

    def test_loading_a_non_pem_value_reports_a_federation_error(self) -> None:
        with self.assertRaises(IOCloudFederationError):
            FederationSigningKey.from_private_key_pem("not-a-key")

    def test_build_jwks_publishes_every_key_in_a_rotation(self) -> None:
        current, retiring = signing_key(), signing_key()

        jwks = build_jwks(current, retiring)

        self.assertEqual(
            [key["kid"] for key in jwks["keys"]], [current.kid, retiring.kid]
        )

    def test_build_jwks_rejects_an_empty_key_set(self) -> None:
        with self.assertRaises(IOCloudFederationError):
            build_jwks()


class SubjectTokenIssuerTests(unittest.TestCase):
    def setUp(self) -> None:
        self.key = signing_key()
        self.issuer = token_issuer(self.key)

    def decode(self, token: str, **overrides) -> dict:
        options = {
            "key": jwt.PyJWK(self.key.public_jwk(), algorithm=SIGNING_ALGORITHM).key,
            "algorithms": [SIGNING_ALGORITHM],
            "audience": AUDIENCE,
            "issuer": ISSUER,
        }
        options.update(overrides)
        return jwt.decode(token, **options)

    def test_token_verifies_against_the_published_jwks(self) -> None:
        token = self.issuer.issue(subject="user-1", external_tenant_id="tenant-1")

        claims = self.decode(token)

        self.assertEqual(claims["sub"], "user-1")
        self.assertEqual(claims["tenant_id"], "tenant-1")

    def test_token_header_names_the_published_kid(self) -> None:
        token = self.issuer.issue(subject="user-1", external_tenant_id="tenant-1")

        header = jwt.get_unverified_header(token)

        self.assertEqual(header["alg"], SIGNING_ALGORITHM)
        self.assertEqual(header["kid"], self.key.kid)

    def test_claims_cover_the_platform_validation_rules(self) -> None:
        token = self.issuer.issue(
            subject="user-1",
            external_tenant_id="tenant-1",
            email="user@customer.example",
            name="Test User",
            email_verified=True,
        )

        claims = self.decode(token)

        self.assertEqual(claims["email"], "user@customer.example")
        self.assertTrue(claims["email_verified"])
        self.assertEqual(claims["name"], "Test User")
        self.assertEqual(claims["exp"] - claims["iat"], 300)
        self.assertEqual(claims["nbf"], claims["iat"])

    def test_every_token_gets_a_unique_jti_for_replay_protection(self) -> None:
        first = self.decode(
            self.issuer.issue(subject="user-1", external_tenant_id="tenant-1")
        )
        second = self.decode(
            self.issuer.issue(subject="user-1", external_tenant_id="tenant-1")
        )

        self.assertNotEqual(first["jti"], second["jti"])

    def test_email_verified_is_absent_when_no_email_is_supplied(self) -> None:
        claims = self.decode(
            self.issuer.issue(subject="user-1", external_tenant_id="tenant-1")
        )

        self.assertNotIn("email", claims)
        self.assertNotIn("email_verified", claims)

    def test_trailing_slash_is_stripped_so_iss_matches_byte_for_byte(self) -> None:
        issuer = token_issuer(self.key, issuer=ISSUER + "/")

        self.assertEqual(issuer.issuer, ISSUER)
        self.assertEqual(issuer.jwks_url, f"{ISSUER}/.well-known/jwks.json")

    def test_claim_names_are_configurable_per_provider(self) -> None:
        issuer = self.issuer.with_claim_names(
            SubjectTokenClaimNames(user="user_id", tenant="org_id")
        )

        claims = self.decode(
            issuer.issue(subject="user-1", external_tenant_id="org-1")
        )

        self.assertEqual(claims["user_id"], "user-1")
        self.assertEqual(claims["org_id"], "org-1")
        self.assertNotIn("sub", claims)

    def test_extra_claims_are_added_to_the_token(self) -> None:
        claims = self.decode(
            self.issuer.issue(
                subject="user-1",
                external_tenant_id="tenant-1",
                extra_claims={"scope": "jobs:create"},
            )
        )

        self.assertEqual(claims["scope"], "jobs:create")

    def test_extra_claims_cannot_override_issuer_controlled_claims(self) -> None:
        for reserved in ("iss", "aud", "exp", "iat", "nbf", "jti"):
            with self.subTest(claim=reserved):
                with self.assertRaises(IOCloudFederationError):
                    self.issuer.issue(
                        subject="user-1",
                        external_tenant_id="tenant-1",
                        extra_claims={reserved: "attacker"},
                    )

    def test_a_tenant_profile_is_signed_as_the_tenant_profile_claim(self) -> None:
        claims = self.decode(
            self.issuer.issue(
                subject="user-1", external_tenant_id="tenant-1", tenant=TENANT_PROFILE
            )
        )

        self.assertEqual(
            claims["tenant_profile"],
            {"name": "Acme Ltd", "contact_email": "ops@acme.example"},
        )
        self.assertEqual(claims["tenant_id"], "tenant-1")

    def test_a_profile_without_a_contact_email_is_signed_without_one(self) -> None:
        claims = self.decode(
            self.issuer.issue(
                subject="user-1",
                external_tenant_id="tenant-1",
                tenant=TenantProfile(name="Acme Ltd"),
            )
        )

        self.assertEqual(claims["tenant_profile"], {"name": "Acme Ltd"})

    def test_a_plan_code_is_signed_into_the_tenant_profile(self) -> None:
        claims = self.decode(
            self.issuer.issue(
                subject="user-1",
                external_tenant_id="tenant-1",
                tenant=TenantProfile(
                    name="Acme Ltd",
                    contact_email="ops@acme.example",
                    plan_code="Growth-2026",
                ),
            )
        )

        self.assertEqual(
            claims["tenant_profile"],
            {
                "name": "Acme Ltd",
                "contact_email": "ops@acme.example",
                "plan_code": "Growth-2026",
            },
        )

    def test_a_profile_without_a_plan_code_is_signed_without_one(self) -> None:
        claims = self.decode(
            self.issuer.issue(
                subject="user-1",
                external_tenant_id="tenant-1",
                tenant=TenantProfile(name="Acme Ltd", plan_code=None),
            )
        )

        self.assertNotIn("plan_code", claims["tenant_profile"])

    def test_no_tenant_profile_claim_is_signed_without_a_profile(self) -> None:
        claims = self.decode(
            self.issuer.issue(subject="user-1", external_tenant_id="tenant-1")
        )

        self.assertNotIn("tenant_profile", claims)

    def test_the_claim_mapping_never_renames_the_tenant_profile(self) -> None:
        issuer = self.issuer.with_claim_names(
            SubjectTokenClaimNames(user="user_id", tenant="org_id")
        )

        claims = self.decode(
            issuer.issue(
                subject="user-1", external_tenant_id="org-1", tenant=TENANT_PROFILE
            )
        )

        self.assertEqual(claims["org_id"], "org-1")
        self.assertEqual(claims["tenant_profile"], TENANT_PROFILE.to_claim())

    def test_extra_claims_can_neither_set_nor_override_the_tenant_profile(self) -> None:
        smuggled = {"tenant_profile": {"name": "Other"}}

        with self.assertRaises(IOCloudFederationError):
            self.issuer.issue(
                subject="user-1", external_tenant_id="tenant-1", extra_claims=smuggled
            )
        with self.assertRaises(IOCloudFederationError):
            self.issuer.issue(
                subject="user-1",
                external_tenant_id="tenant-1",
                extra_claims=smuggled,
                tenant=TENANT_PROFILE,
            )

    def test_blank_tenant_profile_values_are_rejected_at_the_boundary(self) -> None:
        blank_profiles = {
            "name": TenantProfile(name=" ", contact_email="ops@acme.example"),
            "contact_email": TenantProfile(name="Acme Ltd", contact_email="  "),
            "plan_code": TenantProfile(name="Acme Ltd", plan_code="  "),
        }
        for blank_field, profile in blank_profiles.items():
            with self.subTest(field=blank_field):
                with self.assertRaises(IOCloudFederationError) as raised:
                    self.issuer.issue(
                        subject="user-1", external_tenant_id="tenant-1", tenant=profile
                    )
                self.assertIn(blank_field, str(raised.exception))

    def test_blank_identity_values_are_rejected_at_the_boundary(self) -> None:
        with self.assertRaises(IOCloudFederationError):
            self.issuer.issue(subject="  ", external_tenant_id="tenant-1")
        with self.assertRaises(IOCloudFederationError):
            self.issuer.issue(subject="user-1", external_tenant_id="")

    def test_issuer_configuration_is_validated_on_construction(self) -> None:
        with self.assertRaises(IOCloudFederationError):
            token_issuer(self.key, issuer=" ")
        with self.assertRaises(IOCloudFederationError):
            token_issuer(self.key, audience="")
        with self.assertRaises(IOCloudFederationError):
            token_issuer(self.key, token_ttl_seconds=0)


if __name__ == "__main__":
    unittest.main()
