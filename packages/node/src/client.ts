import { IOCloudAPIError, IOCloudAuthenticationError } from "./errors.js";
import type {
  CreateTenantInput,
  ExternalTenantMapping,
  IssueTenantTokenInput,
  MapExternalTenantInput,
  PartnerToken,
  SetUserPersonaInput,
  Tenant,
  TenantCredential,
  TenantToken,
} from "./models.js";

type JsonObject = Record<string, unknown>;

export interface IOCloudClientOptions {
  clientId: string;
  clientSecret: string;
  baseUrl: string;
  timeoutMs?: number;
  fetch?: typeof globalThis.fetch;
}

export class IOCloudClient {
  readonly #clientId: string;
  readonly #clientSecret: string;
  readonly #baseUrl: string;
  readonly #timeoutMs: number;
  readonly #fetch: typeof globalThis.fetch;
  #partnerToken?: PartnerToken;
  readonly #tenantTokens = new Map<string, TenantToken>();

  constructor(options: IOCloudClientOptions) {
    this.#clientId = required(options.clientId, "clientId");
    this.#clientSecret = required(options.clientSecret, "clientSecret");
    this.#baseUrl = required(options.baseUrl, "baseUrl").replace(/\/$/, "");
    this.#timeoutMs = options.timeoutMs ?? 30_000;
    this.#fetch = options.fetch ?? globalThis.fetch;
  }

  async issuePartnerToken(forceRefresh = false): Promise<PartnerToken> {
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
    payload: JsonObject,
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
    payload: JsonObject,
    accessToken?: string,
  ): Promise<JsonObject> {
    const headers: Record<string, string> = { "content-type": "application/json" };
    if (accessToken) headers.authorization = `Bearer ${accessToken}`;

    const response = await this.#fetch(`${this.#baseUrl}${path}`, {
      method,
      headers,
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(this.#timeoutMs),
    });
    const text = await response.text();
    let body: JsonObject = {};
    if (text) {
      try {
        body = record(JSON.parse(text));
      } catch {
        body = {};
      }
    }

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

function string(value: unknown): string {
  if (typeof value !== "string") {
    throw new TypeError("IOCloud API returned an unexpected response shape");
  }
  return value;
}
