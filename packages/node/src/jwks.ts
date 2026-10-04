/**
 * Where the platform fetches an identity provider's keys.
 *
 * The platform stores an issuer's key-set location as `jwks_path`, a path it
 * appends to the issuer's own origin: it fetches `issuer_origin + jwks_path`
 * and nothing else. A JWKS URL a partner already has is reduced to that path,
 * and only when it is on the issuer's origin; anywhere else, the URL names keys
 * the platform would never fetch.
 */

/** The platform's own default, and where the SDK's key set is meant to be served. */
export const DEFAULT_JWKS_PATH = "/.well-known/jwks.json";

/**
 * `scheme://host[:port]` of `url`, canonical as the platform compares it, or
 * null when it has no http or https origin. Scheme and host lowercased, the
 * host without a trailing dot and in IDNA ASCII, the scheme's default port
 * dropped.
 */
export function originOf(url: string): string | null {
  let parsed: URL;
  try {
    parsed = new URL(url.trim());
  } catch {
    return null;
  }
  if (parsed.protocol !== "http:" && parsed.protocol !== "https:") return null;
  const host = parsed.hostname.replace(/\.$/, "");
  if (!host) return null;
  return `${parsed.protocol}//${host}${parsed.port ? `:${parsed.port}` : ""}`;
}

/**
 * The path of an absolute URL as written: what follows its authority, up to
 * any query or fragment. Empty when it has none, or is not an absolute URL.
 */
export function pathOf(url: string): string {
  return /^[a-z][a-z0-9+.-]*:\/\/[^/?#]*([^?#]*)/i.exec(url.trim())?.[1] ?? "";
}

/** The `jwks_path` to register: the one given, the default, or `jwksUrl`'s path. */
export function resolveJwksPath(
  issuer: string,
  jwksPath: string | undefined,
  jwksUrl: string | undefined,
): string {
  if (jwksUrl === undefined) return jwksPath ?? DEFAULT_JWKS_PATH;
  if (jwksPath !== undefined) {
    throw new TypeError("Pass jwksPath or jwksUrl, not both.");
  }
  return jwksPathOf(jwksUrl, issuer);
}

/**
 * The path of `jwksUrl`, which must be on `issuer`'s origin. Throws a
 * `TypeError` for a URL on any other origin, or one carrying a query or a
 * fragment: the platform keeps the path alone, so either would be silently
 * dropped.
 */
function jwksPathOf(jwksUrl: string, issuer: string): string {
  const issuerOrigin = originOf(issuer);
  if (issuerOrigin === null) {
    throw new TypeError(
      `The issuer '${issuer}' has no http or https origin to put a JWKS path on.`,
    );
  }
  if (originOf(jwksUrl) !== issuerOrigin) {
    throw new TypeError(
      `jwksUrl '${jwksUrl}' is not on the issuer's origin, ${issuerOrigin}. ` +
        "The platform fetches an issuer's keys from its own origin only: serve " +
        `them on ${issuerOrigin} and pass that path as jwksPath.`,
    );
  }
  if (jwksUrl.includes("?") || jwksUrl.includes("#")) {
    throw new TypeError(
      `jwksUrl '${jwksUrl}' carries a query or a fragment; the platform ` +
        "stores a path only. Pass jwksPath instead.",
    );
  }
  const path = pathOf(jwksUrl);
  if (!path) {
    throw new TypeError(`jwksUrl '${jwksUrl}' names no path. Pass jwksPath instead.`);
  }
  return path;
}
