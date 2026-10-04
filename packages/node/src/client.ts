import {
  IOCloudAPIError,
  IOCloudAuthenticationError,
  IOCloudFederationError,
  IOCloudTokenExchangeError,
} from "./errors.js";
import type { SubjectTokenIssuer } from "./federation.js";
import { originOf, pathOf, resolveJwksPath } from "./jwks.js";
import type {
  ActivateTenantSubscriptionInput,
  ActivateTenantTopupInput,
  CreateIdentityProviderInput,
  CreateTenantInput,
  CreateTopupPackageInput,
  CreateUserInput,
  FederatedLoginInput,
  FederatedSession,
  GrantTenantTopupInput,
  IdentityProvider,
  IssueTenantTokenInput,
  JsonWebKeySet,
  PartnerToken,
  PlanSubscription,
  ProvisionedBalance,
  ProvisionedTopup,
  SetTenantExternalIdInput,
  SetUserPersonaInput,
  SubjectTokenClaimNames,
  SubscribeTenantInput,
  Tenant,
  TenantCredential,
  TenantPlan,
  TenantSubscription,
  TenantToken,
  TenantTopup,
  TopupPackage,
  TopupPackagePlan,
  TopupPurchase,
  UpdateTopupPackageInput,
  UpdateUserStatusInput,
  User,
} from "./models.js";

type JsonObject = Record<string, unknown>;

/** RFC 8693 / RFC 7519 URNs identifying the exchange grant and token types. */
export const TOKEN_EXCHANGE_GRANT_TYPE =
  "urn:ietf:params:oauth:grant-type:token-exchange";
export const JWT_TOKEN_TYPE = "urn:ietf:params:oauth:token-type:jwt";

const DEFAULT_ALLOWED_ALGORITHMS = ["RS256"];
const DEFAULT_TOKEN_MAX_AGE_SECONDS = 900;
const DEFAULT_CLAIM_NAMES: SubjectTokenClaimNames = {
  user: "sub",
  tenant: "tenant_id",
  email: "email",
  name: "name",
};

export interface IOCloudClientOptions {
  /** Needed only for partner-authenticated calls, not for federation. */
  clientId?: string;
  /** Needed only for partner-authenticated calls, not for federation. */
  clientSecret?: string;
  baseUrl: string;
  timeoutMs?: number;
  fetch?: typeof globalThis.fetch;
  /**
   * Only needed for {@link IOCloudClient.federatedLogin}; supply it and the
   * endpoint your frontend fetches its subject token from becomes a single call.
   */
  tokenIssuer?: SubjectTokenIssuer;
}

export class IOCloudClient {
  readonly #clientId: string;
  readonly #clientSecret: string;
  readonly #baseUrl: string;
  readonly #timeoutMs: number;
  readonly #fetch: typeof globalThis.fetch;
  readonly #tokenIssuer: SubjectTokenIssuer | undefined;
  #partnerToken?: PartnerToken;
  readonly #tenantTokens = new Map<string, TenantToken>();

  constructor(options: IOCloudClientOptions) {
    // The partner credentials are checked when they are first used rather than
    // here: publishing a JWKS and exchanging a subject token need no partner
    // token, so federation works before those credentials are configured.
    this.#clientId = options.clientId ?? "";
    this.#clientSecret = options.clientSecret ?? "";
    this.#baseUrl = required(options.baseUrl, "baseUrl").replace(/\/$/, "");
    this.#timeoutMs = options.timeoutMs ?? 30_000;
    this.#fetch = options.fetch ?? globalThis.fetch;
    this.#tokenIssuer = options.tokenIssuer;
  }

