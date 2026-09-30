import assert from "node:assert/strict";
import test from "node:test";

import { IOCloudAPIError, IOCloudClient } from "../dist/index.js";

test("caches a fresh partner token", async () => {
  let calls = 0;
  const fetch = async () => {
    calls += 1;
    return Response.json({
      data: {
        token: {
          access_token: "partner-token",
          token_type: "Bearer",
          expires_at: "2099-01-01T00:00:00Z",
        },
      },
    });
  };
  const client = new IOCloudClient({
    clientId: "client-id",
    clientSecret: "client-secret",
    baseUrl: "https://api.example.com/",
    fetch,
  });

  const first = await client.issuePartnerToken();
  const second = await client.issuePartnerToken();

  assert.equal(first, second);
  assert.equal(calls, 1);
});

test("raises a typed API error", async () => {
  const fetch = async (_input, init) => {
    const headers = new Headers(init?.headers);
    if (!headers.has("authorization")) {
      return Response.json({
        data: {
          token: {
            access_token: "partner-token",
            token_type: "Bearer",
            expires_at: "2099-01-01T00:00:00Z",
          },
        },
      });
    }
    return Response.json(
      { code: "VALIDATION_ERROR", message: "Invalid tenant." },
      { status: 422 },
    );
  };
  const client = new IOCloudClient({
    clientId: "client-id",
    clientSecret: "client-secret",
    baseUrl: "https://api.example.com",
    fetch,
  });

  await assert.rejects(
    client.createTenant({
      applicationUuid: "11111111-1111-1111-1111-111111111111",
      name: "Acme",
      contactEmail: "ops@acme.example",
    }),
    (error) =>
      error instanceof IOCloudAPIError &&
      error.statusCode === 422 &&
      error.code === "VALIDATION_ERROR",
  );
});
