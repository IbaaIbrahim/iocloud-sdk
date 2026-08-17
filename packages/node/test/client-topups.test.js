import assert from "node:assert/strict";
import test from "node:test";

import { IOCloudClient } from "../dist/index.js";

const PARTNER_TOKEN = {
  data: {
    token: {
      access_token: "partner-token",
      token_type: "Bearer",
      expires_at: "2099-01-01T00:00:00Z",
    },
  },
};

const BRONZE_PLAN_UUID = "3f1b1f70-0000-4000-8000-0000000000b1";
const PACKAGE_UUID = "3f1b1f70-0000-4000-8000-0000000000c1";
const TENANT_UUID = "3f1b1f70-0000-4000-8000-0000000000d1";
const PURCHASE_UUID = "3f1b1f70-0000-4000-8000-0000000000e1";

const PACKAGE = {
  uuid: PACKAGE_UUID,
  name: "Booster 5K",
  credits: 5000,
  price_cents: 4900,
  validity_days: 90,
  definer_type: "partners",
  audience: "tenants",
  status: "active",
  created_at: "2026-08-17T00:00:00Z",
  updated_at: "2026-08-17T00:00:00Z",
  plans: [
    {
      plan_type: "tenant_plans",
      plan_uuid: BRONZE_PLAN_UUID,
      plan_name: "Bronze",
    },
  ],
};

const PURCHASE = {
  uuid: PURCHASE_UUID,
  package_uuid: PACKAGE_UUID,
  package_name: "Booster 5K",
  tenant_uuid: TENANT_UUID,
  tenant_name: "Acme Ltd",
  credits: 5000,
  valid_from: "2026-08-17T00:00:00+00:00",
  valid_to: "2026-11-15T00:00:00+00:00",
  status: "active",
  payment_transaction_uuid: null,
  created_at: "2026-08-17T00:00:00Z",
};