  async issuePartnerToken(forceRefresh = false): Promise<PartnerToken> {
    if (!this.#clientId.trim() || !this.#clientSecret.trim()) {
      throw new TypeError(
        "This call needs partner client credentials. Construct the client with" +
          " clientId and clientSecret.",
      );
    }
    if (!forceRefresh && tokenIsFresh(this.#partnerToken)) {
      return this.#partnerToken;
    }

    const data = await this.request("POST", "/v1/partner/auth/token", {
      client_id: this.#clientId,
      client_secret: this.#clientSecret,
    });
    this.#partnerToken = parseToken(record(data.token));
    return this.#partnerToken;
  }

  /**
   * Create a tenant (space) inside an application owned by the partner.
   *
   * The platform generates the tenant's slug from `name` and returns it as
   * `slug` on the tenant. `contactEmail` is optional: omit it for none.
   *
   * `externalId` is your own id for the organisation — the value your subject
   * tokens carry in the tenant claim — and must be unique within the
   * application. Omit it for a tenant nobody logs into yet and set it later
   * with {@link setTenantExternalId}.
   */
  async createTenant(input: CreateTenantInput): Promise<Tenant> {
    const payload: JsonObject = { name: input.name };
    const contactEmail = input.contactEmail ?? null;
    if (contactEmail !== null) payload.contact_email = contactEmail;
    const externalId = input.externalId ?? null;
    if (externalId !== null) payload.external_id = externalId;
    const data = await this.partnerRequest(
      "POST",
      `/v1/partner/applications/${input.applicationUuid}/tenants`,
      payload,
    );
    return parseTenant(record(data.tenant));
  }

  /**
   * Set, change or clear the id your subject tokens carry for a tenant.
   *
   * A federated login resolves its tenant claim to the tenant of the identity
   * provider's application whose `externalId` equals it, so this is what makes
   * a tenant reachable. The id is unique within the application, and every
   * provider of that application must sign the same one. `externalId: null`
   * clears it, which stops federated logins into the tenant.
   */
  async setTenantExternalId(input: SetTenantExternalIdInput): Promise<Tenant> {
    const data = await this.partnerRequest(
      "PATCH",
      `/v1/partner/tenants/${input.tenantUuid}/external-id`,
      { external_id: input.externalId },
    );
    return parseTenant(record(data.tenant));
  }

  /** List the tenant plans this partner offers. */
  async listTenantPlans(
    options: { page?: number; limit?: number } = {},
  ): Promise<TenantPlan[]> {
    const page = options.page ?? 1;
    const limit = options.limit ?? 25;
    const data = await this.partnerRequest(
      "GET",
      `/v1/partner/plans/tenant?page=${page}&limit=${limit}`,
    );
    return array(data.list).map((item) => parseTenantPlan(record(item)));
  }

  /**
   * Put one of the partner's tenants on one of the partner's plans.
   *
   * Tenants are the partner's clients and never pay this platform, so the
   * partner owns both halves of the flow. With `activateNow` (the default) the
   * subscription is created AND activated in one call: the billing window opens
   * and the tenant's balance is provisioned as child-cap rows — the tenant's
   * own cap from the plan's `credits`, plus one per active user from
   * `userCreditsCap`.
   *
   * Pass `activateNow: false` to record the intent first (status
   * `pending_payment`) and call {@link activateTenantSubscription} once the
   * client has actually paid.
   */
  async subscribeTenant(input: SubscribeTenantInput): Promise<TenantSubscription> {
    const data = await this.partnerRequest(
      "POST",
      "/v1/partner/plans/tenant/subscriptions",
      {
        tenant_uuid: input.tenantUuid,
        plan_uuid: input.planUuid,
        billing_cycle: input.billingCycle ?? "monthly",
        activate_now: input.activateNow ?? true,
        reference: input.reference ?? null,
      },
    );
    return parseTenantSubscription(data);
  }

  /**
   * Activate a pending tenant subscription and provision its balance.
   *
   * Call this after collecting payment from the tenant in your own billing
   * system. Idempotent: activating an already-active subscription returns it
   * unchanged with `provisioned` null.
   */
  async activateTenantSubscription(
    input: ActivateTenantSubscriptionInput,
  ): Promise<TenantSubscription> {
    const data = await this.partnerRequest(
      "POST",
      `/v1/partner/plans/tenant/subscriptions/${input.subscriptionUuid}/activate`,
      { reference: input.reference ?? null },
    );
    return parseTenantSubscription(data);
  }

  /** List every subscription held by this partner's tenants. */
  async listTenantSubscriptions(): Promise<PlanSubscription[]> {
    const data = await this.partnerRequest(
      "GET",
      "/v1/partner/plans/tenant/subscriptions",
    );
    return array(data.subscriptions).map((item) =>
      parsePlanSubscription(record(item)),
    );
  }

  /**
   * List the top-up packages this partner offers its tenants.
   *
   * These are the packages you authored. What the *platform* sells you is a
   * separate catalogue, reached through the dashboard.
   */
  async listTopupPackages(
    options: { page?: number; limit?: number } = {},
  ): Promise<TopupPackage[]> {
    const page = options.page ?? 1;
    const limit = options.limit ?? 25;
    const data = await this.partnerRequest(
      "GET",
      `/v1/partner/topup-packages?page=${page}&limit=${limit}`,
    );
    return array(data.list).map((item) => parseTopupPackage(record(item)));
  }

  /**
   * Create a credit bundle your tenants can buy.
   *
   * `planUuids` names your own tenant plans and is what lets two plans carry
   * different offers: a package scoped to Bronze is invisible to a tenant on
   * Silver. Pass none to offer it to every tenant.
   */
  async createTopupPackage(
    input: CreateTopupPackageInput,
  ): Promise<TopupPackage> {
    const data = await this.partnerRequest("POST", "/v1/partner/topup-packages", {
      name: input.name,
      credits: input.credits,
      price_cents: input.priceCents,
      validity_days: input.validityDays ?? null,
      plan_uuids: input.planUuids ?? [],
    });
    return parseTopupPackage(record(data.package));
  }

  /**
   * Update one of your packages. Omitted fields are left unchanged.
   *
   * Editing changes what the package sells next, never what it already sold:
   * existing purchases keep the credits snapshotted at purchase time.
   * `status: "inactive"` withdraws it from the catalogue. Omitting `planUuids`
   * keeps the current scoping; `[]` clears it.
   */
  async updateTopupPackage(
    input: UpdateTopupPackageInput,
  ): Promise<TopupPackage> {
    const body: Record<string, unknown> = {};
    if (input.name !== undefined) body.name = input.name;
    if (input.credits !== undefined) body.credits = input.credits;
    if (input.priceCents !== undefined) body.price_cents = input.priceCents;
    if (input.validityDays !== undefined) body.validity_days = input.validityDays;
    if (input.status !== undefined) body.status = input.status;
    if (input.planUuids !== undefined) body.plan_uuids = input.planUuids;
    const data = await this.partnerRequest(
      "PATCH",
      `/v1/partner/topup-packages/${input.packageUuid}`,
      body,
    );
    return parseTopupPackage(record(data.package));
  }

  /**
   * Sell one of your tenants a top-up.
   *
   * Same shape as {@link subscribeTenant}, and for the same reason: tenants
   * are your clients and never pay this platform, so you own both halves. With
   * `activateNow` (the default) the credits are spendable when this resolves —
   * a top-up credit pool owned by the tenant, drawn on before your own
   * balance.
   *
   * Pass `activateNow: false` to record the purchase first (status `pending`)
   * and call {@link activateTenantTopup} once the client has paid. The package
   * must be one that tenant is actually offered, so a plan-scoped package
   * cannot be granted to a tenant on the wrong plan.
   */
  async grantTenantTopup(input: GrantTenantTopupInput): Promise<TenantTopup> {
    const data = await this.partnerRequest(
      "POST",
      "/v1/partner/topups/tenant/purchases",
      {
        tenant_uuid: input.tenantUuid,
        package_uuid: input.packageUuid,
        activate_now: input.activateNow ?? true,
        reference: input.reference ?? null,
      },
    );
    return parseTenantTopup(data);
  }

  /**
   * Activate a pending tenant top-up and provision its credit pool.
   *
   * Call this after collecting payment in your own billing system. Idempotent:
   * activating an already-active purchase returns it unchanged with
   * `provisioned` null, so a retry never grants the credits twice.
   */
  async activateTenantTopup(
    input: ActivateTenantTopupInput,
  ): Promise<TenantTopup> {
    const data = await this.partnerRequest(
      "POST",
      `/v1/partner/topups/tenant/purchases/${input.transactionUuid}/activate`,
      { reference: input.reference ?? null },
    );
    return parseTenantTopup(data);
  }

  /** List every top-up bought by one of this partner's tenants. */
  async listTenantTopups(): Promise<TopupPurchase[]> {
    const data = await this.partnerRequest(
      "GET",
      "/v1/partner/topups/tenant/purchases",
    );
    return array(data.purchases).map((item) => parseTopupPurchase(record(item)));
  }

  /**
   * Register the partner's own issuer as a trusted identity provider.
   *
   * `applicationUuid` names the application the provider belongs to: a token it
   * signs logs users into that application's tenants only. An application may
   * have several providers, and any of them logs in any of its users, so they
   * must all sign the same tenant and user ids.
   *
   * `jwksPath` is where the platform fetches the issuer's keys: a path on the
   * issuer's origin, under `/.well-known/`. It defaults to
   * `/.well-known/jwks.json`, the platform's default and the path the SDK's
   * JWKS document is meant to be served from; a {@link SubjectTokenIssuer}'s is
   * its `jwksPath`. Pass the same `claimNames` as the issuer that signs the
   * tokens, so the two configurations cannot drift apart.
   *
   * `jwksUrl` is deprecated: pass `jwksPath`. It is still accepted, and only its
   * path is sent, so it must be on the issuer's own origin and carry no query
   * or fragment; anything else throws a `TypeError` before any request, as does
   * passing both.
   *
   * `allowJitTenants` lets a login whose tenant claim names no tenant of the
   * application create it, from the `tenant` passed to {@link federatedLogin}.
   * It requires `allowJitUsers`, since that tenant's users can only be created
   * at login: the platform answers 422 to one without the other.
   */
  async createIdentityProvider(
    input: CreateIdentityProviderInput,
  ): Promise<IdentityProvider> {
    const issuer = input.issuer.replace(/\/+$/, "");
    // `?? undefined`: a JavaScript caller's null means "not given", as before.
    const jwksUrl = input.jwksUrl ?? undefined;
    if (jwksUrl !== undefined) warnJwksUrlDeprecated();
    const jwksPath = resolveJwksPath(issuer, input.jwksPath ?? undefined, jwksUrl);
    const claimNames = { ...DEFAULT_CLAIM_NAMES, ...input.claimNames };
    const data = await this.partnerRequest(
      "POST",
      "/v1/partner/federation/providers",
      {
        application_uuid: input.applicationUuid,
        name: input.name,
        issuer,
        jwks_path: jwksPath,
        allowed_audiences: input.allowedAudiences,
        allowed_algorithms: input.allowedAlgorithms ?? DEFAULT_ALLOWED_ALGORITHMS,
        token_max_age_seconds:
          input.tokenMaxAgeSeconds ?? DEFAULT_TOKEN_MAX_AGE_SECONDS,
        require_email_verified: input.requireEmailVerified ?? false,
        user_claim: claimNames.user,
        tenant_claim: claimNames.tenant,
        email_claim: claimNames.email,
        name_claim: claimNames.name,
        allow_jit_users: input.allowJitUsers ?? false,
        allow_jit_tenants: input.allowJitTenants ?? false,
      },
    );
    return parseIdentityProvider(record(data.provider));
  }

  /** List the identity providers registered by this partner. */
  async listIdentityProviders(): Promise<IdentityProvider[]> {
    const data = await this.partnerRequest(
      "GET",
      "/v1/partner/federation/providers",
    );
    return array(data.providers).map((provider) =>
      parseIdentityProvider(record(provider)),
    );
  }

  /**
   * Exchange a partner-signed OIDC JWT for a platform session (RFC 8693).
   *
   * Needs no partner token: the subject token is the credential, and trust is
   * decided by the identity provider its `iss` resolves to. Throws
   * {@link IOCloudTokenExchangeError} when the platform rejects the token.
   */
  /**
   * The public key set to publish at `<issuer>/.well-known/jwks.json`.
   *
   * Return it straight from a route handler — this is the whole JWKS endpoint.
   * The path is yours to choose, on the issuer's origin and under
   * `/.well-known/`; it only has to match the `jwksPath` registered with the
   * platform. Contains public key material only, and is safe to cache.
   */
  jwks(): JsonWebKeySet {
    return this.#requireTokenIssuer("jwks").jwks();
  }

  /**
   * The issuer, audience, JWKS URL and path, and key id this client signs
   * under. `jwksPath` is what to register with the platform; `jwksUrl` is where
   * to serve {@link jwks}.
   */
  federationDetails(): {
    issuer: string;
    audience: string;
    jwksUrl: string;
    jwksPath: string;
    kid: string;
  } {
    const tokenIssuer = this.#requireTokenIssuer("federationDetails");
    return {
      issuer: tokenIssuer.issuer,
      audience: tokenIssuer.audience,
      jwksUrl: tokenIssuer.jwksUrl,
      jwksPath: tokenIssuer.jwksPath,
      kid: tokenIssuer.signingKey.kid,
    };
  }

  async exchangeSubjectToken(subjectToken: string): Promise<FederatedSession> {
    if (!subjectToken?.trim()) {
      throw new TypeError("subjectToken must not be empty");
    }

    const response = await this.#fetch(`${this.#baseUrl}/v1/federation/token`, {
      method: "POST",
      headers: { "content-type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        grant_type: TOKEN_EXCHANGE_GRANT_TYPE,
        subject_token: subjectToken,
        subject_token_type: JWT_TOKEN_TYPE,
      }).toString(),
      signal: AbortSignal.timeout(this.#timeoutMs),
    });
    const text = await response.text();
    const body = parseJsonObject(text);

