/**
 * Partner-side federation: signing keys, the JWKS document, subject tokens.
 *
 * A partner that federates users into IOCloud has to act as a small OIDC
 * issuer: hold an RSA keypair, publish the public half as a JWKS at its issuer
 * URL, and sign a short-lived JWT for every user who logs in. This module
 * provides those three pieces on Node's built-in crypto — no extra dependency.
 */

import {
  createHash,
  createPrivateKey,
  createPublicKey,
  createSign,
  generateKeyPairSync,
  randomUUID,
  type KeyObject,
} from "node:crypto";

import { IOCloudFederationError } from "./errors.js";
import type {
  JsonWebKey,
  JsonWebKeySet,
  SubjectTokenClaimNames,
  TenantProfile,
} from "./models.js";

/**
 * RFC 7518 §3.1 recommends RSASSA-PKCS1-v1_5 with SHA-256 as the baseline; the
 * platform accepts it for every provider without extra configuration.
 */
export const SIGNING_ALGORITHM = "RS256";

/** Subject tokens exist only to be exchanged once, right after login. */
export const DEFAULT_TOKEN_TTL_SECONDS = 300;

const KEY_TYPE = "RSA";
const KEY_USE = "sig";
const RSA_KEY_SIZE_BITS = 2048;
const NODE_SIGN_ALGORITHM = "RSA-SHA256";

/**
 * The tenant a first login may create. Its name is fixed: the claim mapping
 * renames the identity values, never this claim.
 */
const TENANT_PROFILE_CLAIM = "tenant_profile";

/**
 * Claims whose values the issuer alone decides. The tenant profile is one of
 * them: the typed `tenant` input of `issue` is its only way in.
 */
const RESERVED_CLAIMS = new Set([
  "iss",
  "aud",
  "iat",
  "nbf",
  "exp",
  "jti",
  TENANT_PROFILE_CLAIM,
]);

const DEFAULT_CLAIM_NAMES: SubjectTokenClaimNames = {
  user: "sub",
  tenant: "tenant_id",
  email: "email",
  name: "name",
};

export interface SubjectTokenIssuerOptions {
  signingKey: FederationSigningKey;
  issuer: string;
  audience: string;
  tokenTtlSeconds?: number;
  claimNames?: Partial<SubjectTokenClaimNames>;
}

export interface IssueSubjectTokenInput {
  /**
   * The partner's stable, never-reused user id. This is the identity key the
   * platform stores, so reusing it for a different person hands over that
   * person's account.
   */
  subject: string;
  /**
   * The tenant claim. May be left out when `tenant` carries one, and must
   * equal it when both are given.
   */
  externalTenantId?: string;
  email?: string;
  name?: string;
  emailVerified?: boolean;
  extraClaims?: Record<string, unknown>;
  /**
   * The tenant to create if this is its first login, signed as the
   * `tenant_profile` claim.
   */
  tenant?: TenantProfile;
}

/** An RSA keypair with the JWK views the partner's OIDC endpoints serve. */
export class FederationSigningKey {
  readonly #privateKey: KeyObject;
  readonly #modulus: string;
  readonly #exponent: string;
  readonly #kid: string;

  private constructor(privateKey: KeyObject) {
    const jwk = privateKey.export({ format: "jwk" }) as {
      kty?: string;
      n?: string;
      e?: string;
    };
    if (jwk.kty !== KEY_TYPE || !jwk.n || !jwk.e) {
      throw new IOCloudFederationError(
        "Federation signing keys must be RSA keys.",
      );
    }
    const modulusBits = base64UrlDecode(jwk.n).length * 8;
    if (modulusBits < RSA_KEY_SIZE_BITS) {
      throw new IOCloudFederationError(
        `Federation signing keys must be at least ${RSA_KEY_SIZE_BITS} bits;` +
          ` this key is ${modulusBits} bits.`,
      );
    }

    this.#privateKey = privateKey;
    this.#modulus = jwk.n;
    this.#exponent = jwk.e;
    this.#kid = jwkThumbprint(jwk.n, jwk.e);
  }

  /** Generate a fresh 2048-bit RSA keypair. */
  static generate(): FederationSigningKey {
    const { privateKey } = generateKeyPairSync("rsa", {
      modulusLength: RSA_KEY_SIZE_BITS,
    });
    return new FederationSigningKey(privateKey);
  }

  /** Load a persisted key so the published `kid` survives a restart. */
  static fromPrivateKeyPem(
    privateKeyPem: string,
    passphrase?: string,
  ): FederationSigningKey {
    let privateKey: KeyObject;
    try {
      privateKey = createPrivateKey(
        passphrase === undefined
          ? { key: privateKeyPem }
          : { key: privateKeyPem, passphrase },
      );
    } catch (cause) {
      throw new IOCloudFederationError(
        "The federation private key is not a readable PEM private key.",
        { cause },
      );
    }
    return new FederationSigningKey(privateKey);
  }

