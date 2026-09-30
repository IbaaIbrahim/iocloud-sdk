import assert from "node:assert/strict";
import { createPublicKey, createVerify } from "node:crypto";
import test from "node:test";

import {
  FederationSigningKey,
  IOCloudFederationError,
  SIGNING_ALGORITHM,
  SubjectTokenIssuer,
  buildJwks,
} from "../dist/index.js";

const ISSUER = "https://portal.acme.example";
const AUDIENCE = "ai-ecosystem";
const TENANT_PROFILE = {
  name: "Acme Ltd",
  contactEmail: "ops@acme.example",
};

function tokenIssuer(signingKey, overrides = {}) {
  return new SubjectTokenIssuer({
    signingKey,
    issuer: ISSUER,
    audience: AUDIENCE,
    tokenTtlSeconds: 300,
    ...overrides,
  });
}

/** Verify a compact JWS the way a JWKS consumer would: from the public JWK. */
function verifyWithJwks(token, jwks) {
  const [encodedHeader, encodedPayload, encodedSignature] = token.split(".");
  const header = JSON.parse(Buffer.from(encodedHeader, "base64url").toString());
  const jwk = jwks.keys.find((key) => key.kid === header.kid);
  assert.ok(jwk, `no JWKS entry for kid ${header.kid}`);

  const publicKey = createPublicKey({ key: jwk, format: "jwk" });
  const verified = createVerify("RSA-SHA256")
    .update(`${encodedHeader}.${encodedPayload}`)
    .verify(publicKey, Buffer.from(encodedSignature, "base64url"));

  return { verified, header, claims: JSON.parse(Buffer.from(encodedPayload, "base64url").toString()) };
}

test("public JWK carries the fields a verifier needs", () => {
  const jwk = FederationSigningKey.generate().publicJwk();

  assert.equal(jwk.kty, "RSA");
  assert.equal(jwk.use, "sig");
  assert.equal(jwk.alg, SIGNING_ALGORITHM);
  assert.equal(jwk.e, "AQAB");
  assert.ok(jwk.kid.length > 0);
  assert.ok(!jwk.n.includes("="));
});

test("JWKS publishes only the public half", () => {
  const jwks = FederationSigningKey.generate().jwks();

  assert.equal(jwks.keys.length, 1);
  assert.deepEqual(Object.keys(jwks.keys[0]).sort(), [
    "alg",
    "e",
    "kid",
    "kty",
    "n",
    "use",
  ]);
});

test("kid is derived from the key so it survives a reload", () => {
  const key = FederationSigningKey.generate();

  const reloaded = FederationSigningKey.fromPrivateKeyPem(key.privateKeyPem);

  assert.equal(reloaded.kid, key.kid);
  assert.deepEqual(reloaded.publicJwk(), key.publicJwk());
});

test("distinct keys get distinct kids", () => {
  assert.notEqual(
    FederationSigningKey.generate().kid,
    FederationSigningKey.generate().kid,
  );
});

test("serializing a key never leaks the private half", () => {
  const key = FederationSigningKey.generate();

  const serialized = JSON.stringify(key);

  assert.ok(!serialized.includes("PRIVATE"));
  assert.ok(serialized.includes(key.kid));
});

test("the public key PEM verifies what the private half signed", () => {
  const key = FederationSigningKey.generate();

  const publicKeyPem = key.publicKeyPem;

  assert.match(publicKeyPem, /BEGIN PUBLIC KEY/);
  assert.ok(!publicKeyPem.includes("PRIVATE"));
  const token = key.sign({ sub: "user-1" });
  const [header, payload, signature] = token.split(".");
  assert.ok(
    createVerify("RSA-SHA256")
      .update(`${header}.${payload}`)
      .verify(publicKeyPem, Buffer.from(signature, "base64url")),
  );
});

test("loading a non-PEM value reports a federation error", () => {
  assert.throws(
    () => FederationSigningKey.fromPrivateKeyPem("not-a-key"),
    IOCloudFederationError,
  );
});

test("buildJwks publishes every key in a rotation", () => {
  const current = FederationSigningKey.generate();
  const retiring = FederationSigningKey.generate();

  const jwks = buildJwks(current, retiring);

  assert.deepEqual(
    jwks.keys.map((key) => key.kid),
    [current.kid, retiring.kid],
  );
});