    if (!response.ok) {
      throw new IOCloudTokenExchangeError(
        response.status,
        typeof body.error === "string" ? body.error : "invalid_grant",
        typeof body.error_description === "string"
          ? body.error_description
          : text || "The subject token was rejected.",
      );
    }
    return parseFederatedSession(body);
  }

  /**
   * Sign the subject token for a logged-in partner user, and send nothing.
   *
   * Return it to your frontend: the chat client fetches it from your backend
   * and exchanges it at the platform's `/v1/federation/token` itself, so the
   * platform session never passes through your backend. A backend that wants
   * the session anyway passes the token to {@link exchangeSubjectToken}.
   * Requires `tokenIssuer` on the client options.
   *
   * `tenant` is the tenant to create if this is its first login, on a provider
   * that allows just-in-time tenants; the exchange's `tenant_created` says
   * whether that login created it.
   */
  federatedLogin(input: FederatedLoginInput): string {
    return this.#requireTokenIssuer("federatedLogin").issue(input);
  }

  /**
   * The configured token issuer, or a message naming what to configure.
   *
   * Federation is optional, so every federation entry point checks here rather
   * than failing when the client is constructed.
   */
  #requireTokenIssuer(calledMethod: string): SubjectTokenIssuer {
    if (this.#tokenIssuer === undefined) {
      throw new IOCloudFederationError(
        `${calledMethod}() needs a tokenIssuer. Construct the client with` +
          " tokenIssuer: new SubjectTokenIssuer(...).",
      );
    }
    return this.#tokenIssuer;
  }

  async createTenantCredentials(
    tenantUuid: string,
    name = "realestate-persona-sync",
  ): Promise<TenantCredential> {
    const data = await this.partnerRequest(
      "POST",
      `/v1/partner/tenants/${tenantUuid}/credentials`,
      { name },
    );
    const credential = record(data.credential);
    return {
      credentialUuid: string(credential.credential_uuid),
      tenantUuid: string(credential.tenant_uuid),
      clientId: string(credential.client_id),
      clientSecret: string(credential.client_secret),
    };
  }

  async issueTenantToken(input: IssueTenantTokenInput): Promise<TenantToken> {
    const cached = this.#tenantTokens.get(input.clientId);
    if (!input.forceRefresh && tokenIsFresh(cached)) {
      return cached;
    }

    const data = await this.request("POST", "/v1/tenant/auth/token", {
      client_id: input.clientId,
      client_secret: input.clientSecret,
    });
    const token = parseToken(record(data.token));
    this.#tenantTokens.set(input.clientId, token);
    return token;
  }

  async setUserPersona(input: SetUserPersonaInput): Promise<JsonObject> {
    const path = `/v1/tenant/users/${input.userUuid}/persona`;
    const payload = { persona: input.persona };
    let token = await this.issueTenantToken({
      clientId: input.tenantClientId,
      clientSecret: input.tenantClientSecret,
    });
    try {
      return await this.request("PATCH", path, payload, token.accessToken);
    } catch (error) {
      if (!(error instanceof IOCloudAuthenticationError)) throw error;
      token = await this.issueTenantToken({
        clientId: input.tenantClientId,
        clientSecret: input.tenantClientSecret,
        forceRefresh: true,
      });
      return this.request("PATCH", path, payload, token.accessToken);
    }
  }

  /**
   * Create a user inside the tenant a tenant credential belongs to.
   *
   * `externalId` is your own id for the person — the value your subject tokens
   * carry in the user claim (`sub` by default) — and is how a federated login
   * finds the user within the token's tenant. Pre-create users this way when
   * the identity provider does not provision them just in time
   * (`allowJitUsers: false`).
   *
   * The route is tenant-scoped, so the call authenticates with the credential
   * from {@link createTenantCredentials}, refreshing the tenant token once on a
   * 401. The user starts `pending`: activate it with {@link updateUserStatus}
   * before it can log in.
   */
  async createUser(input: CreateUserInput): Promise<User> {
    const payload: JsonObject = { name: input.name, email: input.email };
    const externalId = input.externalId ?? null;
    if (externalId !== null) payload.external_id = externalId;
    const data = await this.tenantRequest("POST", "/v1/tenant/users", payload, {
      clientId: input.tenantClientId,
      clientSecret: input.tenantClientSecret,
    });
    return parseUser(record(data.user));
  }

  /**
   * Activate (`"active"`) or deactivate (`"deactivated"`) a user.
   *
   * Tenant-scoped like {@link createUser}, and authenticated the same way. A
   * pending user cannot log in until this activates it.
   */
  async updateUserStatus(input: UpdateUserStatusInput): Promise<User> {
    const data = await this.tenantRequest(
      "PATCH",
      `/v1/tenant/users/${input.userUuid}/status`,
      { status: input.status },
      { clientId: input.tenantClientId, clientSecret: input.tenantClientSecret },
    );
    return parseUser(record(data.user));
  }

  private async partnerRequest(
    method: string,
    path: string,
    payload?: JsonObject,
  ): Promise<JsonObject> {
    let token = await this.issuePartnerToken();
    try {
      return await this.request(method, path, payload, token.accessToken);
    } catch (error) {
      if (!(error instanceof IOCloudAuthenticationError)) throw error;
      token = await this.issuePartnerToken(true);
      return this.request(method, path, payload, token.accessToken);
    }
  }

  /**
   * The tenant-token twin of {@link partnerRequest}: issues (or reuses) the
   * credential's tenant token and refreshes it once on a 401.
   */
  private async tenantRequest(
    method: string,
    path: string,
    payload: JsonObject,
    credential: { clientId: string; clientSecret: string },
  ): Promise<JsonObject> {
    let token = await this.issueTenantToken(credential);
    try {
      return await this.request(method, path, payload, token.accessToken);
    } catch (error) {
      if (!(error instanceof IOCloudAuthenticationError)) throw error;
      token = await this.issueTenantToken({ ...credential, forceRefresh: true });
      return this.request(method, path, payload, token.accessToken);
    }
  }

  private async request(
    method: string,
    path: string,
    payload?: JsonObject,
    accessToken?: string,
  ): Promise<JsonObject> {
    const headers: Record<string, string> = {};
    if (payload !== undefined) headers["content-type"] = "application/json";
    if (accessToken) headers.authorization = `Bearer ${accessToken}`;

    const response = await this.#fetch(`${this.#baseUrl}${path}`, {
      method,
      headers,
      // A GET carries no body; sending one is rejected by some proxies.
      ...(payload === undefined ? {} : { body: JSON.stringify(payload) }),
      signal: AbortSignal.timeout(this.#timeoutMs),
    });
    const text = await response.text();
    const body = parseJsonObject(text);

    if (response.ok) return record(body.data ?? body);

    const ErrorType = response.status === 401
      ? IOCloudAuthenticationError
      : IOCloudAPIError;
    throw new ErrorType(
      response.status,
      typeof body.code === "string" ? body.code : "IOCLOUD_API_ERROR",
      typeof body.message === "string"
        ? body.message
        : text || "IOCloud API request failed.",
    );
  }
}

