import assert from "node:assert/strict";
import test from "node:test";

import { IOCloudAPIError, IOCloudClient } from "../dist/index.js";

const PARTNER_TOKEN = {
  data: {
    token: {
      access_token: "partner-token",
      token_type: "Bearer",
      expires_at: "2099-01-01T00:00:00Z",
    },
  },
};

const TENANT_UUID = "3f1b1f70-0000-4000-8000-0000000000d1";
const USER_UUID = "3f1b1f70-0000-4000-8000-0000000000f1";
const ENTRY_UUID = "3f1b1f70-0000-4000-8000-0000000000a1";
const JOB_UUID = "3f1b1f70-0000-4000-8000-0000000000a9";

const WORKSPACE_PATH = `/v1/partner/tenants/${TENANT_UUID}/standing-context`;
const PERSONAL_PATH =
  `/v1/partner/tenants/${TENANT_UUID}/users/${USER_UUID}/standing-context`;

const ENTRY = {
  uuid: ENTRY_UUID,
  scope: "tenant",
  kind: "instruction",
  body: "Answer in formal German.",
  status: "active",
  expires_on: "2026-12-31",
  expired: false,
  source: "api",
  source_job_uuid: null,
  created_at: "2026-10-24T09:00:00Z",
  updated_at: "2026-10-24T09:30:00Z",
};

function entry(index, overrides = {}) {
  return {
    ...ENTRY,
    uuid: `3f1b1f70-0000-4000-8000-${String(index).padStart(12, "0")}`,
    ...overrides,
  };
}

function listing(items, total, offset = 0) {
  return {
    code: "STANDING_CONTEXT_LISTED",
    message: "",
    data: {
      entries: {
        items,
        pagination: {
          total,
          page: Math.floor(offset / 100) + 1,
          per_page: 100,
          pages: Math.ceil(total / 100),
        },
      },
    },
  };
}

/** Answers the token call, then delegates to `handler` for the real request. */
function partnerFetch(handler) {
  const seen = [];
  const fetch = async (input, init) => {
    const headers = new Headers(init?.headers);
    if (!headers.has("authorization")) return Response.json(PARTNER_TOKEN);
    const url = new URL(String(input));
    seen.push({
      url,
      method: init?.method,
      authorization: headers.get("authorization"),
      body: init?.body ? JSON.parse(init.body) : undefined,
    });
    return handler(seen.at(-1));
  };
  return { fetch, seen };
}

function client(fetch) {
  return new IOCloudClient({
    clientId: "client-id",
    clientSecret: "client-secret",
    baseUrl: "https://api.example.com/",
    fetch,
  });
}

test("lists a workspace's standing entries and parses them", async () => {
  const agentMemory = entry(2, {
    kind: "memory",
    body: "Audits follow ISO 9001.",
    expires_on: null,
    source: "agent",
    source_job_uuid: JOB_UUID,
  });
  const { fetch, seen } = partnerFetch(() =>
    Response.json(listing([ENTRY, agentMemory], 2)),
  );

  const entries = await client(fetch).listStandingEntries({
    tenantUuid: TENANT_UUID,
  });

  assert.equal(seen.length, 1);
  assert.equal(seen[0].method, "GET");
  assert.equal(seen[0].url.pathname, WORKSPACE_PATH);
  assert.equal(seen[0].authorization, "Bearer partner-token");
  assert.equal(seen[0].url.searchParams.get("include_retired"), "false");
  assert.equal(seen[0].url.searchParams.get("limit"), "100");
  assert.equal(seen[0].url.searchParams.get("offset"), "0");
  assert.equal(seen[0].body, undefined);

  assert.equal(entries.length, 2);
  assert.deepEqual(entries[0], {
    uuid: ENTRY_UUID,
    scope: "tenant",
    kind: "instruction",
    body: "Answer in formal German.",
    status: "active",
    expiresOn: "2026-12-31",
    expired: false,
    source: "api",
    sourceJobUuid: null,
    createdAt: new Date("2026-10-24T09:00:00Z"),
    updatedAt: new Date("2026-10-24T09:30:00Z"),
  });
  assert.equal(entries[1].kind, "memory");
  assert.equal(entries[1].expiresOn, null);
  assert.equal(entries[1].source, "agent");
  assert.equal(entries[1].sourceJobUuid, JOB_UUID);
});

test("an expired entry is listed and flagged", async () => {
  const { fetch } = partnerFetch(() =>
    Response.json(
      listing([{ ...ENTRY, expires_on: "2026-01-31", expired: true }], 1),
    ),
  );

  const entries = await client(fetch).listStandingEntries({
    tenantUuid: TENANT_UUID,
  });

  assert.equal(entries[0].expired, true);
  assert.equal(entries[0].status, "active");
});

