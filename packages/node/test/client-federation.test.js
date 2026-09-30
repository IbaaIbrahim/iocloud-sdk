import assert from "node:assert/strict";
import test from "node:test";

import {
  FederationSigningKey,
  IOCloudClient,
  IOCloudFederationError,
  IOCloudTokenExchangeError,
  JWT_TOKEN_TYPE,
  SubjectTokenIssuer,
  TOKEN_EXCHANGE_GRANT_TYPE,
} from "../dist/index.js";

const BASE_URL = "https://api.example.com";
const APPLICATION_UUID = "11111111-1111-4111-8111-111111111111";
const TENANT_UUID = "22222222-2222-4222-8222-222222222222";
const TENANT_NOT_CREATED = "The token's tenant could not be created.";
const TENANT_PLAN_MISSING = "The token's tenant plan does not exist.";
const TENANT_PROFILE = {
  name: "Acme Ltd",
  contactEmail: "ops@acme.example",
};
const PARTNER_TOKEN_BODY = {
  data: {
    token: {
      access_token: "partner-token",
      token_type: "Bearer",
      expires_at: "2099-01-01T00:00:00Z",
    },
  },
};
const PROVIDER_BODY = {
  uuid: "4be507fc-2a1b-4e19-9f0e-2c7f7f5f8a11",
  application_uuid: APPLICATION_UUID,
  name: "Acme Portal",
  issuer: "https://portal.acme.example",
  jwks_url: "https://portal.acme.example/.well-known/jwks.json",
  allowed_audiences: ["ai-ecosystem"],
  allowed_algorithms: ["RS256"],
  token_max_age_seconds: 900,
  require_email_verified: true,
  user_claim: "sub",
  tenant_claim: "tenant_id",
  email_claim: "email",
  name_claim: "name",
  allow_jit_users: true,
  status: "active",
  created_at: "2026-07-09T10:15:00Z",
};
const SESSION_BODY = {
  access_token: "platform-session-token",
  issued_token_type: "urn:ietf:params:oauth:token-type:access_token",
  token_type: "Bearer",
  expires_in: 3600,
  user_uuid: "992d64fc-8f2a-4c31-b7e5-1d0a6c9f3b48",
  name: "Test User",
  email: "user@customer.example",
};

/** Records every request and replies from a path-keyed response map. */
function recordingFetch(responses) {
  const requests = [];
  const fetch = async (input, init = {}) => {
    const url = new URL(input);
    requests.push({
      path: url.pathname,
      method: init.method ?? "GET",
      headers: new Headers(init.headers),
      body: init.body,
    });
    const build = responses[url.pathname];
    assert.ok(build, `unexpected request to ${url.pathname}`);
    return build();
  };
  const requestTo = (path) => {
    const request = requests.find((candidate) => candidate.path === path);
    assert.ok(request, `no request was sent to ${path}`);
    return request;
  };
  return { fetch, requests, requestTo };
}

const partnerTokenResponse = () => Response.json(PARTNER_TOKEN_BODY);

test("createIdentityProvider derives the JWKS URL from the issuer", async () => {
  const { fetch, requestTo } = recordingFetch({
    "/v1/partner/auth/token": partnerTokenResponse,
    "/v1/partner/federation/providers": () =>
      Response.json({ data: { provider: PROVIDER_BODY } }, { status: 201 }),
  });
  const client = new IOCloudClient({
    clientId: "client-id",
    clientSecret: "client-secret",
    baseUrl: BASE_URL,
    fetch,
  });

  const provider = await client.createIdentityProvider({
    applicationUuid: APPLICATION_UUID,
    name: "Acme Portal",
    issuer: "https://portal.acme.example/",
    allowedAudiences: ["ai-ecosystem"],
    requireEmailVerified: true,
    allowJitUsers: true,
  });

  const sent = requestTo("/v1/partner/federation/providers");
  const body = JSON.parse(sent.body);
  assert.equal(body.application_uuid, APPLICATION_UUID);
  assert.equal(body.issuer, "https://portal.acme.example");
  assert.equal(
    body.jwks_url,
    "https://portal.acme.example/.well-known/jwks.json",
  );
  assert.deepEqual(body.allowed_algorithms, ["RS256"]);
  assert.equal(body.token_max_age_seconds, 900);
  assert.equal(body.require_email_verified, true);
  assert.equal(body.allow_jit_users, true);
  assert.equal(sent.headers.get("authorization"), "Bearer partner-token");
  assert.equal(provider.uuid, PROVIDER_BODY.uuid);
  assert.equal(provider.applicationUuid, APPLICATION_UUID);
  assert.deepEqual(provider.claimNames, {
    user: "sub",
    tenant: "tenant_id",
    email: "email",
    name: "name",
  });
});

