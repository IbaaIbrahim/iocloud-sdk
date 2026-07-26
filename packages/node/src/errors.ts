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