test("reads a user's entries across pages, retired ones included", async () => {
  const firstPage = Array.from({ length: 100 }, (_, index) =>
    entry(index, { scope: "user" }),
  );
  const lastPage = [entry(100, { scope: "user", status: "retired" })];
  const { fetch, seen } = partnerFetch(({ url }) => {
    const offset = Number(url.searchParams.get("offset"));
    return Response.json(
      listing(offset === 0 ? firstPage : lastPage, 101, offset),
    );
  });

  const entries = await client(fetch).listStandingEntries({
    tenantUuid: TENANT_UUID,
    userUuid: USER_UUID,
    includeRetired: true,
  });

  assert.deepEqual(
    seen.map(({ url }) => url.pathname),
    [PERSONAL_PATH, PERSONAL_PATH],
  );
  assert.deepEqual(
    seen.map(({ url }) => url.searchParams.get("offset")),
    ["0", "100"],
  );
  for (const { url } of seen) {
    assert.equal(url.searchParams.get("include_retired"), "true");
    assert.equal(url.searchParams.get("limit"), "100");
  }
  assert.equal(entries.length, 101);
  assert.equal(entries[0].scope, "user");
  assert.equal(entries.at(-1).status, "retired");
});

test("stops reading at the page cap", async () => {
  const { fetch, seen } = partnerFetch(({ url }) => {
    const offset = Number(url.searchParams.get("offset"));
    const items = Array.from({ length: 100 }, (_, index) => entry(offset + index));
    return Response.json(listing(items, 1_000_000, offset));
  });

  const entries = await client(fetch).listStandingEntries({
    tenantUuid: TENANT_UUID,
  });

  assert.equal(seen.length, 50);
  assert.equal(entries.length, 5000);
});

test("an empty listing is one request", async () => {
  const { fetch, seen } = partnerFetch(() => Response.json(listing([], 0)));

  const entries = await client(fetch).listStandingEntries({
    tenantUuid: TENANT_UUID,
  });

  assert.deepEqual(entries, []);
  assert.equal(seen.length, 1);
});

test("gets one entry from either family", async () => {
  const { fetch, seen } = partnerFetch(() =>
    Response.json({ data: { entry: ENTRY } }),
  );
  const sdk = client(fetch);

  const workspace = await sdk.getStandingEntry({
    tenantUuid: TENANT_UUID,
    entryUuid: ENTRY_UUID,
  });
  await sdk.getStandingEntry({
    tenantUuid: TENANT_UUID,
    entryUuid: ENTRY_UUID,
    userUuid: USER_UUID,
  });

  assert.equal(workspace.uuid, ENTRY_UUID);
  assert.deepEqual(
    seen.map(({ method, url }) => [method, url.pathname]),
    [
      ["GET", `${WORKSPACE_PATH}/${ENTRY_UUID}`],
      ["GET", `${PERSONAL_PATH}/${ENTRY_UUID}`],
    ],
  );
});

test("creates a workspace instruction with an expiry", async () => {
  const { fetch, seen } = partnerFetch(() =>
    Response.json({ data: { entry: ENTRY } }, { status: 201 }),
  );

  const created = await client(fetch).createStandingEntry({
    tenantUuid: TENANT_UUID,
    kind: "instruction",
    body: "Answer in formal German.",
    expiresOn: "2026-12-31",
  });

  assert.equal(seen[0].method, "POST");
  assert.equal(seen[0].url.pathname, WORKSPACE_PATH);
  assert.deepEqual(seen[0].body, {
    kind: "instruction",
    body: "Answer in formal German.",
    expires_on: "2026-12-31",
  });
  assert.equal(created.expiresOn, "2026-12-31");
});

test("creates a personal memory without an expiry", async () => {
  const { fetch, seen } = partnerFetch(() =>
    Response.json(
      { data: { entry: { ...ENTRY, scope: "user", kind: "memory" } } },
      { status: 201 },
    ),
  );

  const created = await client(fetch).createStandingEntry({
    tenantUuid: TENANT_UUID,
    userUuid: USER_UUID,
    kind: "memory",
    body: "Manages the Hamburg site.",
  });

  assert.equal(seen[0].url.pathname, PERSONAL_PATH);
  assert.deepEqual(seen[0].body, {
    kind: "memory",
    body: "Manages the Hamburg site.",
  });
  assert.equal(created.scope, "user");
});