test("createIdentityProvider registers the claim names the issuer emits", async () => {
  const { fetch, requestTo } = recordingFetch({
    "/v1/partner/auth/token": partnerTokenResponse,
    "/v1/partner/federation/providers": () =>
      Response.json({ data: { provider: PROVIDER_BODY } }, { status: 201 }),
  });
  const client = new IOCloudClient({
    clientId: "client-id",
    clientSecret: "client-secret",
    baseUrl: BASE_URL,
    fetch,
  });

  await client.createIdentityProvider({
    applicationUuid: APPLICATION_UUID,
    name: "Acme Portal",
    issuer: "https://portal.acme.example",
    allowedAudiences: ["ai-ecosystem"],
    claimNames: { user: "user_id", tenant: "org_id" },
  });

  const body = JSON.parse(requestTo("/v1/partner/federation/providers").body);
  assert.equal(body.user_claim, "user_id");
  assert.equal(body.tenant_claim, "org_id");
  assert.equal(body.email_claim, "email");
  assert.equal(body.name_claim, "name");
});

test("createIdentityProvider sends allowJitTenants false by default", async () => {
  const { fetch, requestTo } = recordingFetch({
    "/v1/partner/auth/token": partnerTokenResponse,
    "/v1/partner/federation/providers": () =>
      Response.json({ data: { provider: PROVIDER_BODY } }, { status: 201 }),
  });
  const client = new IOCloudClient({
    clientId: "client-id",
    clientSecret: "client-secret",
    baseUrl: BASE_URL,
    fetch,
  });

  await client.createIdentityProvider({
    applicationUuid: APPLICATION_UUID,
    name: "Acme Portal",
    issuer: "https://portal.acme.example",
    allowedAudiences: ["ai-ecosystem"],
  });

  const body = JSON.parse(requestTo("/v1/partner/federation/providers").body);
  assert.equal(body.allow_jit_tenants, false);
});

test("createIdentityProvider sends allowJitTenants when asked and reads it back", async () => {
  const { fetch, requestTo } = recordingFetch({
    "/v1/partner/auth/token": partnerTokenResponse,
    "/v1/partner/federation/providers": () =>
      Response.json(
        { data: { provider: { ...PROVIDER_BODY, allow_jit_tenants: true } } },
        { status: 201 },
      ),
  });
  const client = new IOCloudClient({
    clientId: "client-id",
    clientSecret: "client-secret",
    baseUrl: BASE_URL,
    fetch,
  });

  const provider = await client.createIdentityProvider({
    applicationUuid: APPLICATION_UUID,
    name: "Acme Portal",
    issuer: "https://portal.acme.example",
    allowedAudiences: ["ai-ecosystem"],
    allowJitUsers: true,
    allowJitTenants: true,
  });

  const body = JSON.parse(requestTo("/v1/partner/federation/providers").body);
  assert.equal(body.allow_jit_users, true);
  assert.equal(body.allow_jit_tenants, true);
  assert.equal(provider.allowJitTenants, true);
});

test("a provider without allow_jit_tenants reads as false", async () => {
  // PROVIDER_BODY is what a platform that predates just-in-time tenants sends:
  // no allow_jit_tenants member at all.
  const { fetch } = recordingFetch({
    "/v1/partner/auth/token": partnerTokenResponse,
    "/v1/partner/federation/providers": () =>
      Response.json({ data: { providers: [PROVIDER_BODY] } }),
  });
  const client = new IOCloudClient({
    clientId: "client-id",
    clientSecret: "client-secret",
    baseUrl: BASE_URL,
    fetch,
  });

  const [provider] = await client.listIdentityProviders();

  assert.equal(provider.allowJitTenants, false);
});