/** Answers the token call, then delegates to `handler` for the real request. */
function partnerFetch(handler) {
  const seen = [];
  const fetch = async (input, init) => {
    const headers = new Headers(init?.headers);
    if (!headers.has("authorization")) return Response.json(PARTNER_TOKEN);
    seen.push({
      url: String(input),
      method: init?.method,
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

test("lists top-up packages from the paginated envelope", async () => {
  const { fetch, seen } = partnerFetch(() =>
    Response.json({
      data: {
        list: [PACKAGE],
        pagination: { page: 1, total_pages: 1, limit: 50, total: 1 },
      },
    }),
  );

  const packages = await client(fetch).listTopupPackages({ limit: 50 });

  assert.match(seen[0].url, /\/v1\/partner\/topup-packages\?page=1&limit=50$/);
  assert.equal(packages.length, 1);
  assert.equal(packages[0].name, "Booster 5K");
  assert.equal(packages[0].credits, 5000);
  assert.equal(packages[0].validityDays, 90);
  assert.equal(packages[0].plans.length, 1);
  assert.equal(packages[0].plans[0].planName, "Bronze");
});

test("a package with no plans parses as offered to everyone", async () => {
  const { fetch } = partnerFetch(() =>
    Response.json({ data: { list: [{ ...PACKAGE, plans: [] }] } }),
  );

  const packages = await client(fetch).listTopupPackages();

  assert.deepEqual(packages[0].plans, []);
});

test("a package with no expiry parses validityDays as null", async () => {
  const { fetch } = partnerFetch(() =>
    Response.json({ data: { list: [{ ...PACKAGE, validity_days: null }] } }),
  );

  const packages = await client(fetch).listTopupPackages();

  assert.equal(packages[0].validityDays, null);
});

test("creating a package sends its plan scoping", async () => {
  const { fetch, seen } = partnerFetch(() =>
    Response.json({ data: { package: PACKAGE } }),
  );

  const created = await client(fetch).createTopupPackage({
    name: "Booster 5K",
    credits: 5000,
    priceCents: 4900,
    validityDays: 90,
    planUuids: [BRONZE_PLAN_UUID],
  });

  assert.equal(seen[0].method, "POST");
  assert.deepEqual(seen[0].body, {
    name: "Booster 5K",
    credits: 5000,
    price_cents: 4900,
    validity_days: 90,
    plan_uuids: [BRONZE_PLAN_UUID],
  });
  assert.equal(created.uuid, PACKAGE_UUID);
});

test("creating without plans sends an empty list, not an omission", async () => {
  const { fetch, seen } = partnerFetch(() =>
    Response.json({ data: { package: PACKAGE } }),
  );

  await client(fetch).createTopupPackage({
    name: "Any",
    credits: 10,
    priceCents: 0,
  });

  assert.deepEqual(seen[0].body.plan_uuids, []);
  assert.equal(seen[0].body.validity_days, null);
});

test("clearing the scoping sends plan_uuids, omitting it does not", async () => {
  const cleared = partnerFetch(() => Response.json({ data: { package: PACKAGE } }));
  await client(cleared.fetch).updateTopupPackage({
    packageUuid: PACKAGE_UUID,
    planUuids: [],
  });
  assert.deepEqual(cleared.seen[0].body, { plan_uuids: [] });
  assert.match(cleared.seen[0].url, new RegExp(`/topup-packages/${PACKAGE_UUID}$`));

  const statusOnly = partnerFetch(() =>
    Response.json({ data: { package: PACKAGE } }),
  );
  await client(statusOnly.fetch).updateTopupPackage({
    packageUuid: PACKAGE_UUID,
    status: "inactive",
  });
  assert.deepEqual(statusOnly.seen[0].body, { status: "inactive" });
});

test("granting a tenant top-up activates it by default", async () => {
  const { fetch, seen } = partnerFetch(() =>
    Response.json({
      data: {
        purchase: PURCHASE,
        provisioning: { pool_created: true, pool_credits: 5000 },
      },
    }),
  );

  const result = await client(fetch).grantTenantTopup({
    tenantUuid: TENANT_UUID,
    packageUuid: PACKAGE_UUID,
    reference: "invoice INV-2026-0042",
  });

  assert.equal(seen[0].method, "POST");
  assert.equal(seen[0].body.activate_now, true);
  assert.equal(seen[0].body.reference, "invoice INV-2026-0042");
  assert.equal(result.purchase.tenantName, "Acme Ltd");
  assert.equal(result.purchase.credits, 5000);
  assert.equal(result.provisioned.poolCreated, true);
  assert.equal(result.provisioned.poolCredits, 5000);
});

test("granting without activation provisions nothing", async () => {
  const { fetch, seen } = partnerFetch(() =>
    Response.json({
      data: { purchase: { ...PURCHASE, status: "pending" }, provisioning: null },
    }),
  );

  const result = await client(fetch).grantTenantTopup({
    tenantUuid: TENANT_UUID,
    packageUuid: PACKAGE_UUID,
    activateNow: false,
  });

  assert.equal(seen[0].body.activate_now, false);
  assert.equal(result.purchase.status, "pending");
  assert.equal(result.provisioned, null);
});

test("activating an already-active top-up provisions nothing", async () => {
  const { fetch, seen } = partnerFetch(() =>
    Response.json({ data: { purchase: PURCHASE, provisioning: null } }),
  );

  const result = await client(fetch).activateTenantTopup({
    transactionUuid: PURCHASE_UUID,
  });

  assert.match(
    seen[0].url,
    new RegExp(`/topups/tenant/purchases/${PURCHASE_UUID}/activate$`),
  );
  assert.equal(result.purchase.status, "active");
  assert.equal(result.provisioned, null);
});

test("lists every top-up bought by the partner's tenants", async () => {
  const { fetch, seen } = partnerFetch(() =>
    Response.json({ data: { purchases: [PURCHASE] } }),
  );

  const purchases = await client(fetch).listTenantTopups();

  assert.match(seen[0].url, /\/v1\/partner\/topups\/tenant\/purchases$/);
  assert.equal(purchases.length, 1);
  assert.equal(purchases[0].tenantUuid, TENANT_UUID);
  assert.ok(purchases[0].validTo instanceof Date);
});

test("a purchase that never expires parses validTo as null", async () => {
  const { fetch } = partnerFetch(() =>
    Response.json({ data: { purchases: [{ ...PURCHASE, valid_to: null }] } }),
  );

  const purchases = await client(fetch).listTenantTopups();

  assert.equal(purchases[0].validTo, null);
});