  /** The key id published in the JWKS and set in every token header. */
  get kid(): string {
    return this.#kid;
  }

  /** PKCS#8 PEM of the private half. Store it as a secret. */
  get privateKeyPem(): string {
    return this.#privateKey.export({ format: "pem", type: "pkcs8" }).toString();
  }

  /**
   * PEM of the public half.
   *
   * Not needed to publish a JWKS — that is derived from the private key — but
   * available so the pair can be inspected or handed to another tool.
   */
  get publicKeyPem(): string {
    return createPublicKey(this.#privateKey)
      .export({ format: "pem", type: "spki" })
      .toString();
  }

  /** The public key as a JWK (RFC 7517). */
  publicJwk(): JsonWebKey {
    return {
      kty: KEY_TYPE,
      use: KEY_USE,
      alg: SIGNING_ALGORITHM,
      kid: this.#kid,
      n: this.#modulus,
      e: this.#exponent,
    };
  }

  /** The document to serve at `<issuer>/.well-known/jwks.json`. */
  jwks(): JsonWebKeySet {
    return { keys: [this.publicJwk()] };
  }

  /** Sign `claims` into a compact JWS naming this key's `kid`. */
  sign(claims: Record<string, unknown>): string {
    const header = { alg: SIGNING_ALGORITHM, kid: this.#kid, typ: "JWT" };
    const signingInput =
      `${base64UrlEncodeJson(header)}.${base64UrlEncodeJson(claims)}`;
    const signature = createSign(NODE_SIGN_ALGORITHM)
      .update(signingInput)
      .sign(this.#privateKey);
    return `${signingInput}.${base64UrlEncode(signature)}`;
  }

  /** Keeps the private key out of logs and error reports. */
  toJSON(): Record<string, string> {
    return { kid: this.#kid, privateKeyPem: "***" };
  }
}

/**
 * Merge several keys into one JWKS, which is how a rotation is published.
 *
 * During a rotation the retiring key stays in the document until every token
 * it signed has expired; verifiers select by `kid`.
 */
export function buildJwks(
  ...signingKeys: readonly FederationSigningKey[]
): JsonWebKeySet {
  if (signingKeys.length === 0) {
    throw new IOCloudFederationError("A JWKS must publish at least one key.");
  }
  return { keys: signingKeys.map((signingKey) => signingKey.publicJwk()) };
}

/** Mints the short-lived OIDC JWTs a partner exchanges for a session. */
export class SubjectTokenIssuer {
  readonly #signingKey: FederationSigningKey;
  readonly #issuer: string;
  readonly #audience: string;
  readonly #tokenTtlSeconds: number;
  readonly #claimNames: SubjectTokenClaimNames;

  constructor(options: SubjectTokenIssuerOptions) {
    if (!options.issuer?.trim()) {
      throw new IOCloudFederationError("issuer must not be empty");
    }
    if (!options.audience?.trim()) {
      throw new IOCloudFederationError("audience must not be empty");
    }
    const tokenTtlSeconds = options.tokenTtlSeconds ?? DEFAULT_TOKEN_TTL_SECONDS;
    if (!Number.isInteger(tokenTtlSeconds) || tokenTtlSeconds <= 0) {
      throw new IOCloudFederationError(
        "tokenTtlSeconds must be a positive whole number of seconds",
      );
    }

    this.#signingKey = options.signingKey;
    this.#issuer = options.issuer.replace(/\/+$/, "");
    this.#audience = options.audience;
    this.#tokenTtlSeconds = tokenTtlSeconds;
    this.#claimNames = { ...DEFAULT_CLAIM_NAMES, ...options.claimNames };
  }

  /** The `iss` value; also the base of the JWKS URL. */
  get issuer(): string {
    return this.#issuer;
  }

  get audience(): string {
    return this.#audience;
  }

  get claimNames(): SubjectTokenClaimNames {
    return { ...this.#claimNames };
  }

  get signingKey(): FederationSigningKey {
    return this.#signingKey;
  }

  /** Where the platform must be told to fetch this issuer's keys. */
  get jwksUrl(): string {
    return `${this.#issuer}/.well-known/jwks.json`;
  }

  /** The JWKS document to serve at {@link jwksUrl}. */
  jwks(): JsonWebKeySet {
    return this.#signingKey.jwks();
  }

  /** Sign a subject token for one logged-in partner user. */
  issue(input: IssueSubjectTokenInput): string {
    if (!input.subject?.trim()) {
      throw new IOCloudFederationError("subject must not be empty");
    }
    if (input.tenant !== undefined) {
      requireTenantProfileValues(input.tenant);
    }

    const claims = this.#standardClaims(input, tenantClaimValue(input));
    // Reserved claims stay under the issuer's control: a caller cannot widen
    // the audience, extend the lifetime, or sign an unchecked tenant profile
    // through extraClaims.
    for (const [claimName, claimValue] of Object.entries(input.extraClaims ?? {})) {
      if (RESERVED_CLAIMS.has(claimName)) {
        throw new IOCloudFederationError(
          `extraClaims may not override the '${claimName}' claim.`,
        );
      }
      claims[claimName] = claimValue;
    }

    return this.#signingKey.sign(claims);
  }

  /** A copy that emits identity values under different claim names. */
  withClaimNames(claimNames: Partial<SubjectTokenClaimNames>): SubjectTokenIssuer {
    return new SubjectTokenIssuer({
      signingKey: this.#signingKey,
      issuer: this.#issuer,
      audience: this.#audience,
      tokenTtlSeconds: this.#tokenTtlSeconds,
      claimNames: { ...this.#claimNames, ...claimNames },
    });
  }

  #standardClaims(
    input: IssueSubjectTokenInput,
    externalTenantId: string,
  ): Record<string, unknown> {
    const issuedAt = Math.floor(Date.now() / 1000);
    const claims: Record<string, unknown> = {
      iss: this.#issuer,
      aud: this.#audience,
      iat: issuedAt,
      nbf: issuedAt,
      exp: issuedAt + this.#tokenTtlSeconds,
      // A unique id per token: the platform registers it so the same token
      // cannot be exchanged twice.
      jti: randomUUID(),
      [this.#claimNames.user]: input.subject,
      [this.#claimNames.tenant]: externalTenantId,
    };
    if (input.email !== undefined) {
      claims[this.#claimNames.email] = input.email;
      claims.email_verified = input.emailVerified ?? false;
    }
    if (input.name !== undefined) {
      claims[this.#claimNames.name] = input.name;
    }
    if (input.tenant !== undefined) {
      // Keyed as the platform reads it, whatever the claim names are.
      const profile: Record<string, string> = { name: input.tenant.name };
      const contactEmail = input.tenant.contactEmail ?? null;
      if (contactEmail !== null) profile.contact_email = contactEmail;
      const planCode = input.tenant.planCode ?? null;
      if (planCode !== null) profile.plan_code = planCode;
      claims[TENANT_PROFILE_CLAIM] = profile;
    }
    return claims;
  }
}

/**
 * Refuse an empty profile value, the way an empty subject is refused. Only
 * emptiness is checked, and the optional values only when given: lengths are
 * the platform's to judge.
 */
function requireTenantProfileValues(tenant: TenantProfile): void {
  if (!tenant.name?.trim()) {
    throw new IOCloudFederationError("tenant.name must not be empty");
  }
  const optionalValues = {
    contactEmail: tenant.contactEmail,
    planCode: tenant.planCode,
    externalTenantId: tenant.externalTenantId,
  };
  for (const [fieldName, value] of Object.entries(optionalValues)) {
    if (value !== undefined && value !== null && !value.trim()) {
      throw new IOCloudFederationError(`tenant.${fieldName} must not be empty`);
    }
  }
}

/**
 * The tenant claim's value: the login's `externalTenantId`, else its
 * profile's. Given twice, it must be one id: the platform finds the tenant by
 * the claim, and creates it under that id, whatever the profile said.
 */
function tenantClaimValue(input: IssueSubjectTokenInput): string {
  const loginTenantId = input.externalTenantId ?? null;
  const profileTenantId = input.tenant?.externalTenantId ?? null;
  if (loginTenantId !== null && profileTenantId !== null && loginTenantId !== profileTenantId) {
    throw new IOCloudFederationError("tenant.externalTenantId must equal externalTenantId");
  }
  const tenantId = loginTenantId ?? profileTenantId;
  if (tenantId === null) {
    throw new IOCloudFederationError(
      "externalTenantId is required: pass it, or a tenant that carries it",
    );
  }
  if (!tenantId.trim()) {
    throw new IOCloudFederationError("externalTenantId must not be empty");
  }
  return tenantId;
}

/**
 * Compute the RFC 7638 thumbprint used as the key id.
 *
 * Deriving the `kid` from the key itself — rather than a random value — keeps
 * it stable across process restarts and across the three SDKs, so a published
 * JWKS and a signed token always agree on the key id.
 */
function jwkThumbprint(modulus: string, exponent: string): string {
  const canonicalJson = JSON.stringify({ e: exponent, kty: KEY_TYPE, n: modulus });
  return base64UrlEncode(createHash("sha256").update(canonicalJson).digest());
}

function base64UrlEncode(raw: Buffer): string {
  return raw.toString("base64url");
}

function base64UrlDecode(value: string): Buffer {
  return Buffer.from(value, "base64url");
}

function base64UrlEncodeJson(value: unknown): string {
  return base64UrlEncode(Buffer.from(JSON.stringify(value), "utf8"));
}
