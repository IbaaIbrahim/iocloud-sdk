export class IOCloudError extends Error {
  override readonly name: string = "IOCloudError";
}

/** The finer detail of a refusal: the response's `error` object. */
export interface IOCloudAPIErrorDetail {
  reason?: string | null;
  fields?: Record<string, string[]>;
}

/**
 * An API response reported a failure.
 *
 * `code` is the response's machine-readable `code` (`TENANT_NOT_FOUND`,
 * `STANDING_CONTEXT_FULL`, …). `reason` is its `error.reason` when the platform
 * gives one — the finer cause of a refusal, such as `STANDING_ENTRY_TOO_LONG`
 * under `STANDING_ENTRY_INVALID` — and `null` otherwise. `fields` is a
 * `422 VALIDATION_ERROR`'s `error.fields`, each invalid field's path mapped to
 * its messages, and empty otherwise.
 */
export class IOCloudAPIError extends IOCloudError {
  override readonly name: string = "IOCloudAPIError";
  readonly reason: string | null;
  readonly fields: Record<string, string[]>;

  constructor(
    public readonly statusCode: number,
    public readonly code: string,
    message: string,
    detail: IOCloudAPIErrorDetail = {},
  ) {
    super(`${statusCode} ${code}: ${message}`);
    this.reason = detail.reason ?? null;
    this.fields = { ...detail.fields };
  }
}

export class IOCloudAuthenticationError extends IOCloudAPIError {
  override readonly name = "IOCloudAuthenticationError";
}

/**
 * The token exchange endpoint rejected a partner-signed subject token.
 *
 * Carries the RFC 6749 error body. `error` is the machine-readable reason
 * (`invalid_grant`, `invalid_target`, …); the platform deliberately keeps
 * `errorDescription` generic — its audit log holds the precise cause.
 */
export class IOCloudTokenExchangeError extends IOCloudAPIError {
  override readonly name = "IOCloudTokenExchangeError";

  constructor(
    statusCode: number,
    public readonly error: string,
    public readonly errorDescription: string,
  ) {
    super(statusCode, error, errorDescription);
  }
}

/** Partner-side federation is misconfigured: no signing key, bad PEM, … */
export class IOCloudFederationError extends IOCloudError {
  override readonly name = "IOCloudFederationError";
}