test("an update sends a null expiry only when clearing it", async () => {
  const { fetch, seen } = partnerFetch(() =>
    Response.json({ data: { entry: ENTRY } }),
  );
  const sdk = client(fetch);

  await sdk.updateStandingEntry({
    tenantUuid: TENANT_UUID,
    entryUuid: ENTRY_UUID,
    expiresOn: null,
  });
  await sdk.updateStandingEntry({
    tenantUuid: TENANT_UUID,
    entryUuid: ENTRY_UUID,
    body: "Answer in formal English.",
    expiresOn: undefined,
  });
  await sdk.updateStandingEntry({
    tenantUuid: TENANT_UUID,
    entryUuid: ENTRY_UUID,
    userUuid: USER_UUID,
    status: "retired",
    expiresOn: "2027-01-31",
  });

  assert.deepEqual(
    seen.map(({ method, url }) => [method, url.pathname]),
    [
      ["PATCH", `${WORKSPACE_PATH}/${ENTRY_UUID}`],
      ["PATCH", `${WORKSPACE_PATH}/${ENTRY_UUID}`],
      ["PATCH", `${PERSONAL_PATH}/${ENTRY_UUID}`],
    ],
  );
  assert.deepEqual(seen[0].body, { expires_on: null });
  assert.deepEqual(seen[1].body, { body: "Answer in formal English." });
  assert.equal("expires_on" in seen[1].body, false);
  assert.deepEqual(seen[2].body, { expires_on: "2027-01-31", status: "retired" });
});

test("deletes an entry from either family", async () => {
  const { fetch, seen } = partnerFetch(() =>
    Response.json({
      code: "STANDING_ENTRY_DELETED",
      message: "Deleted.",
      data: { entry_uuid: ENTRY_UUID },
    }),
  );
  const sdk = client(fetch);

  const result = await sdk.deleteStandingEntry({
    tenantUuid: TENANT_UUID,
    entryUuid: ENTRY_UUID,
  });
  await sdk.deleteStandingEntry({
    tenantUuid: TENANT_UUID,
    entryUuid: ENTRY_UUID,
    userUuid: USER_UUID,
  });

  assert.equal(result, undefined);
  assert.deepEqual(
    seen.map(({ method, url }) => [method, url.pathname]),
    [
      ["DELETE", `${WORKSPACE_PATH}/${ENTRY_UUID}`],
      ["DELETE", `${PERSONAL_PATH}/${ENTRY_UUID}`],
    ],
  );
});

async function refusal(status, body) {
  const { fetch } = partnerFetch(() => Response.json(body, { status }));
  try {
    await client(fetch).createStandingEntry({
      tenantUuid: TENANT_UUID,
      kind: "memory",
      body: "x",
    });
  } catch (error) {
    assert.ok(error instanceof IOCloudAPIError);
    return error;
  }
  assert.fail("expected the entry to be refused");
}

test("a full context carries its code and reason", async () => {
  const error = await refusal(409, {
    code: "STANDING_CONTEXT_FULL",
    message: "There is no room for another memory.",
    error: { reason: "STANDING_CONTEXT_FULL" },
  });

  assert.equal(error.statusCode, 409);
  assert.equal(error.code, "STANDING_CONTEXT_FULL");
  assert.equal(error.reason, "STANDING_CONTEXT_FULL");
  assert.deepEqual(error.fields, {});
});

test("a too-long entry is told apart by its reason", async () => {
  const error = await refusal(422, {
    code: "STANDING_ENTRY_INVALID",
    message: "A memory is at most 300 characters.",
    error: { reason: "STANDING_ENTRY_TOO_LONG" },
  });

  assert.equal(error.code, "STANDING_ENTRY_INVALID");
  assert.equal(error.reason, "STANDING_ENTRY_TOO_LONG");
});

test("a validation error carries its fields", async () => {
  const error = await refusal(422, {
    code: "VALIDATION_ERROR",
    message: "Request validation failed.",
    error: {
      fields: { "body.kind": ["Input should be 'instruction' or 'memory'"] },
    },
  });

  assert.equal(error.code, "VALIDATION_ERROR");
  assert.equal(error.reason, null);
  assert.deepEqual(error.fields, {
    "body.kind": ["Input should be 'instruction' or 'memory'"],
  });
});

test("a refusal without details has no reason", async () => {
  const error = await refusal(404, {
    code: "USER_NOT_FOUND",
    message: "No such user.",
    error: null,
  });

  assert.equal(error.code, "USER_NOT_FOUND");
  assert.equal(error.reason, null);
  assert.deepEqual(error.fields, {});
});
