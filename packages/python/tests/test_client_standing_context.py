import json
import unittest
from datetime import date, datetime, timezone
from uuid import UUID

import httpx

from iocloud_sdk import IOCloudAPIError, IOCloudClient, StandingEntry

_PARTNER_TOKEN = {
    "data": {
        "token": {
            "access_token": "partner-token",
            "token_type": "Bearer",
            "expires_at": "2099-01-01T00:00:00Z",
        }
    }
}

_TENANT_UUID = "3f1b1f70-0000-4000-8000-0000000000d1"
_USER_UUID = "3f1b1f70-0000-4000-8000-0000000000f1"
_ENTRY_UUID = "3f1b1f70-0000-4000-8000-0000000000a1"
_JOB_UUID = "3f1b1f70-0000-4000-8000-0000000000a9"

_WORKSPACE_PATH = f"/v1/partner/tenants/{_TENANT_UUID}/standing-context"
_PERSONAL_PATH = (
    f"/v1/partner/tenants/{_TENANT_UUID}/users/{_USER_UUID}/standing-context"
)

_ENTRY = {
    "uuid": _ENTRY_UUID,
    "scope": "tenant",
    "kind": "instruction",
    "body": "Answer in formal German.",
    "status": "active",
    "expires_on": "2026-12-31",
    "expired": False,
    "source": "api",
    "source_job_uuid": None,
    "created_at": "2026-10-24T09:00:00Z",
    "updated_at": "2026-10-24T09:30:00Z",
}


def _entry(index: int, **overrides) -> dict:
    return {**_ENTRY, "uuid": f"3f1b1f70-0000-4000-8000-{index:012d}", **overrides}


