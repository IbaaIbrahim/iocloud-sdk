export class IOCloudError extends Error {
  override readonly name: string = "IOCloudError";
}

export class IOCloudAPIError extends IOCloudError {
  override readonly name: string = "IOCloudAPIError";

  constructor(
    public readonly statusCode: number,
    public readonly code: string,
    message: string,
  ) {
    super(`${statusCode} ${code}: ${message}`);
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