test("listIdentityProviders sends a GET with no request body", async () => {
  const { fetch, requestTo } = recordingFetch({
    "/v1/partner/auth/token": partnerTokenResponse,
    "/v1/partner/federation/providers": () =>
      Response.json({ data: { providers: [PROVIDER_BODY] } }),
  });
  const client = new IOCloudClient({
    clientId: "client-id",
    clientSecret: "client-secret",
    baseUrl: BASE_URL,
    fetch,
  });

  const providers = await client.listIdentityProviders();

  const sent = requestTo("/v1/partner/federation/providers");
  assert.equal(sent.method, "GET");
  assert.equal(sent.body, undefined);
  assert.equal(sent.headers.has("content-type"), false);
  assert.equal(providers.length, 1);
  assert.equal(providers[0].status, "active");
  assert.equal(providers[0].applicationUuid, APPLICATION_UUID);
});

test("exchangeSubjectToken posts the RFC 8693 form grammar unauthenticated", async () => {
  const { fetch, requestTo } = recordingFetch({
    "/v1/federation/token": () => Response.json(SESSION_BODY),
  });
  const client = new IOCloudClient({
    clientId: "client-id",
    clientSecret: "client-secret",
    baseUrl: BASE_URL,
    fetch,
  });

  const session = await client.exchangeSubjectToken("signed.jwt.value");

  const sent = requestTo("/v1/federation/token");
  const form = new URLSearchParams(sent.body);
  assert.equal(form.get("grant_type"), TOKEN_EXCHANGE_GRANT_TYPE);
  assert.equal(form.get("subject_token"), "signed.jwt.value");
  assert.equal(form.get("subject_token_type"), JWT_TOKEN_TYPE);
  assert.equal(
    sent.headers.get("content-type"),
    "application/x-www-form-urlencoded",
  );
  assert.equal(sent.headers.has("authorization"), false);
  assert.equal(session.accessToken, "platform-session-token");
  assert.equal(session.expiresIn, 3600);
  assert.equal(session.userUuid, SESSION_BODY.user_uuid);
  assert.ok(session.expiresAt.getTime() > Date.now());
});

test("a session names its tenant and whether this login created it", async () => {
  const { fetch } = recordingFetch({
    "/v1/federation/token": () =>
      Response.json({
        ...SESSION_BODY,
        tenant_uuid: TENANT_UUID,
        tenant_created: true,
      }),
  });
  const client = new IOCloudClient({ baseUrl: BASE_URL, fetch });

  const session = await client.exchangeSubjectToken("signed.jwt.value");

  assert.equal(session.tenantUuid, TENANT_UUID);
  assert.equal(session.tenantCreated, true);
});

test("a platform without the tenant members reads as null and false", async () => {
  // SESSION_BODY is what a platform that predates just-in-time tenants sends:
  // neither tenant_uuid nor tenant_created.
  const { fetch } = recordingFetch({
    "/v1/federation/token": () => Response.json(SESSION_BODY),
  });
  const client = new IOCloudClient({ baseUrl: BASE_URL, fetch });

  const session = await client.exchangeSubjectToken("signed.jwt.value");

  assert.equal(session.tenantUuid, null);
  assert.equal(session.tenantCreated, false);
});

test("a rejected subject token raises the RFC 6749 error", async () => {
  const { fetch } = recordingFetch({
    "/v1/federation/token": () =>
      Response.json(
        {
          error: "invalid_target",
          error_description: "The token's tenant is not mapped.",
        },
        { status: 400 },
      ),
  });
  const client = new IOCloudClient({
    clientId: "client-id",
    clientSecret: "client-secret",
    baseUrl: BASE_URL,
    fetch,
  });

  await assert.rejects(
    client.exchangeSubjectToken("signed.jwt.value"),
    (error) =>
      error instanceof IOCloudTokenExchangeError &&
      error.statusCode === 400 &&
      error.error === "invalid_target" &&
      error.errorDescription === "The token's tenant is not mapped.",
  );
});