function parseToken(payload: JsonObject): PartnerToken {
  return {
    accessToken: string(payload.access_token),
    tokenType: string(payload.token_type),
    expiresAt: new Date(string(payload.expires_at)),
  };
}

function parseTenant(payload: JsonObject): Tenant {
  return {
    uuid: string(payload.uuid),
    applicationUuid: string(payload.application_uuid),
    name: string(payload.name),
    slug: string(payload.slug),
    contactEmail: nullableString(payload.contact_email),
    externalId: nullableString(payload.external_id),
    status: string(payload.status),
    createdAt: new Date(string(payload.created_at)),
  };
}

function parseUser(payload: JsonObject): User {
  return {
    uuid: string(payload.uuid),
    tenantUuid: string(payload.tenant_uuid),
    name: string(payload.name),
    email: string(payload.email),
    externalId: nullableString(payload.external_id),
    status: string(payload.status),
    createdAt: new Date(string(payload.created_at)),
  };
}

function parseTenantPlan(payload: JsonObject): TenantPlan {
  return {
    uuid: string(payload.uuid),
    name: string(payload.name),
    // Absent from a platform that predates plan codes.
    planCode: nullableString(payload.plan_code),
    monthlyPriceCents: integer(payload.monthly_price_cents),
    yearlyPriceCents: integer(payload.yearly_price_cents),
    tpm: integer(payload.tpm),
    rpm: integer(payload.rpm),
    credits: integer(payload.credits),
    userCreditsCap: integer(payload.user_credits_cap),
    userTpm: integer(payload.user_tpm),
    userRpm: integer(payload.user_rpm),
  };
}

