import {
  IOCloudAPIError,
  IOCloudAuthenticationError,
  IOCloudFederationError,
  IOCloudTokenExchangeError,
} from "./errors.js";
import type { SubjectTokenIssuer } from "./federation.js";
import type {
  CreateIdentityProviderInput,
  CreateTenantInput,
  ExternalTenantMapping,
  FederatedLoginInput,
  FederatedSession,
  IdentityProvider,
  IssueTenantTokenInput,
  JsonWebKeySet,
  MapExternalTenantInput,
  PartnerToken,
  SetUserPersonaInput,
  SubjectTokenClaimNames,
  Tenant,
  TenantCredential,
  TenantToken,
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
   * Only needed for {@link IOCloudClient.federatedLogin}; supply it and a
   * partner's login handler becomes a single call.
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

  async createTenant(input: CreateTenantInput): Promise<Tenant> {
    const data = await this.partnerRequest(
      "POST",
      `/v1/partner/applications/${input.applicationUuid}/tenants`,
      {
        name: input.name,
        slug: input.slug,
        contact_email: input.contactEmail,
      },
    );
    const tenant = record(data.tenant);
    return {
      uuid: string(tenant.uuid),
      applicationUuid: string(tenant.application_uuid),
      name: string(tenant.name),
      slug: string(tenant.slug),
      contactEmail: string(tenant.contact_email),
      status: string(tenant.status),
      createdAt: new Date(string(tenant.created_at)),
    };
  }

  /**
   * Register the partner's own issuer as a trusted identity provider.
   *
   * `jwksUrl` defaults to `<issuer>/.well-known/jwks.json`, the path the SDK's
   * JWKS document is meant to be served from. Pass the same `claimNames` as the
   * {@link SubjectTokenIssuer} that signs the tokens, so the two configurations
   * cannot drift apart.
   */
  async createIdentityProvider(
    input: CreateIdentityProviderInput,
  ): Promise<IdentityProvider> {
    const issuer = input.issuer.replace(/\/+$/, "");
    const claimNames = { ...DEFAULT_CLAIM_NAMES, ...input.claimNames };
    const data = await this.partnerRequest(
      "POST",
      "/v1/partner/federation/providers",
      {
        name: input.name,
        issuer,
        jwks_url: input.jwksUrl ?? `${issuer}/.well-known/jwks.json`,
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

  async mapExternalTenant(input: MapExternalTenantInput): Promise<ExternalTenantMapping> {
    const path = `/v1/partner/federation/providers/${input.providerUuid}/tenants`;
    const payload = {
      tenant_uuid: input.tenantUuid,
      external_tenant_id: input.externalTenantId,
    };
    const data = input.accessToken
      ? await this.request("POST", path, payload, input.accessToken)
      : await this.partnerRequest("POST", path, payload);
    const mapping = record(data.mapping);
    return {
      identityProviderUuid: string(mapping.identity_provider_uuid),
      tenantUuid: string(mapping.tenant_uuid),
      externalTenantId: string(mapping.external_tenant_id),
      createdAt: new Date(string(mapping.created_at)),
    };
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
   * The path is yours to choose; it only has to match the `jwksUrl` registered
   * with the platform. Contains public key material only, and is safe to cache.
   */
  jwks(): JsonWebKeySet {
    return this.#requireTokenIssuer("jwks").jwks();
  }

  /** The issuer, audience, JWKS URL, and key id this client signs under. */
  federationDetails(): {
    issuer: string;
    audience: string;
    jwksUrl: string;
    kid: string;
  } {
    const tokenIssuer = this.#requireTokenIssuer("federationDetails");
    return {
      issuer: tokenIssuer.issuer,
      audience: tokenIssuer.audience,
      jwksUrl: tokenIssuer.jwksUrl,
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
   * Sign a subject token for a logged-in partner user and exchange it.
   *
   * The whole partner-side login integration, in one call. Requires
   * `tokenIssuer` on the client options.
   */
  async federatedLogin(input: FederatedLoginInput): Promise<FederatedSession> {
    return this.exchangeSubjectToken(
      this.#requireTokenIssuer("federatedLogin").issue(input),
    );
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

function parseIdentityProvider(payload: JsonObject): IdentityProvider {
  return {
    uuid: string(payload.uuid),
    name: string(payload.name),
    issuer: string(payload.issuer),
    jwksUrl: string(payload.jwks_url),
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

function integer(value: unknown): number {
  if (typeof value !== "number" || !Number.isFinite(value)) {
    throw new TypeError("IOCloud API returned an unexpected response shape");
  }
  return value;
}