test("jwks() returns the document a route can publish", () => {
  const signingKey = FederationSigningKey.generate();
  const client = new IOCloudClient({
    clientId: "client-id",
    clientSecret: "client-secret",
    baseUrl: BASE_URL,
    tokenIssuer: new SubjectTokenIssuer({
      signingKey,
      issuer: "https://portal.acme.example",
      audience: "ai-ecosystem",
    }),
  });

  assert.deepEqual(client.jwks(), signingKey.jwks());
  assert.deepEqual(client.federationDetails(), {
    issuer: "https://portal.acme.example",
    audience: "ai-ecosystem",
    jwksUrl: "https://portal.acme.example/.well-known/jwks.json",
    kid: signingKey.kid,
  });
});

test("jwks() reports a missing issuer rather than an empty document", () => {
  const client = new IOCloudClient({
    clientId: "client-id",
    clientSecret: "client-secret",
    baseUrl: BASE_URL,
  });

  assert.throws(() => client.jwks(), IOCloudFederationError);
  assert.throws(() => client.federationDetails(), IOCloudFederationError);
});

test("publishing and exchanging need no partner credentials", async () => {
  // A partner publishes keys and federates logins before it has API credentials;
  // only partner-authenticated calls require them.
  const signingKey = FederationSigningKey.generate();
  const { fetch } = recordingFetch({
    "/v1/federation/token": () => Response.json(SESSION_BODY),
  });
  const client = new IOCloudClient({
    baseUrl: BASE_URL,
    fetch,
    tokenIssuer: new SubjectTokenIssuer({
      signingKey,
      issuer: "https://portal.acme.example",
      audience: "ai-ecosystem",
    }),
  });

  assert.deepEqual(client.jwks(), signingKey.jwks());
  const session = await client.exchangeSubjectToken(
    client.federatedLogin({ subject: "acme-user-1", externalTenantId: "acme-tenant-1" }),
  );
  assert.equal(session.accessToken, "platform-session-token");

  await assert.rejects(client.issuePartnerToken(), TypeError);
});

/** A token's header and claims, decoded; the issuer's own tests verify signatures. */
function decodeSubjectToken(subjectToken) {
  const [encodedHeader, encodedPayload] = subjectToken.split(".");
  return {
    header: JSON.parse(Buffer.from(encodedHeader, "base64url").toString()),
    claims: JSON.parse(Buffer.from(encodedPayload, "base64url").toString()),
  };
}

function signingClient(responses = {}) {
  const signingKey = FederationSigningKey.generate();
  const recorded = recordingFetch(responses);
  const client = new IOCloudClient({
    baseUrl: BASE_URL,
    fetch: recorded.fetch,
    tokenIssuer: new SubjectTokenIssuer({
      signingKey,
      issuer: "https://portal.acme.example",
      audience: "ai-ecosystem",
    }),
  });
  return { client, signingKey, ...recorded };
}

test("federatedLogin returns the signed subject token and sends nothing", () => {
  const { client, signingKey, requests } = signingClient();

  const subjectToken = client.federatedLogin({
    subject: "acme-user-1",
    externalTenantId: "acme-tenant-1",
    email: "user@customer.example",
    name: "Test User",
    emailVerified: true,
  });

  const { header, claims } = decodeSubjectToken(subjectToken);
  assert.equal(header.kid, signingKey.kid);
  assert.equal(claims.sub, "acme-user-1");
  assert.equal(claims.tenant_id, "acme-tenant-1");
  assert.equal(claims.email_verified, true);
  assert.ok(!("tenant_profile" in claims));
  assert.equal(requests.length, 0, "the frontend exchanges it, not the SDK");
});

test("federatedLogin signs the tenant profile into the token", () => {
  const { client } = signingClient();

  const { claims } = decodeSubjectToken(
    client.federatedLogin({
      subject: "acme-user-1",
      externalTenantId: "acme-tenant-1",
      email: "user@customer.example",
      tenant: TENANT_PROFILE,
    }),
  );

  assert.equal(claims.tenant_id, "acme-tenant-1");
  assert.deepEqual(claims.tenant_profile, {
    name: "Acme Ltd",
    contact_email: "ops@acme.example",
  });
});