function parsePlanSubscription(payload: JsonObject): PlanSubscription {
  return {
    uuid: string(payload.uuid),
    status: string(payload.status),
    planType: string(payload.plan_type),
    billingCycle: string(payload.billing_cycle),
    // Null while pending payment: the window opens only at activation.
    subscribedFrom: payload.subscribed_from
      ? new Date(string(payload.subscribed_from))
      : null,
    subscribedTo: payload.subscribed_to
      ? new Date(string(payload.subscribed_to))
      : null,
    paymentTransactionUuid: payload.payment_transaction_uuid
      ? string(payload.payment_transaction_uuid)
      : null,
    createdAt: new Date(string(payload.created_at)),
  };
}

function parseProvisionedBalance(payload: JsonObject): ProvisionedBalance {
  return {
    poolCreated: Boolean(payload.pool_created),
    poolCredits: integer(payload.pool_credits ?? 0),
    capsCreated: array(payload.caps_created).map((item) => {
      const cap = record(item);
      return {
        child: string(cap.child),
        id: integer(cap.id),
        cap: integer(cap.cap),
      };
    }),
  };
}

function parseTopupPackagePlan(payload: JsonObject): TopupPackagePlan {
  return {
    planType: string(payload.plan_type) as TopupPackagePlan["planType"],
    planUuid: string(payload.plan_uuid),
    planName: string(payload.plan_name),
  };
}

