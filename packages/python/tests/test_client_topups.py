import json
import unittest

import httpx

from iocloud_sdk import IOCloudClient

_PARTNER_TOKEN = {
    "data": {
        "token": {
            "access_token": "partner-token",
            "token_type": "Bearer",
            "expires_at": "2099-01-01T00:00:00Z",
        }
    }
}

_BRONZE_PLAN_UUID = "3f1b1f70-0000-4000-8000-0000000000b1"
_SILVER_PLAN_UUID = "3f1b1f70-0000-4000-8000-0000000000b2"
_PACKAGE_UUID = "3f1b1f70-0000-4000-8000-0000000000c1"
_TENANT_UUID = "3f1b1f70-0000-4000-8000-0000000000d1"
_PURCHASE_UUID = "3f1b1f70-0000-4000-8000-0000000000e1"

_PACKAGE = {
    "uuid": _PACKAGE_UUID,
    "name": "Booster 5K",
    "credits": 5000,
    "price_cents": 4900,
    "validity_days": 90,
    "definer_type": "partners",
    "audience": "tenants",
    "status": "active",
    "created_at": "2026-08-17T00:00:00Z",
    "updated_at": "2026-08-17T00:00:00Z",
    "plans": [
        {
            "plan_type": "tenant_plans",
            "plan_uuid": _BRONZE_PLAN_UUID,
            "plan_name": "Bronze",
        }
    ],
}

_PURCHASE = {
    "uuid": _PURCHASE_UUID,
    "package_uuid": _PACKAGE_UUID,
    "package_name": "Booster 5K",
    "tenant_uuid": _TENANT_UUID,
    "tenant_name": "Acme Ltd",
    "credits": 5000,
    "valid_from": "2026-08-17T00:00:00+00:00",
    "valid_to": "2026-11-15T00:00:00+00:00",
    "status": "active",
    "payment_transaction_uuid": None,
    "created_at": "2026-08-17T00:00:00Z",
}


def _client(handler) -> tuple[IOCloudClient, httpx.Client]:
    http_client = httpx.Client(transport=httpx.MockTransport(handler))
    return (
        IOCloudClient(
            client_id="client-id",
            client_secret="client-secret",
            base_url="https://api.example.com/",
            http_client=http_client,
        ),
        http_client,
    )


