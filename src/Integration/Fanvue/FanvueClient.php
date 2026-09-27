<?php

declare(strict_types=1);

namespace App\Integration\Fanvue;

use App\Http\HttpClient;
use App\Http\HttpClientException;
use App\Http\HttpResponse;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Oficiální Fanvue API (OAuth 2.0 + PKCE). Endpointy a hlavičky ověřeny z oficiálních balíčků
 * @fanvue/builder-sdk a @fanvue/n8n-nodes-fanvue (OpenAPI), API verze 2025-06-26.
 */
final class FanvueClient
{
    public const AUTH_BASE = 'https://auth.fanvue.com';
    public const API_BASE = 'https://api.fanvue.com';
    public const DEFAULT_API_VERSION = '2025-06-26';
    public const SCOPES = 'openid offline_access offline read:self read:insights read:fan';
    private const PAGE_SIZE = 50;
    private const MAX_RATE_LIMIT_WAITS = 3;
    private const MAX_WAIT_SECONDS = 60;
    private const MAX_PAGES = 2000;

    /** @var callable(int): void */
    private $sleeper;

    /** @param (callable(int): void)|null $sleeper pro testy */
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $apiVersion = self::DEFAULT_API_VERSION,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    /** @return array{verifier: string, challenge: string} */
    public static function createPkce(): array
    {
        $verifier = self::base64Url(random_bytes(32));

        return ['verifier' => $verifier, 'challenge' => self::base64Url(hash('sha256', $verifier, true))];
    }

    public function authorizationUrl(string $redirectUri, string $state, string $codeChallenge): string
    {
        return self::AUTH_BASE . '/oauth2/auth?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPES,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $verifier, string $redirectUri): TokenSet
    {
        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId,
            'code_verifier' => $verifier,
        ]);

        return TokenSet::fromTokenResponse($response);
    }

    public function refresh(TokenSet $tokens): TokenSet
    {
        $response = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens->refreshToken,
            'client_id' => $this->clientId,
        ]);

        return TokenSet::fromTokenResponse($response, $tokens->refreshToken);
    }

    /** @return array<string|int, mixed> */
    public function currentUser(string $accessToken): array
    {
        return $this->get($accessToken, '/users/me');
    }

    /**
     * Všechny platby v intervalu [start, end) — stránkování přes kurzor.
     *
     * @return list<array<string, mixed>>
     */
    public function earnings(string $accessToken, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return $this->collectCursor($accessToken, '/insights/earnings', [
            'startDate' => self::isoUtc($start),
            'endDate' => self::isoUtc($end),
            'size' => self::PAGE_SIZE,
        ]);
    }

    /**
     * Denní počty nových a zrušených předplatitelů v intervalu [start, end).
     *
     * @return list<array<string, mixed>>
     */
    public function subscriberStats(string $accessToken, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return $this->collectCursor($accessToken, '/insights/subscribers', [
            'startDate' => self::isoUtc($start),
            'endDate' => self::isoUtc($end),
            'size' => self::PAGE_SIZE,
        ]);
    }

    /**
     * @param array<string, scalar> $query
     * @return list<array<string, mixed>>
     */
    private function collectCursor(string $accessToken, string $path, array $query): array
    {
        $items = [];
        $cursor = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $params = $cursor === null ? $query : $query + ['cursor' => $cursor];
            $data = $this->get($accessToken, $path, $params);
            $batch = $data['data'] ?? null;
            if (!is_array($batch)) {
                throw new FanvueException("Neočekávaná odpověď {$path}: chybí pole data.");
            }
            foreach ($batch as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $next = $data['nextCursor'] ?? null;
            if (!is_string($next) || $next === '' || $next === $cursor) {
                return $items;
            }
            $cursor = $next;
        }

        throw new FanvueException("Stránkování {$path} překročilo limit " . self::MAX_PAGES . ' stránek.');
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string|int, mixed>
     */
    private function get(string $accessToken, string $path, array $query = []): array
    {
        $url = self::API_BASE . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'X-Fanvue-API-Version' => $this->apiVersion,
            'Accept' => 'application/json',
        ];

        for ($attempt = 0; ; $attempt++) {
            $response = $this->send('GET', $url, $headers);
            if ($response->status === 429 && $attempt < self::MAX_RATE_LIMIT_WAITS) {
                ($this->sleeper)($this->retryAfterSeconds($response));
                continue;
            }
            break;
        }

        if ($response->status === 401) {
            throw new FanvueException('Fanvue odmítl přístupový token (401).', true);
        }
        if ($response->status === 403) {
            throw new FanvueException("Fanvue: chybí oprávnění pro {$path} (403). Připoj účet znovu se všemi scopes.", true);
        }
        if (!$response->ok()) {
            throw new FanvueException("Fanvue API {$path} vrátilo HTTP {$response->status}: " . mb_substr($response->body, 0, 300));
        }

        return $response->json();
    }

    /**
     * @param array<string, string> $form
     * @return array<string|int, mixed>
     */
    private function tokenRequest(array $form): array
    {
        if (!$this->isConfigured()) {
            throw new FanvueException('Chybí fanvue.client_id / client_secret v config/config.php.');
        }
        $response = $this->send('POST', self::AUTH_BASE . '/oauth2/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
        ], http_build_query($form, '', '&', PHP_QUERY_RFC1738));

        if (!$response->ok()) {
            $error = '';
            try {
                $body = $response->json();
                $error = (string) ($body['error'] ?? '');
                $description = (string) ($body['error_description'] ?? '');
            } catch (HttpClientException) {
                $description = '';
            }
            $reconnect = $error === 'invalid_grant';
            throw new FanvueException(
                'Fanvue token endpoint: HTTP ' . $response->status . ($error !== '' ? " ({$error}" . ($description !== '' ? ": {$description}" : '') . ')' : '')
                . ($reconnect ? ' — účet je potřeba připojit znovu.' : ''),
                $reconnect
            );
        }

        return $response->json();
    }

    /** @param array<string, string> $headers */
    private function send(string $method, string $url, array $headers, ?string $body = null): HttpResponse
    {
        try {
            return $this->http->request($method, $url, $headers, $body);
        } catch (HttpClientException $e) {
            throw new FanvueException($e->getMessage(), false, $e);
        }
    }

    private function retryAfterSeconds(HttpResponse $response): int
    {
        $value = $response->header('retry-after');
        $seconds = $value !== null && ctype_digit($value) ? (int) $value : 1;

        return max(1, min(self::MAX_WAIT_SECONDS, $seconds));
    }

    private static function isoUtc(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