function parseTopupPackage(payload: JsonObject): TopupPackage {
  return {
    uuid: string(payload.uuid),
    name: string(payload.name),
    credits: integer(payload.credits),
    priceCents: integer(payload.price_cents),
    // Null means the credits never expire.
    validityDays:
      payload.validity_days === null || payload.validity_days === undefined
        ? null
        : integer(payload.validity_days),
    status: string(payload.status),
    audience: payload.audience ? string(payload.audience) : null,
    // Absent or empty: the package is offered to every subscriber.
    plans: (payload.plans ? array(payload.plans) : []).map((item) =>
      parseTopupPackagePlan(record(item)),
    ),
  };
}

function parseTopupPurchase(payload: JsonObject): TopupPurchase {
  return {
    uuid: string(payload.uuid),
    tenantUuid: string(payload.tenant_uuid),
    tenantName: string(payload.tenant_name),
    packageUuid: payload.package_uuid ? string(payload.package_uuid) : null,
    packageName: payload.package_name ? string(payload.package_name) : null,
    credits: integer(payload.credits),
    status: string(payload.status),
    validFrom: payload.valid_from ? new Date(string(payload.valid_from)) : null,
    // Null means the credits never expire.
    validTo: payload.valid_to ? new Date(string(payload.valid_to)) : null,
    createdAt: new Date(string(payload.created_at)),
  };
}

