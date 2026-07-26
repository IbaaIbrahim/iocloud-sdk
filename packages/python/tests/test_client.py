import unittest

import httpx

from iocloud_sdk import IOCloudAPIError, IOCloudClient


class IOCloudClientTests(unittest.TestCase):
    def test_partner_token_is_cached(self) -> None:
        calls = 0

        def handler(request: httpx.Request) -> httpx.Response:
            nonlocal calls
            calls += 1
            self.assertEqual(request.url.path, "/v1/partner/auth/token")
            return httpx.Response(
                200,
                json={
                    "data": {
                        "token": {
                            "access_token": "partner-token",
                            "token_type": "Bearer",
                            "expires_at": "2099-01-01T00:00:00Z",
                        }
                    }
                },
            )

        with httpx.Client(transport=httpx.MockTransport(handler)) as http_client:
            client = IOCloudClient(
                client_id="client-id",
                client_secret="client-secret",
                base_url="https://api.example.com/",
                http_client=http_client,
            )
            first = client.issue_partner_token()
            second = client.issue_partner_token()

        self.assertIs(first, second)
        self.assertEqual(calls, 1)

    def test_api_error_retains_response_details(self) -> None:
        def handler(_: httpx.Request) -> httpx.Response:
            return httpx.Response(
                422,
                json={"code": "VALIDATION_ERROR", "message": "Invalid tenant."},
            )

        with httpx.Client(transport=httpx.MockTransport(handler)) as http_client:
            client = IOCloudClient(
                client_id="client-id",
                client_secret="client-secret",
                base_url="https://api.example.com",
                http_client=http_client,
            )
            with self.assertRaises(IOCloudAPIError) as raised:
                client.create_tenant(
                    application_uuid="11111111-1111-1111-1111-111111111111",
                    name="Acme",
                    slug="acme",
                    contact_email="ops@acme.example",
                )

        self.assertEqual(raised.exception.status_code, 422)
        self.assertEqual(raised.exception.code, "VALIDATION_ERROR")


if __name__ == "__main__":
    unittest.main()
