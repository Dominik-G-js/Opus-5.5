<?php

declare(strict_types=1);

namespace App\Integration\Fanvue;

final class TokenSet
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly int $expiresAt,
        public readonly string $scope,
    ) {
    }

    /** @param array<string|int, mixed> $response Odpověď token endpointu. */
    public static function fromTokenResponse(array $response, ?string $previousRefreshToken = null, ?int $now = null): self
    {
        $access = $response['access_token'] ?? null;
        if (!is_string($access) || $access === '') {
            throw new FanvueException('Token endpoint nevrátil access_token.');
        }
        // Refresh tokeny se rotují — vždy ukládáme ten nejnovější.
        $refresh = $response['refresh_token'] ?? $previousRefreshToken;
        if (!is_string($refresh) || $refresh === '') {
            throw new FanvueException('Token endpoint nevrátil refresh_token (chybí scope offline_access?).');
        }
        $expiresIn = (int) ($response['expires_in'] ?? 3600);

        return new self($access, $refresh, ($now ?? time()) + max(60, $expiresIn), (string) ($response['scope'] ?? ''));
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['access_token'] ?? ''),
            (string) ($data['refresh_token'] ?? ''),
            (int) ($data['expires_at'] ?? 0),
            (string) ($data['scope'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt,
            'scope' => $this->scope,
        ];
    }

    public function isExpired(int $leewaySeconds = 60, ?int $now = null): bool
    {
        return $this->expiresAt - $leewaySeconds <= ($now ?? time());
    }
}
