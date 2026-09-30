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

const SUBSCRIPTION = {
  uuid: "3f1b1f70-0000-4000-8000-000000000001",
  status: "paid",
  plan_type: "tenant_plans",
  billing_cycle: "monthly",
  subscribed_from: "2026-08-04T00:00:00+00:00",
  subscribed_to: "2026-09-03T00:00:00+00:00",
  payment_transaction_uuid: null,
  created_at: "2026-08-04T00:00:00Z",
};

/** What a platform that predates plan codes sends: no plan_code member at all. */
const TENANT_PLAN = {
  uuid: "3f1b1f70-0000-4000-8000-00000000000a",
  name: "Growth",
  monthly_price_cents: 1900,
  yearly_price_cents: 19000,
  tpm: 100,
  rpm: 20,
  credits: 2000,
  user_credits_cap: 500,
  user_tpm: 10,
  user_rpm: 5,
};

/** The paginated envelope the tenant plan listing answers with. */
function planList(plans) {
  return Response.json({
    data: {
      list: plans,
      pagination: { page: 1, total_pages: 1, limit: 25, total: plans.length },
    },
  });
}

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

test("lists tenant plans from the paginated envelope", async () => {
  const { fetch, seen } = partnerFetch(() =>
    Response.json({
      data: {
        list: [
          {
            uuid: "3f1b1f70-0000-4000-8000-00000000000a",
            name: "Growth",
            monthly_price_cents: 1900,
            yearly_price_cents: 19000,
            tpm: 100,
            rpm: 20,
            credits: 2000,
            user_credits_cap: 500,
            user_tpm: 10,
            user_rpm: 5,
          },
        ],
        pagination: { page: 1, total_pages: 1, limit: 50, total: 1 },
      },
    }),
  );

  const plans = await client(fetch).listTenantPlans({ limit: 50 });

  assert.match(seen[0].url, /\/v1\/partner\/plans\/tenant\?page=1&limit=50$/);
  assert.equal(plans.length, 1);
  assert.equal(plans[0].credits, 2000);
  assert.equal(plans[0].userCreditsCap, 500);
});

test("a tenant plan reads its plan code", async () => {
  const { fetch } = partnerFetch(() =>
    planList([{ ...TENANT_PLAN, plan_code: "Growth-2026" }]),
  );

  const [plan] = await client(fetch).listTenantPlans();

  assert.equal(plan.planCode, "Growth-2026");
});

test("a plan without a plan code reads as null", async () => {
  // Null from a plan that has none; absent from an older platform.
  const { fetch } = partnerFetch(() =>
    planList([{ ...TENANT_PLAN, plan_code: null }, TENANT_PLAN]),
  );

  const plans = await client(fetch).listTenantPlans();

  assert.equal(plans[0].planCode, null);
  assert.equal(plans[1].planCode, null);
});

test("subscribes a tenant and activates by default", async () => {
  const { fetch, seen } = partnerFetch(() =>
    Response.json(
      {
        data: {
          subscription: SUBSCRIPTION,
          provisioning: {
            pool_created: false,
            pool_credits: 0,
            caps_created: [
              { child: "tenant", id: 7, cap: 2000 },
              { child: "user", id: 11, cap: 500 },
            ],
          },
        },
      },
      { status: 201 },
    ),
  );

  const result = await client(fetch).subscribeTenant({
    tenantUuid: "3f1b1f70-0000-4000-8000-0000000000ff",
    planUuid: "3f1b1f70-0000-4000-8000-00000000000a",
    reference: "invoice INV-1",
  });

  assert.equal(seen[0].body.activate_now, true);
  assert.equal(seen[0].body.billing_cycle, "monthly");
  assert.equal(seen[0].body.reference, "invoice INV-1");
  assert.equal(result.subscription.status, "paid");
  assert.ok(result.subscription.subscribedTo instanceof Date);
  // Tenants get caps, never a pool of their own.
  assert.equal(result.provisioned.poolCreated, false);
  assert.equal(result.provisioned.capsCreated.length, 2);
  assert.equal(result.provisioned.capsCreated[0].cap, 2000);
});

test("a pending subscription has no window and no provisioning", async () => {
  const { fetch } = partnerFetch(() =>
    Response.json(
      {
        data: {
          subscription: {
            ...SUBSCRIPTION,
            status: "pending_payment",
            subscribed_from: null,
            subscribed_to: null,
          },
          provisioning: null,
        },
      },
      { status: 201 },
    ),
  );

  const result = await client(fetch).subscribeTenant({
    tenantUuid: "3f1b1f70-0000-4000-8000-0000000000ff",
    planUuid: "3f1b1f70-0000-4000-8000-00000000000a",
    activateNow: false,
  });

  assert.equal(result.subscription.status, "pending_payment");
  assert.equal(result.subscription.subscribedFrom, null);
  assert.equal(result.provisioned, null);
});

test("activates a pending tenant subscription", async () => {
  const { fetch, seen } = partnerFetch(() =>
    Response.json({
      data: {
        subscription: SUBSCRIPTION,
        provisioning: {
          pool_created: false,
          pool_credits: 0,
          caps_created: [{ child: "tenant", id: 7, cap: 2000 }],
        },
      },
    }),
  );

  const result = await client(fetch).activateTenantSubscription({
    subscriptionUuid: "3f1b1f70-0000-4000-8000-000000000001",
    reference: "bank transfer",
  });

  assert.match(
    seen[0].url,
    /\/subscriptions\/3f1b1f70-0000-4000-8000-000000000001\/activate$/,
  );
  assert.equal(seen[0].body.reference, "bank transfer");
  assert.equal(result.provisioned.capsCreated[0].cap, 2000);
});

test("lists the partner's tenant subscriptions", async () => {
  const { fetch } = partnerFetch(() =>
    Response.json({ data: { subscriptions: [SUBSCRIPTION] } }),
  );

  const subscriptions = await client(fetch).listTenantSubscriptions();

  assert.equal(subscriptions.length, 1);
  assert.equal(subscriptions[0].billingCycle, "monthly");
  assert.equal(subscriptions[0].paymentTransactionUuid, null);
});