function parseProvisionedTopup(payload: JsonObject): ProvisionedTopup {
  return {
    poolCreated: Boolean(payload.pool_created),
    poolCredits: integer(payload.pool_credits ?? 0),
  };
}

function parseTenantTopup(payload: JsonObject): TenantTopup {
  const provisioning = payload.provisioning;
  return {
    purchase: parseTopupPurchase(record(payload.purchase)),
    provisioned:
      provisioning && typeof provisioning === "object"
        ? parseProvisionedTopup(record(provisioning))
        : null,
  };
}

function parseTenantSubscription(payload: JsonObject): TenantSubscription {
  const provisioning = payload.provisioning;
  return {
    subscription: parsePlanSubscription(record(payload.subscription)),
    provisioned:
      provisioning && typeof provisioning === "object"
        ? parseProvisionedBalance(record(provisioning))
        : null,
  };
}

function parseIdentityProvider(payload: JsonObject): IdentityProvider {
  const issuer = string(payload.issuer);
  // What the platform fetches; it stopped sending the URL itself.
  const jwksUrl =
    payload.jwks_url === undefined || payload.jwks_url === null
      ? string(payload.issuer_origin) + string(payload.jwks_path)
      : string(payload.jwks_url);
  return {
    uuid: string(payload.uuid),
    applicationUuid: string(payload.application_uuid),
    name: string(payload.name),
    issuer,
    // Both absent from a platform that predates them: derived from the
    // issuer and the JWKS URL.
    issuerOrigin:
      nullableString(payload.issuer_origin) ?? originOf(issuer) ?? "",
    jwksPath: nullableString(payload.jwks_path) ?? pathOf(jwksUrl),
    jwksUrl,
    allowedAudiences: array(payload.allowed_audiences).map(string),
    allowedAlgorithms: array(payload.allowed_algorithms).map(string),
    tokenMaxAgeSeconds: integer(payload.token_max_age_seconds),
    requireEmailVerified: Boolean(payload.require_email_verified),
    claimNames: {
      user: string(payload.user_claim),
      tenant: string(payload.tenant_claim),
      email: string(payload.email_claim),
      name: string(payload.name_claim),
    },
    allowJitUsers: Boolean(payload.allow_jit_users),
    allowJitTenants: Boolean(payload.allow_jit_tenants),
    status: string(payload.status),
    createdAt: new Date(string(payload.created_at)),
  };
}

