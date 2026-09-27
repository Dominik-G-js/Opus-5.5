<?php

declare(strict_types=1);

namespace App\Kernel;

use App\Form\FormInput;

final class Request
{
    /** @var array<string, string> */
    private array $routeParams = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly array $server,
        public readonly string $clientIp,
        public readonly bool $secure,
        public readonly string $host,
    ) {
    }

    /**
     * @param list<string> $trustedProxies CIDR rozsahy reverzních proxy (např. Cloudflare), kterým věříme X-Forwarded-*.
     */
    public static function fromGlobals(string $basePath, array $trustedProxies): self
    {
        $server = $_SERVER;
        $remote = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '0.0.0.0';
        $fromTrustedProxy = IpRange::matchesAny($remote, $trustedProxies);

        $clientIp = $remote;
        if ($fromTrustedProxy && is_string($server['HTTP_X_FORWARDED_FOR'] ?? null)) {
            // Bereme první adresu zprava, která nepatří důvěryhodné proxy.
            $hops = array_reverse(array_map('trim', explode(',', $server['HTTP_X_FORWARDED_FOR'])));
            foreach ($hops as $hop) {
                if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                    break;
                }
                $clientIp = $hop;
                if (!IpRange::matchesAny($hop, $trustedProxies)) {
                    break;
                }
            }
        }

        $secure = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
            || (int) ($server['SERVER_PORT'] ?? 0) === 443
            || ($fromTrustedProxy && strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

        $uriPath = parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($uriPath) ? rawurldecode($uriPath) : '/';
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . trim($path, '/');

        return new self(
            method: strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            path: $path,
            query: $_GET,
            post: $_POST,
            files: $_FILES,
            server: $server,
            clientIp: $clientIp,
            secure: $secure,
            host: self::normalizeHost((string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '')),
        );
    }

    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        // Odstranění portu (i u IPv6 v hranatých závorkách).
        if (preg_match('/^\[([0-9a-f:.]+)\](?::\d+)?$/', $host, $m) === 1) {
            return $m[1];
        }
        $host = (string) preg_replace('/:\d+$/', '', $host);

        return preg_match('/^[a-z0-9.-]{1,253}$/', $host) === 1 ? $host : '';
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /** Odeslaný formulář (POST) — sdílený vstup pro validaci v src/Form. */
    public function form(): FormInput
    {
        return new FormInput($this->post);
    }

    public function input(string $key, string $default = ''): string
    {
        return $this->form()->input($key, $default);
    }

    /** Surová hodnota bez trim (pro prompty, kde záleží na formátování). */
    public function rawInput(string $key): string
    {
        return $this->form()->rawInput($key);
    }

    public function checkbox(string $key): bool
    {
        return $this->form()->checkbox($key);
    }

    /** @return list<string> */
    public function inputList(string $key): array
    {
        $value = $this->post[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $v): string => is_string($v) ? trim($v) : '',
            $value
        ), static fn (string $v): bool => $v !== ''));
    }

    public function query(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;

        return is_string($value) ? trim($value) : $default;
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /** @return array<string, mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }

    /** @param array<string, string> $params */
    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;

        return $clone;
    }

    public function param(string $name): string
    {
        return $this->routeParams[$name] ?? '';
    }

    public function intParam(string $name): int
    {
        return (int) ($this->routeParams[$name] ?? 0);
    }
}
