<?php

namespace IOCloud\Laravel\Federation;

use IOCloud\Laravel\Data\SubjectTokenClaimNames;
use IOCloud\Laravel\Exceptions\IOCloudFederationException;

/** Mints the short-lived OIDC JWTs a partner exchanges for a platform session. */
final class SubjectTokenIssuer
{
    /** Subject tokens exist only to be exchanged once, right after login. */
    public const DEFAULT_TOKEN_TTL_SECONDS = 300;

    /** Claims whose values the issuer alone decides. */
    private const RESERVED_CLAIMS = ['iss', 'aud', 'iat', 'nbf', 'exp', 'jti'];

    /** 128 bits of randomness, which is what makes a `jti` collision-free. */
    private const TOKEN_ID_BYTES = 16;

    private readonly string $issuer;

    private readonly SubjectTokenClaimNames $claimNames;

    public function __construct(
        private readonly FederationSigningKey $signingKey,
        string $issuer,
        private readonly string $audience,
        private readonly int $tokenTtlSeconds = self::DEFAULT_TOKEN_TTL_SECONDS,
        ?SubjectTokenClaimNames $claimNames = null,
    ) {
        if (trim($issuer) === '') {
            throw new IOCloudFederationException('issuer must not be empty');
        }
        if (trim($audience) === '') {
            throw new IOCloudFederationException('audience must not be empty');
        }
        if ($tokenTtlSeconds <= 0) {
            throw new IOCloudFederationException('tokenTtlSeconds must be positive');
        }

        $this->issuer = rtrim($issuer, '/');
        $this->claimNames = $claimNames ?? new SubjectTokenClaimNames();
    }

    /** The `iss` value; also the base of the JWKS URL. */
    public function issuer(): string
    {
        return $this->issuer;
    }

    public function audience(): string
    {
        return $this->audience;
    }

    public function claimNames(): SubjectTokenClaimNames
    {
        return $this->claimNames;
    }

    public function signingKey(): FederationSigningKey
    {
        return $this->signingKey;
    }

    /** Where the platform must be told to fetch this issuer's keys. */
    public function jwksUrl(): string
    {
        return $this->issuer.'/.well-known/jwks.json';
    }

    /**
     * The JWKS document to serve at {@see jwksUrl()}.
     *
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array
    {
        return $this->signingKey->jwks();
    }

    /**
     * Sign a subject token for one logged-in partner user.
     *
     * `$subject` must be the partner's stable, never-reused user id: it is the
     * identity key the platform stores, so reusing it for a different person
     * hands over that person's account.
     *
     * @param array<string, mixed> $extraClaims
     */
    public function issue(
        string $subject,
        string $externalTenantId,
        ?string $email = null,
        ?string $name = null,
        bool $emailVerified = false,
        array $extraClaims = [],
    ): string {
        if (trim($subject) === '') {
            throw new IOCloudFederationException('subject must not be empty');
        }
        if (trim($externalTenantId) === '') {
            throw new IOCloudFederationException('externalTenantId must not be empty');
        }

        $claims = $this->standardClaims(
            $subject,
            $externalTenantId,
            $email,
            $name,
            $emailVerified,
        );

        // Reserved claims stay under the issuer's control: a caller cannot widen
        // the audience or extend the lifetime through extra claims.
        foreach ($extraClaims as $claimName => $claimValue) {
            if (in_array($claimName, self::RESERVED_CLAIMS, strict: true)) {
                throw new IOCloudFederationException(
                    "extraClaims may not override the '{$claimName}' claim."
                );
            }
            $claims[$claimName] = $claimValue;
        }

        return $this->signingKey->sign($claims);
    }

    /** A copy that emits identity values under different claim names. */
    public function withClaimNames(SubjectTokenClaimNames $claimNames): self
    {
        return new self(
            signingKey: $this->signingKey,
            issuer: $this->issuer,
            audience: $this->audience,
            tokenTtlSeconds: $this->tokenTtlSeconds,
            claimNames: $claimNames,
        );
    }

    /** @return array<string, mixed> */
    private function standardClaims(
        string $subject,
        string $externalTenantId,
        ?string $email,
        ?string $name,
        bool $emailVerified,
    ): array {
        $issuedAt = time();
        $claims = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
            'exp' => $issuedAt + $this->tokenTtlSeconds,
            // A unique id per token: the platform registers it so the same token
            // cannot be exchanged twice.
            'jti' => bin2hex(random_bytes(self::TOKEN_ID_BYTES)),
            $this->claimNames->user => $subject,
            $this->claimNames->tenant => $externalTenantId,
        ];
        if ($email !== null) {
            $claims[$this->claimNames->email] = $email;
            $claims['email_verified'] = $emailVerified;
        }
        if ($name !== null) {
            $claims[$this->claimNames->name] = $name;
        }

        return $claims;
    }
}