test("buildJwks rejects an empty key set", () => {
  assert.throws(() => buildJwks(), IOCloudFederationError);
});

test("token verifies against the published JWKS", () => {
  const key = FederationSigningKey.generate();
  const issuer = tokenIssuer(key);

  const token = issuer.issue({ subject: "user-1", externalTenantId: "tenant-1" });

  const { verified, header, claims } = verifyWithJwks(token, issuer.jwks());
  assert.ok(verified);
  assert.equal(header.alg, SIGNING_ALGORITHM);
  assert.equal(header.kid, key.kid);
  assert.equal(header.typ, "JWT");
  assert.equal(claims.iss, ISSUER);
  assert.equal(claims.aud, AUDIENCE);
  assert.equal(claims.sub, "user-1");
  assert.equal(claims.tenant_id, "tenant-1");
});

test("a token from a different key does not verify against the JWKS", () => {
  const published = tokenIssuer(FederationSigningKey.generate());
  const stranger = tokenIssuer(FederationSigningKey.generate());

  const token = stranger.issue({ subject: "user-1", externalTenantId: "tenant-1" });

  // Different key, different kid: the JWKS has no entry for it at all.
  assert.throws(() => verifyWithJwks(token, published.jwks()));
});

test("claims cover the platform validation rules", () => {
  const issuer = tokenIssuer(FederationSigningKey.generate());

  const { claims } = verifyWithJwks(
    issuer.issue({
      subject: "user-1",
      externalTenantId: "tenant-1",
      email: "user@customer.example",
      name: "Test User",
      emailVerified: true,
    }),
    issuer.jwks(),
  );

  assert.equal(claims.email, "user@customer.example");
  assert.equal(claims.email_verified, true);
  assert.equal(claims.name, "Test User");
  assert.equal(claims.exp - claims.iat, 300);
  assert.equal(claims.nbf, claims.iat);
  assert.ok(claims.jti);
});

test("email_verified is absent when no email is supplied", () => {
  const issuer = tokenIssuer(FederationSigningKey.generate());

  const { claims } = verifyWithJwks(
    issuer.issue({ subject: "user-1", externalTenantId: "tenant-1" }),
    issuer.jwks(),
  );

  assert.ok(!("email" in claims));
  assert.ok(!("email_verified" in claims));
});

test("every token gets a unique jti for replay protection", () => {
  const issuer = tokenIssuer(FederationSigningKey.generate());
  const input = { subject: "user-1", externalTenantId: "tenant-1" };

  const first = verifyWithJwks(issuer.issue(input), issuer.jwks()).claims;
  const second = verifyWithJwks(issuer.issue(input), issuer.jwks()).claims;

  assert.notEqual(first.jti, second.jti);
});

test("trailing slashes are stripped so iss matches byte for byte", () => {
  const issuer = tokenIssuer(FederationSigningKey.generate(), {
    issuer: `${ISSUER}//`,
  });

  assert.equal(issuer.issuer, ISSUER);
  assert.equal(issuer.jwksUrl, `${ISSUER}/.well-known/jwks.json`);
});

test("claim names are configurable per provider", () => {
  const issuer = tokenIssuer(FederationSigningKey.generate()).withClaimNames({
    user: "user_id",
    tenant: "org_id",
  });

  const { claims } = verifyWithJwks(
    issuer.issue({ subject: "user-1", externalTenantId: "org-1" }),
    issuer.jwks(),
  );

  assert.equal(claims.user_id, "user-1");
  assert.equal(claims.org_id, "org-1");
  assert.ok(!("sub" in claims));
  assert.deepEqual(issuer.claimNames, {
    user: "user_id",
    tenant: "org_id",
    email: "email",
    name: "name",
  });
});

test("extra claims are added but cannot override issuer-controlled claims", () => {
  const issuer = tokenIssuer(FederationSigningKey.generate());

  const { claims } = verifyWithJwks(
    issuer.issue({
      subject: "user-1",
      externalTenantId: "tenant-1",
      extraClaims: { scope: "jobs:create" },
    }),
    issuer.jwks(),
  );
  assert.equal(claims.scope, "jobs:create");

  for (const reserved of ["iss", "aud", "exp", "iat", "nbf", "jti"]) {
    assert.throws(
      () =>
        issuer.issue({
          subject: "user-1",
          externalTenantId: "tenant-1",
          extraClaims: { [reserved]: "attacker" },
        }),
      IOCloudFederationError,
      `expected ${reserved} to be rejected`,
    );
  }
});