class TopupPackageTests(unittest.TestCase):
    def test_list_topup_packages_unwraps_the_paginated_envelope(self) -> None:
        def handler(request: httpx.Request) -> httpx.Response:
            if request.url.path == "/v1/partner/auth/token":
                return httpx.Response(200, json=_PARTNER_TOKEN)
            self.assertEqual(request.url.path, "/v1/partner/topup-packages")
            self.assertEqual(request.url.params.get("limit"), "50")
            return httpx.Response(
                200,
                json={
                    "data": {
                        "list": [_PACKAGE],
                        "pagination": {
                            "page": 1,
                            "total_pages": 1,
                            "limit": 50,
                            "total": 1,
                        },
                    }
                },
            )

        client, http_client = _client(handler)
        with http_client:
            packages = client.list_topup_packages(limit=50)

        self.assertEqual(len(packages), 1)
        package = packages[0]
        self.assertEqual(package.name, "Booster 5K")
        self.assertEqual(package.credits, 5000)
        self.assertEqual(package.validity_days, 90)
        self.assertTrue(package.is_active)
        self.assertEqual(len(package.plans), 1)
        self.assertEqual(package.plans[0].plan_name, "Bronze")
        self.assertFalse(package.is_offered_to_every_plan)

    def test_a_package_with_no_plans_is_offered_to_every_plan(self) -> None:
        def handler(request: httpx.Request) -> httpx.Response:
            if request.url.path == "/v1/partner/auth/token":
                return httpx.Response(200, json=_PARTNER_TOKEN)
            return httpx.Response(
                200, json={"data": {"list": [{**_PACKAGE, "plans": []}]}}
            )

        client, http_client = _client(handler)
        with http_client:
            packages = client.list_topup_packages()

        self.assertTrue(packages[0].is_offered_to_every_plan)

    def test_a_package_with_no_expiry_reports_none(self) -> None:
        def handler(request: httpx.Request) -> httpx.Response:
            if request.url.path == "/v1/partner/auth/token":
                return httpx.Response(200, json=_PARTNER_TOKEN)
            return httpx.Response(
                200, json={"data": {"list": [{**_PACKAGE, "validity_days": None}]}}
            )

        client, http_client = _client(handler)
        with http_client:
            packages = client.list_topup_packages()

        self.assertIsNone(packages[0].validity_days)

    def test_create_topup_package_sends_the_plan_scoping(self) -> None:
        sent: dict = {}

        def handler(request: httpx.Request) -> httpx.Response:
            if request.url.path == "/v1/partner/auth/token":
                return httpx.Response(200, json=_PARTNER_TOKEN)
            self.assertEqual(request.method, "POST")
            self.assertEqual(request.url.path, "/v1/partner/topup-packages")
            sent.update(json.loads(request.content))
            return httpx.Response(201, json={"data": {"package": _PACKAGE}})

        client, http_client = _client(handler)
        with http_client:
            package = client.create_topup_package(
                name="Booster 5K",
                credits=5000,
                price_cents=4900,
                validity_days=90,
                plan_uuids=[_BRONZE_PLAN_UUID],
            )

        self.assertEqual(sent["name"], "Booster 5K")
        self.assertEqual(sent["credits"], 5000)
        self.assertEqual(sent["price_cents"], 4900)
        self.assertEqual(sent["validity_days"], 90)
        self.assertEqual(sent["plan_uuids"], [_BRONZE_PLAN_UUID])
        self.assertEqual(str(package.uuid), _PACKAGE_UUID)

    def test_create_without_plans_sends_an_empty_list(self) -> None:
        """An empty list is the "offered to every tenant" case, not an omission."""
        sent: dict = {}

        def handler(request: httpx.Request) -> httpx.Response:
            if request.url.path == "/v1/partner/auth/token":
                return httpx.Response(200, json=_PARTNER_TOKEN)
            sent.update(json.loads(request.content))
            return httpx.Response(201, json={"data": {"package": _PACKAGE}})

        client, http_client = _client(handler)
        with http_client:
            client.create_topup_package(name="Any", credits=10, price_cents=0)

        self.assertEqual(sent["plan_uuids"], [])
        self.assertIsNone(sent["validity_days"])

    def test_update_omits_untouched_fields_but_sends_cleared_plans(self) -> None:
        """`plan_uuids=[]` must reach the API; omitting it must not."""
        sent: dict = {}

        def handler(request: httpx.Request) -> httpx.Response:
            if request.url.path == "/v1/partner/auth/token":
                return httpx.Response(200, json=_PARTNER_TOKEN)
            self.assertEqual(request.method, "PATCH")
            self.assertEqual(
                request.url.path, f"/v1/partner/topup-packages/{_PACKAGE_UUID}"
            )
            sent.update(json.loads(request.content))
            return httpx.Response(200, json={"data": {"package": _PACKAGE}})

        client, http_client = _client(handler)
        with http_client:
            client.update_topup_package(package_uuid=_PACKAGE_UUID, plan_uuids=[])

        self.assertEqual(sent, {"plan_uuids": []})

    def test_update_status_alone_leaves_the_scoping_untouched(self) -> None:
        sent: dict = {}

        def handler(request: httpx.Request) -> httpx.Response:
            if request.url.path == "/v1/partner/auth/token":
                return httpx.Response(200, json=_PARTNER_TOKEN)
            sent.update(json.loads(request.content))
            return httpx.Response(200, json={"data": {"package": _PACKAGE}})

        client, http_client = _client(handler)
        with http_client:
            client.update_topup_package(
                package_uuid=_PACKAGE_UUID, status="inactive"
            )

        self.assertEqual(sent, {"status": "inactive"})
        self.assertNotIn("plan_uuids", sent)


