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

_SUBSCRIPTION = {
    "uuid": "3f1b1f70-0000-4000-8000-000000000001",
    "status": "paid",
    "plan_type": "tenant_plans",
    "billing_cycle": "monthly",
    "subscribed_from": "2026-08-04T00:00:00+00:00",
    "subscribed_to": "2026-09-03T00:00:00+00:00",
    "payment_transaction_uuid": None,
    "created_at": "2026-08-04T00:00:00Z",
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


class TenantPlanTests(unittest.TestCase):
    def test_list_tenant_plans_unwraps_the_paginated_envelope(self) -> None:
        def handler(request: httpx.Request) -> httpx.Response:
            if request.url.path == "/v1/partner/auth/token":
                return httpx.Response(200, json=_PARTNER_TOKEN)
            self.assertEqual(request.url.path, "/v1/partner/plans/tenant")
            self.assertEqual(request.url.params.get("limit"), "50")
            return httpx.Response(
                200,
                json={
                    "data": {
                        "list": [
                            {
                                "uuid": "3f1b1f70-0000-4000-8000-00000000000a",
                                "name": "Growth",
                                "monthly_price_cents": 1900,
                                "yearly_price_cents": 19000,
                                "tpm": 100,
                                "rpm": 20,
                                "credits": 2000,
                                "user_credits_cap": 500,
                                "user_tpm": 10,
                                "user_rpm": 5,
                            }
                        ],
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
            plans = client.list_tenant_plans(limit=50)

        self.assertEqual(len(plans), 1)
        self.assertEqual(plans[0].name, "Growth")
        self.assertEqual(plans[0].credits, 2000)
        self.assertEqual(plans[0].user_credits_cap, 500)

    def test_subscribe_tenant_activates_and_reports_provisioned_caps(self) -> None:
        sent: dict = {}

        def handler(request: httpx.Request) -> httpx.Response:
            if request.url.path == "/v1/partner/auth/token":
                return httpx.Response(200, json=_PARTNER_TOKEN)
            self.assertEqual(
                request.url.path, "/v1/partner/plans/tenant/subscriptions"
            )
            sent.update(__import__("json").loads(request.content))
            return httpx.Response(
                201,
                json={
                    "data": {
                        "subscription": _SUBSCRIPTION,
                        "provisioning": {
                            "pool_created": False,
                            "pool_credits": 0,
                            "caps_created": [
                                {"child": "tenant", "id": 7, "cap": 2000},
                                {"child": "user", "id": 11, "cap": 500},
                            ],
                        },
                    }
                },
            )

        client, http_client = _client(handler)
        with http_client:
            result = client.subscribe_tenant(
                tenant_uuid="3f1b1f70-0000-4000-8000-0000000000ff",
                plan_uuid="3f1b1f70-0000-4000-8000-00000000000a",
                reference="invoice INV-1",
            )

        # activate_now defaults to True: the partner already billed its client.
        self.assertIs(sent["activate_now"], True)
        self.assertEqual(sent["billing_cycle"], "monthly")
        self.assertEqual(sent["reference"], "invoice INV-1")
        self.assertTrue(result.subscription.is_active)
        self.assertIsNotNone(result.subscription.subscribed_to)
        # Tenants get caps, never a pool of their own.
        self.assertFalse(result.provisioned.pool_created)
        self.assertEqual(len(result.provisioned.caps_created), 2)

    def test_pending_subscription_has_no_window_and_no_provisioning(self) -> None:
        def handler(request: httpx.Request) -> httpx.Response:
            if request.url.path == "/v1/partner/auth/token":
                return httpx.Response(200, json=_PARTNER_TOKEN)
            return httpx.Response(
                201,
                json={
                    "data": {
                        "subscription": {
                            **_SUBSCRIPTION,
                            "status": "pending_payment",
                            "subscribed_from": None,
                            "subscribed_to": None,
                        },
                        "provisioning": None,
                    }
                },
            )

        client, http_client = _client(handler)
        with http_client:
            result = client.subscribe_tenant(
                tenant_uuid="3f1b1f70-0000-4000-8000-0000000000ff",
                plan_uuid="3f1b1f70-0000-4000-8000-00000000000a",
                activate_now=False,
            )

        self.assertFalse(result.subscription.is_active)
        self.assertIsNone(result.subscription.subscribed_from)
        self.assertIsNone(result.provisioned)

    def test_activate_tenant_subscription_targets_the_subscription(self) -> None:
        def handler(request: httpx.Request) -> httpx.Response:
            if request.url.path == "/v1/partner/auth/token":
                return httpx.Response(200, json=_PARTNER_TOKEN)
            self.assertEqual(
                request.url.path,
                "/v1/partner/plans/tenant/subscriptions/"
                "3f1b1f70-0000-4000-8000-000000000001/activate",
            )
            return httpx.Response(
                200,
                json={
                    "data": {
                        "subscription": _SUBSCRIPTION,
                        "provisioning": {
                            "pool_created": False,
                            "pool_credits": 0,
                            "caps_created": [
                                {"child": "tenant", "id": 7, "cap": 2000}
                            ],
                        },
                    }
                },
            )

        client, http_client = _client(handler)
        with http_client:
            result = client.activate_tenant_subscription(
                subscription_uuid="3f1b1f70-0000-4000-8000-000000000001",
                reference="bank transfer",
            )

        self.assertTrue(result.subscription.is_active)
        self.assertEqual(result.provisioned.caps_created[0]["cap"], 2000)

    def test_list_tenant_subscriptions_reads_the_named_key(self) -> None:
        def handler(request: httpx.Request) -> httpx.Response:
            if request.url.path == "/v1/partner/auth/token":
                return httpx.Response(200, json=_PARTNER_TOKEN)
            self.assertEqual(
                request.url.path, "/v1/partner/plans/tenant/subscriptions"
            )
            return httpx.Response(
                200, json={"data": {"subscriptions": [_SUBSCRIPTION]}}
            )

        client, http_client = _client(handler)
        with http_client:
            subscriptions = client.list_tenant_subscriptions()

        self.assertEqual(len(subscriptions), 1)
        self.assertEqual(subscriptions[0].billing_cycle, "monthly")


if __name__ == "__main__":  # pragma: no cover
    unittest.main()