test("a tenant profile is signed as the tenant_profile claim", () => {
  const issuer = tokenIssuer(FederationSigningKey.generate());

  const { claims } = verifyWithJwks(
    issuer.issue({
      subject: "user-1",
      externalTenantId: "tenant-1",
      tenant: TENANT_PROFILE,
    }),
    issuer.jwks(),
  );

  assert.deepEqual(claims.tenant_profile, {
    name: "Acme Ltd",
    contact_email: "ops@acme.example",
  });
  assert.equal(claims.tenant_id, "tenant-1");
});

test("a tenant profile without a contact email is signed without one", () => {
  const issuer = tokenIssuer(FederationSigningKey.generate());

  const { claims } = verifyWithJwks(
    issuer.issue({
      subject: "user-1",
      externalTenantId: "tenant-1",
      tenant: { name: "Acme Ltd" },
    }),
    issuer.jwks(),
  );

  assert.deepEqual(claims.tenant_profile, { name: "Acme Ltd" });
});

test("no tenant_profile claim is signed without a profile", () => {
  const issuer = tokenIssuer(FederationSigningKey.generate());

  const { claims } = verifyWithJwks(
    issuer.issue({ subject: "user-1", externalTenantId: "tenant-1" }),
    issuer.jwks(),
  );

  assert.ok(!("tenant_profile" in claims));
});

test("the claim mapping never renames the tenant profile", () => {
  const issuer = tokenIssuer(FederationSigningKey.generate()).withClaimNames({
    user: "user_id",
    tenant: "org_id",
  });

  const { claims } = verifyWithJwks(
    issuer.issue({
      subject: "user-1",
      externalTenantId: "org-1",
      tenant: TENANT_PROFILE,
    }),
    issuer.jwks(),
  );

  assert.equal(claims.org_id, "org-1");
  assert.deepEqual(claims.tenant_profile, {
    name: "Acme Ltd",
    contact_email: "ops@acme.example",
  });
});

test("extra claims can neither set nor override the tenant profile", () => {
  const issuer = tokenIssuer(FederationSigningKey.generate());
  const smuggled = { tenant_profile: { name: "Other" } };

  assert.throws(
    () =>
      issuer.issue({
        subject: "user-1",
        externalTenantId: "tenant-1",
        extraClaims: smuggled,
      }),
    IOCloudFederationError,
  );
  assert.throws(
    () =>
      issuer.issue({
        subject: "user-1",
        externalTenantId: "tenant-1",
        extraClaims: smuggled,
        tenant: TENANT_PROFILE,
      }),
    IOCloudFederationError,
  );
});

test("blank tenant profile values are rejected at the boundary", () => {
  const issuer = tokenIssuer(FederationSigningKey.generate());

  for (const blankField of ["name", "contactEmail"]) {
    assert.throws(
      () =>
        issuer.issue({
          subject: "user-1",
          externalTenantId: "tenant-1",
          tenant: { ...TENANT_PROFILE, [blankField]: "  " },
        }),
      (error) =>
        error instanceof IOCloudFederationError &&
        error.message.includes(blankField),
      `expected a blank ${blankField} to be rejected`,
    );
  }
});

test("blank identity values are rejected at the boundary", () => {
  const issuer = tokenIssuer(FederationSigningKey.generate());

  assert.throws(
    () => issuer.issue({ subject: "  ", externalTenantId: "tenant-1" }),
    IOCloudFederationError,
  );
  assert.throws(
    () => issuer.issue({ subject: "user-1", externalTenantId: "" }),
    IOCloudFederationError,
  );
});

test("issuer configuration is validated on construction", () => {
  const key = FederationSigningKey.generate();

  assert.throws(() => tokenIssuer(key, { issuer: " " }), IOCloudFederationError);
  assert.throws(() => tokenIssuer(key, { audience: "" }), IOCloudFederationError);
  assert.throws(
    () => tokenIssuer(key, { tokenTtlSeconds: 0 }),
    IOCloudFederationError,
  );
});
