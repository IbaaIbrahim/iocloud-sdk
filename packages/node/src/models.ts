export interface PartnerToken {
  accessToken: string;
  tokenType: string;
  expiresAt: Date;
}

export type TenantToken = PartnerToken;

export interface TenantCredential {
  credentialUuid: string;
  tenantUuid: string;
  clientId: string;
  clientSecret: string;
}

export interface Tenant {
  uuid: string;
  applicationUuid: string;
  name: string;
  slug: string;
  contactEmail: string;
  status: string;
  createdAt: Date;
}

export interface ExternalTenantMapping {
  identityProviderUuid: string;
  tenantUuid: string;
  externalTenantId: string;
  createdAt: Date;
}

export interface CreateTenantInput {
  applicationUuid: string;
  name: string;
  slug: string;
  contactEmail: string;
}

export interface MapExternalTenantInput {
  providerUuid: string;
  tenantUuid: string;
  externalTenantId: string;
  accessToken?: string;
}

export interface IssueTenantTokenInput {
  clientId: string;
  clientSecret: string;
  forceRefresh?: boolean;
}

export interface SetUserPersonaInput {
  userUuid: string;
  persona: string;
  tenantClientId: string;
  tenantClientSecret: string;
}
