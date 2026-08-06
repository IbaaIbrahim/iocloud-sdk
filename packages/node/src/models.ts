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

/**
 * Which claim carries each identity value.
 *
 * Shared by the two halves of a federation setup: the issuer that writes the
 * claims and the identity provider row that tells the platform where to read
 * them. Pass one value to both and they cannot drift apart.
 */
export interface SubjectTokenClaimNames {
  user: string;
  tenant: string;
  email: string;
  name: string;
}

/**
 * The platform's trust anchor for one partner issuer. Every field is an
 * instruction to the platform's token validator; a subject token overrides
 * none of them.
 */
export interface IdentityProvider {
  uuid: string;
  name: string;
  issuer: string;
  jwksUrl: string;
  allowedAudiences: string[];
  allowedAlgorithms: string[];
  tokenMaxAgeSeconds: number;
  requireEmailVerified: boolean;
  claimNames: SubjectTokenClaimNames;
  allowJitUsers: boolean;
  status: string;
  createdAt: Date;
}

/**
 * The platform session a subject token was exchanged for.
 *
 * `accessToken` is opaque — not a JWT — and is presented as a bearer credential
 * on the Gateway job APIs. There are no refresh tokens: when it expires, the
 * partner signs a new subject token and exchanges again.
 */
export interface FederatedSession {
  accessToken: string;
  tokenType: string;
  issuedTokenType: string;
  expiresIn: number;
  expiresAt: Date;
  userUuid: string;
  name: string;
  email: string;
}

/** One RSA public signing key, as served in a JWKS (RFC 7517). */
export interface JsonWebKey {
  kty: string;
  use: string;
  alg: string;
  kid: string;
  n: string;
  e: string;
}

/** The document a partner serves at `<issuer>/.well-known/jwks.json`. */
export interface JsonWebKeySet {
  keys: JsonWebKey[];
}

export interface CreateIdentityProviderInput {
  name: string;
  issuer: string;
  /** Defaults to `<issuer>/.well-known/jwks.json`. */
  jwksUrl?: string;
  allowedAudiences: string[];
  allowedAlgorithms?: string[];
  tokenMaxAgeSeconds?: number;
  requireEmailVerified?: boolean;
  allowJitUsers?: boolean;
  claimNames?: Partial<SubjectTokenClaimNames>;
}

export interface FederatedLoginInput {
  subject: string;
  externalTenantId: string;
  email?: string;
  name?: string;
  emailVerified?: boolean;
  extraClaims?: Record<string, unknown>;
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

/**
 * A plan the partner offers its own tenants.
 *
 * `credits` is the tenant's included balance and `userCreditsCap` the per-user
 * share of it; both become child-cap rows when a subscription is activated.
 */
export interface TenantPlan {
  uuid: string;
  name: string;
  monthlyPriceCents: number;
  yearlyPriceCents: number;
  tpm: number;
  rpm: number;
  credits: number;
  userCreditsCap: number;
  userTpm: number;
  userRpm: number;
}

/**
 * A subscription linking a subscriber to a plan for a billing period.
 *
 * `status` is `pending_payment` until activated, then `paid`. The window
 * (`subscribedFrom`/`subscribedTo`) is null while pending — it is established
 * at activation and is what makes the plan and its balance active.
 */
export interface PlanSubscription {
  uuid: string;
  status: string;
  planType: string;
  billingCycle: string;
  subscribedFrom: Date | null;
  subscribedTo: Date | null;
  paymentTransactionUuid: string | null;
  createdAt: Date;
}

/** One balance row an activation created. */
export interface ProvisionedCap {
  child: string;
  id: number;
  cap: number;
}

/**
 * The balance rows an activation created. A tenant subscription provisions
 * `capsCreated` and never a pool: tenants draw on their partner's credit pool,
 * bounded by those caps.
 */
export interface ProvisionedBalance {
  poolCreated: boolean;
  poolCredits: number;
  capsCreated: ProvisionedCap[];
}

/**
 * A subscription plus whatever its activation provisioned.
 *
 * `provisioned` is null when this call provisioned nothing — the subscription
 * is still pending payment, or an already-active one was activated again
 * (activation is idempotent).
 */
export interface TenantSubscription {
  subscription: PlanSubscription;
  provisioned: ProvisionedBalance | null;
}

export interface SubscribeTenantInput {
  tenantUuid: string;
  planUuid: string;
  billingCycle?: "monthly" | "yearly";
  /** Defaults to true: create AND activate in one call. */
  activateNow?: boolean;
  /** Free text kept in the platform's audit trail (invoice number, note). */
  reference?: string;
}

export interface ActivateTenantSubscriptionInput {
  subscriptionUuid: string;
  reference?: string;
}