class TenantTopupTests(unittest.TestCase):
    def test_grant_activates_by_default_and_reports_the_pool(self) -> None:
        sent: dict = {}

        def handler(request: httpx.Request) -> httpx.Response:
            if request.url.path == "/v1/partner/auth/token":
                return httpx.Response(200, json=_PARTNER_TOKEN)
            self.assertEqual(request.method, "POST")
            self.assertEqual(
                request.url.path, "/v1/partner/topups/tenant/purchases"
            )
            sent.update(json.loads(request.content))
            return httpx.Response(
                201,
                json={
                    "data": {
                        "purchase": _PURCHASE,
                        "provisioning": {"pool_created": True, "pool_credits": 5000},
                    }
                },
            )

        client, http_client = _client(handler)
        with http_client:
            result = client.grant_tenant_topup(
                tenant_uuid=_TENANT_UUID,
                package_uuid=_PACKAGE_UUID,
                reference="invoice INV-2026-0042",
            )

        self.assertTrue(sent["activate_now"])
        self.assertEqual(sent["reference"], "invoice INV-2026-0042")
        self.assertTrue(result.purchase.is_active)
        self.assertEqual(result.purchase.tenant_name, "Acme Ltd")
        self.assertIsNotNone(result.provisioned)
        assert result.provisioned is not None
        self.assertTrue(result.provisioned.pool_created)
        self.assertEqual(result.provisioned.pool_credits, 5000)

    def test_grant_without_activation_provisions_nothing(self) -> None:
        def handler(request: httpx.Request) -> httpx.Response:
            if request.url.path == "/v1/partner/auth/token":
                return httpx.Response(200, json=_PARTNER_TOKEN)
            self.assertFalse(json.loads(request.content)["activate_now"])
            return httpx.Response(
                201,
                json={
                    "data": {
                        "purchase": {**_PURCHASE, "status": "pending"},
                        "provisioning": None,
                    }
                },
            )

        client, http_client = _client(handler)
        with http_client:
            result = client.grant_tenant_topup(
                tenant_uuid=_TENANT_UUID,
                package_uuid=_PACKAGE_UUID,
                activate_now=False,
            )

        self.assertFalse(result.purchase.is_active)
        self.assertIsNone(result.provisioned)

    def test_activating_an_already_active_topup_provisions_nothing(self) -> None:
        """Activation is idempotent: a retry must not grant the credits twice."""

        def handler(request: httpx.Request) -> httpx.Response:
            if request.url.path == "/v1/partner/auth/token":
                return httpx.Response(200, json=_PARTNER_TOKEN)
            self.assertEqual(
                request.url.path,
                f"/v1/partner/topups/tenant/purchases/{_PURCHASE_UUID}/activate",
            )
            return httpx.Response(
                200, json={"data": {"purchase": _PURCHASE, "provisioning": None}}
            )

        client, http_client = _client(handler)
        with http_client:
            result = client.activate_tenant_topup(transaction_uuid=_PURCHASE_UUID)

        self.assertTrue(result.purchase.is_active)
        self.assertIsNone(result.provisioned)

    def test_list_tenant_topups_unwraps_the_purchases_key(self) -> None:
        def handler(request: httpx.Request) -> httpx.Response:
            if request.url.path == "/v1/partner/auth/token":
                return httpx.Response(200, json=_PARTNER_TOKEN)
            self.assertEqual(
                request.url.path, "/v1/partner/topups/tenant/purchases"
            )
            return httpx.Response(200, json={"data": {"purchases": [_PURCHASE]}})

        client, http_client = _client(handler)
        with http_client:
            purchases = client.list_tenant_topups()

        self.assertEqual(len(purchases), 1)
        self.assertEqual(str(purchases[0].tenant_uuid), _TENANT_UUID)
        self.assertEqual(purchases[0].credits, 5000)

    def test_a_purchase_that_never_expires_reports_no_valid_to(self) -> None:
        def handler(request: httpx.Request) -> httpx.Response:
            if request.url.path == "/v1/partner/auth/token":
                return httpx.Response(200, json=_PARTNER_TOKEN)
            return httpx.Response(
                200,
                json={"data": {"purchases": [{**_PURCHASE, "valid_to": None}]}},
            )

        client, http_client = _client(handler)
        with http_client:
            purchases = client.list_tenant_topups()

        self.assertIsNone(purchases[0].valid_to)


if __name__ == "__main__":
    unittest.main()