test("a tenant profile carrying the externalTenantId names the tenant", () => {
  const { client } = signingClient();

  const { claims } = decodeSubjectToken(
    client.federatedLogin({
      subject: "acme-user-1",
      email: "user@customer.example",
      tenant: { name: "Acme Ltd", externalTenantId: "acme-tenant-1" },
    }),
  );

  assert.equal(claims.tenant_id, "acme-tenant-1");
  assert.deepEqual(claims.tenant_profile, { name: "Acme Ltd" });
});

test("a backend that wants the session exchanges the token itself", async () => {
  const { client, requestTo } = signingClient({
    "/v1/federation/token": () =>
      Response.json({ ...SESSION_BODY, tenant_uuid: TENANT_UUID, tenant_created: true }),
  });

  const subjectToken = client.federatedLogin({
    subject: "acme-user-1",
    externalTenantId: "acme-tenant-1",
    email: "user@customer.example",
    tenant: TENANT_PROFILE,
  });
  const session = await client.exchangeSubjectToken(subjectToken);

  const form = new URLSearchParams(requestTo("/v1/federation/token").body);
  assert.equal(form.get("subject_token"), subjectToken);
  assert.equal(session.accessToken, "platform-session-token");
  assert.equal(session.tenantUuid, TENANT_UUID);
  assert.equal(session.tenantCreated, true);
});

test("a tenant the platform could not create refuses the exchange", async () => {
  const { client } = signingClient({
    "/v1/federation/token": () =>
      Response.json(
        { error: "invalid_target", error_description: TENANT_NOT_CREATED },
        { status: 400 },
      ),
  });
  const subjectToken = client.federatedLogin({
    subject: "acme-user-1",
    externalTenantId: "acme-tenant-1",
    email: "user@customer.example",
    tenant: { name: "Acme Ltd" },
  });

  await assert.rejects(
    client.exchangeSubjectToken(subjectToken),
    (error) =>
      error instanceof IOCloudTokenExchangeError &&
      error.statusCode === 400 &&
      error.error === "invalid_target" &&
      error.errorDescription === TENANT_NOT_CREATED,
  );
});

test("a plan code no plan has refuses the exchange", async () => {
  const { client } = signingClient({
    "/v1/federation/token": () =>
      Response.json(
        { error: "invalid_target", error_description: TENANT_PLAN_MISSING },
        { status: 400 },
      ),
  });
  const subjectToken = client.federatedLogin({
    subject: "acme-user-1",
    externalTenantId: "acme-tenant-1",
    email: "user@customer.example",
    tenant: { name: "Acme Ltd", planCode: "no-such-plan" },
  });
  assert.equal(decodeSubjectToken(subjectToken).claims.tenant_profile.plan_code, "no-such-plan");

  await assert.rejects(
    client.exchangeSubjectToken(subjectToken),
    (error) =>
      error instanceof IOCloudTokenExchangeError &&
      error.statusCode === 400 &&
      error.error === "invalid_target" &&
      error.errorDescription === TENANT_PLAN_MISSING,
  );
});

test("federatedLogin reports a missing issuer before touching the network", () => {
  const { fetch, requests } = recordingFetch({});
  const client = new IOCloudClient({
    clientId: "client-id",
    clientSecret: "client-secret",
    baseUrl: BASE_URL,
    fetch,
  });

  assert.throws(
    () => client.federatedLogin({ subject: "user-1", externalTenantId: "tenant-1" }),
    IOCloudFederationError,
  );
  assert.equal(requests.length, 0);
});

test("an empty subject token never reaches the network", async () => {
  const { fetch, requests } = recordingFetch({});
  const client = new IOCloudClient({
    clientId: "client-id",
    clientSecret: "client-secret",
    baseUrl: BASE_URL,
    fetch,
  });

  await assert.rejects(client.exchangeSubjectToken("   "), TypeError);
  assert.equal(requests.length, 0);
});