function parseFederatedSession(payload: JsonObject): FederatedSession {
  const expiresIn = integer(payload.expires_in);
  return {
    accessToken: string(payload.access_token),
    tokenType: string(payload.token_type),
    issuedTokenType: string(payload.issued_token_type),
    expiresIn,
    // The wire format is a relative lifetime; an absolute instant is what
    // callers need to store alongside a persisted session.
    expiresAt: new Date(Date.now() + expiresIn * 1000),
    userUuid: string(payload.user_uuid),
    name: string(payload.name),
    email: string(payload.email),
    // Both absent from a platform that predates just-in-time tenants.
    tenantUuid: nullableString(payload.tenant_uuid),
    tenantCreated: Boolean(payload.tenant_created),
  };
}

function parseJsonObject(text: string): JsonObject {
  if (!text) return {};
  try {
    const parsed: unknown = JSON.parse(text);
    return typeof parsed === "object" && parsed !== null && !Array.isArray(parsed)
      ? (parsed as JsonObject)
      : {};
  } catch {
    return {};
  }
}

let jwksUrlDeprecationWarned = false;

/** Once per process, as Node's own deprecations are. */
function warnJwksUrlDeprecated(): void {
  if (jwksUrlDeprecationWarned) return;
  jwksUrlDeprecationWarned = true;
  globalThis.process?.emitWarning?.(
    "createIdentityProvider({ jwksUrl }) is deprecated: pass jwksPath, the path " +
      "on the issuer's origin, instead.",
    { type: "DeprecationWarning", code: "IOCLOUD_JWKS_URL" },
  );
}

function tokenIsFresh(token?: PartnerToken): token is PartnerToken {
  return token !== undefined && token.expiresAt.getTime() > Date.now() + 30_000;
}

function required(value: string, name: string): string {
  if (!value.trim()) throw new TypeError(`${name} must not be empty`);
  return value;
}

function record(value: unknown): JsonObject {
  if (typeof value !== "object" || value === null || Array.isArray(value)) {
    throw new TypeError("IOCloud API returned an unexpected response shape");
  }
  return value as JsonObject;
}

function array(value: unknown): unknown[] {
  if (!Array.isArray(value)) {
    throw new TypeError("IOCloud API returned an unexpected response shape");
  }
  return value;
}

function string(value: unknown): string {
  if (typeof value !== "string") {
    throw new TypeError("IOCloud API returned an unexpected response shape");
  }
  return value;
}

/** A nullable string field: `null` when the response sends null or omits it. */
function nullableString(value: unknown): string | null {
  return value === null || value === undefined ? null : string(value);
}

function integer(value: unknown): number {
  if (typeof value !== "number" || !Number.isFinite(value)) {
    throw new TypeError("IOCloud API returned an unexpected response shape");
  }
  return value;
}