def _listing(items: list[dict], total: int, offset: int = 0) -> dict:
    return {
        "code": "STANDING_CONTEXT_LISTED",
        "message": "",
        "data": {
            "entries": {
                "items": items,
                "pagination": {
                    "total": total,
                    "page": offset // 100 + 1,
                    "per_page": 100,
                    "pages": (total + 99) // 100,
                },
            }
        },
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


def _recording(reply) -> tuple[list[httpx.Request], object]:
    """A handler that answers the token call and records every other request."""
    seen: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path == "/v1/partner/auth/token":
            return httpx.Response(200, json=_PARTNER_TOKEN)
        seen.append(request)
        return reply(request)

    return seen, handler


class ListStandingEntriesTests(unittest.TestCase):
    def test_lists_a_workspaces_entries_and_parses_them(self) -> None:
        agent_memory = _entry(
            2,
            kind="memory",
            body="Audits follow ISO 9001.",
            expires_on=None,
            expired=False,
            source="agent",
            source_job_uuid=_JOB_UUID,
        )
        seen, handler = _recording(
            lambda _: httpx.Response(200, json=_listing([_ENTRY, agent_memory], 2))
        )

        client, http_client = _client(handler)
        with http_client:
            entries = client.list_standing_entries(_TENANT_UUID)

        self.assertEqual(len(seen), 1)
        self.assertEqual(seen[0].method, "GET")
        self.assertEqual(seen[0].url.path, _WORKSPACE_PATH)
        self.assertEqual(seen[0].headers["authorization"], "Bearer partner-token")
        self.assertEqual(seen[0].url.params.get("include_retired"), "false")
        self.assertEqual(seen[0].url.params.get("limit"), "100")
        self.assertEqual(seen[0].url.params.get("offset"), "0")

        self.assertEqual(len(entries), 2)
        instruction = entries[0]
        self.assertIsInstance(instruction, StandingEntry)
        self.assertEqual(instruction.uuid, UUID(_ENTRY_UUID))
        self.assertEqual(instruction.scope, "tenant")
        self.assertEqual(instruction.kind, "instruction")
        self.assertEqual(instruction.body, "Answer in formal German.")
        self.assertTrue(instruction.is_active)
        self.assertEqual(instruction.expires_on, date(2026, 12, 31))
        self.assertFalse(instruction.expired)
        self.assertEqual(instruction.source, "api")
        self.assertIsNone(instruction.source_job_uuid)
        self.assertEqual(
            instruction.created_at, datetime(2026, 10, 24, 9, 0, tzinfo=timezone.utc)
        )
        self.assertEqual(
            instruction.updated_at, datetime(2026, 10, 24, 9, 30, tzinfo=timezone.utc)
        )

        memory = entries[1]
        self.assertEqual(memory.kind, "memory")
        self.assertIsNone(memory.expires_on)
        self.assertEqual(memory.source, "agent")
        self.assertEqual(memory.source_job_uuid, UUID(_JOB_UUID))

    def test_an_expired_entry_is_listed_and_flagged(self) -> None:
        _, handler = _recording(
            lambda _: httpx.Response(
                200,
                json=_listing([{**_ENTRY, "expires_on": "2026-01-31", "expired": True}], 1),
            )
        )

        client, http_client = _client(handler)
        with http_client:
            entries = client.list_standing_entries(_TENANT_UUID)

        self.assertTrue(entries[0].expired)
        self.assertTrue(entries[0].is_active)

    def test_reads_a_users_entries_across_pages_with_retired_ones(self) -> None:
        first_page = [_entry(index, scope="user") for index in range(100)]
        last_page = [_entry(100, scope="user", status="retired")]

        def reply(request: httpx.Request) -> httpx.Response:
            offset = int(request.url.params["offset"])
            items = first_page if offset == 0 else last_page
            return httpx.Response(200, json=_listing(items, 101, offset))

        seen, handler = _recording(reply)
        client, http_client = _client(handler)
        with http_client:
            entries = client.list_standing_entries(
                _TENANT_UUID, user_uuid=_USER_UUID, include_retired=True
            )

        self.assertEqual([request.url.path for request in seen], [_PERSONAL_PATH] * 2)
        self.assertEqual(
            [request.url.params["offset"] for request in seen], ["0", "100"]
        )
        self.assertTrue(
            all(request.url.params["include_retired"] == "true" for request in seen)
        )
        self.assertTrue(all(request.url.params["limit"] == "100" for request in seen))
        self.assertEqual(len(entries), 101)
        self.assertEqual(entries[0].scope, "user")
        self.assertEqual(entries[-1].status, "retired")
        self.assertFalse(entries[-1].is_active)

    def test_stops_reading_at_the_page_cap(self) -> None:
        """A listing that never ends must not loop forever."""

        def reply(request: httpx.Request) -> httpx.Response:
            offset = int(request.url.params["offset"])
            items = [_entry(offset + index) for index in range(100)]
            return httpx.Response(200, json=_listing(items, 1_000_000, offset))

        seen, handler = _recording(reply)
        client, http_client = _client(handler)
        with http_client:
            entries = client.list_standing_entries(_TENANT_UUID)

        self.assertEqual(len(seen), 50)
        self.assertEqual(len(entries), 5000)

    def test_an_empty_listing_is_one_request(self) -> None:
        seen, handler = _recording(lambda _: httpx.Response(200, json=_listing([], 0)))

        client, http_client = _client(handler)
        with http_client:
            entries = client.list_standing_entries(_TENANT_UUID)

        self.assertEqual(entries, [])
        self.assertEqual(len(seen), 1)


class StandingEntryCallTests(unittest.TestCase):
    def test_gets_one_entry_from_either_family(self) -> None:
        seen, handler = _recording(
            lambda _: httpx.Response(200, json={"data": {"entry": _ENTRY}})
        )

        client, http_client = _client(handler)
        with http_client:
            workspace = client.get_standing_entry(_TENANT_UUID, _ENTRY_UUID)
            client.get_standing_entry(_TENANT_UUID, _ENTRY_UUID, user_uuid=_USER_UUID)

        self.assertEqual(workspace.uuid, UUID(_ENTRY_UUID))
        self.assertEqual([request.method for request in seen], ["GET", "GET"])
        self.assertEqual(
            [request.url.path for request in seen],
            [f"{_WORKSPACE_PATH}/{_ENTRY_UUID}", f"{_PERSONAL_PATH}/{_ENTRY_UUID}"],
        )

    def test_creates_a_workspace_instruction_with_an_expiry(self) -> None:
        seen, handler = _recording(
            lambda _: httpx.Response(201, json={"data": {"entry": _ENTRY}})
        )

        client, http_client = _client(handler)
        with http_client:
            entry = client.create_standing_entry(
                _TENANT_UUID,
                kind="instruction",
                body="Answer in formal German.",
                expires_on=date(2026, 12, 31),
            )

        self.assertEqual(seen[0].method, "POST")
        self.assertEqual(seen[0].url.path, _WORKSPACE_PATH)
        self.assertEqual(
            json.loads(seen[0].content),
            {
                "kind": "instruction",
                "body": "Answer in formal German.",
                "expires_on": "2026-12-31",
            },
        )
        self.assertEqual(entry.expires_on, date(2026, 12, 31))

    def test_creates_a_personal_memory_without_an_expiry(self) -> None:
        seen, handler = _recording(
            lambda _: httpx.Response(
                201,
                json={"data": {"entry": {**_ENTRY, "scope": "user", "kind": "memory"}}},
            )
        )

        client, http_client = _client(handler)
        with http_client:
            entry = client.create_standing_entry(
                _TENANT_UUID,
                kind="memory",
                body="Manages the Hamburg site.",
                user_uuid=_USER_UUID,
            )

        self.assertEqual(seen[0].url.path, _PERSONAL_PATH)
        self.assertEqual(
            json.loads(seen[0].content),
            {"kind": "memory", "body": "Manages the Hamburg site."},
        )
        self.assertEqual(entry.scope, "user")

    def test_expires_on_also_takes_an_iso_string(self) -> None:
        seen, handler = _recording(
            lambda _: httpx.Response(201, json={"data": {"entry": _ENTRY}})
        )

        client, http_client = _client(handler)
        with http_client:
            client.create_standing_entry(
                _TENANT_UUID, kind="memory", body="x", expires_on="2026-12-31"
            )

        self.assertEqual(json.loads(seen[0].content)["expires_on"], "2026-12-31")

    def test_a_datetime_expiry_is_refused_before_any_request(self) -> None:
        seen, handler = _recording(lambda _: httpx.Response(500))

        client, http_client = _client(handler)
        with http_client, self.assertRaises(TypeError):
            client.create_standing_entry(
                _TENANT_UUID,
                kind="memory",
                body="x",
                expires_on=datetime(2026, 12, 31, tzinfo=timezone.utc),
            )

        self.assertEqual(seen, [])

    def test_update_sends_a_null_expiry_only_when_clearing_it(self) -> None:
        seen, handler = _recording(
            lambda _: httpx.Response(200, json={"data": {"entry": _ENTRY}})
        )

        client, http_client = _client(handler)
        with http_client:
            client.update_standing_entry(_TENANT_UUID, _ENTRY_UUID, expires_on=None)
            client.update_standing_entry(
                _TENANT_UUID, _ENTRY_UUID, body="Answer in formal English."
            )
            client.update_standing_entry(
                _TENANT_UUID,
                _ENTRY_UUID,
                user_uuid=_USER_UUID,
                status="retired",
                expires_on=date(2027, 1, 31),
            )

        self.assertEqual([request.method for request in seen], ["PATCH"] * 3)
        self.assertEqual(
            [request.url.path for request in seen],
            [
                f"{_WORKSPACE_PATH}/{_ENTRY_UUID}",
                f"{_WORKSPACE_PATH}/{_ENTRY_UUID}",
                f"{_PERSONAL_PATH}/{_ENTRY_UUID}",
            ],
        )
        cleared, unchanged, retired = (json.loads(r.content) for r in seen)
        self.assertEqual(cleared, {"expires_on": None})
        self.assertEqual(unchanged, {"body": "Answer in formal English."})
        self.assertNotIn("expires_on", unchanged)
        self.assertEqual(retired, {"status": "retired", "expires_on": "2027-01-31"})

    def test_deletes_an_entry_from_either_family(self) -> None:
        seen, handler = _recording(
            lambda _: httpx.Response(
                200,
                json={
                    "code": "STANDING_ENTRY_DELETED",
                    "message": "Deleted.",
                    "data": {"entry_uuid": _ENTRY_UUID},
                },
            )
        )

        client, http_client = _client(handler)
        with http_client:
            result = client.delete_standing_entry(_TENANT_UUID, _ENTRY_UUID)
            client.delete_standing_entry(_TENANT_UUID, _ENTRY_UUID, user_uuid=_USER_UUID)

        self.assertIsNone(result)
        self.assertEqual([request.method for request in seen], ["DELETE", "DELETE"])
        self.assertEqual(
            [request.url.path for request in seen],
            [f"{_WORKSPACE_PATH}/{_ENTRY_UUID}", f"{_PERSONAL_PATH}/{_ENTRY_UUID}"],
        )


class StandingContextRefusalTests(unittest.TestCase):
    def _refusal(self, status_code: int, body: dict) -> IOCloudAPIError:
        _, handler = _recording(lambda _: httpx.Response(status_code, json=body))
        client, http_client = _client(handler)
        with http_client, self.assertRaises(IOCloudAPIError) as raised:
            client.create_standing_entry(_TENANT_UUID, kind="memory", body="x")
        return raised.exception

    def test_a_full_context_carries_its_code_and_reason(self) -> None:
        error = self._refusal(
            409,
            {
                "code": "STANDING_CONTEXT_FULL",
                "message": "There is no room for another memory.",
                "error": {"reason": "STANDING_CONTEXT_FULL"},
            },
        )

        self.assertEqual(error.status_code, 409)
        self.assertEqual(error.code, "STANDING_CONTEXT_FULL")
        self.assertEqual(error.reason, "STANDING_CONTEXT_FULL")
        self.assertEqual(error.fields, {})

    def test_a_too_long_entry_is_told_apart_by_its_reason(self) -> None:
        error = self._refusal(
            422,
            {
                "code": "STANDING_ENTRY_INVALID",
                "message": "A memory is at most 300 characters.",
                "error": {"reason": "STANDING_ENTRY_TOO_LONG"},
            },
        )

        self.assertEqual(error.code, "STANDING_ENTRY_INVALID")
        self.assertEqual(error.reason, "STANDING_ENTRY_TOO_LONG")

    def test_a_validation_error_carries_its_fields(self) -> None:
        error = self._refusal(
            422,
            {
                "code": "VALIDATION_ERROR",
                "message": "Request validation failed.",
                "error": {"fields": {"body.kind": ["Input should be 'instruction' or 'memory'"]}},
            },
        )

        self.assertEqual(error.code, "VALIDATION_ERROR")
        self.assertIsNone(error.reason)
        self.assertEqual(
            error.fields, {"body.kind": ["Input should be 'instruction' or 'memory'"]}
        )

    def test_a_refusal_without_details_has_no_reason(self) -> None:
        error = self._refusal(
            404,
            {"code": "USER_NOT_FOUND", "message": "No such user.", "error": None},
        )

        self.assertEqual(error.code, "USER_NOT_FOUND")
        self.assertIsNone(error.reason)
        self.assertEqual(error.fields, {})


if __name__ == "__main__":
    unittest.main()
