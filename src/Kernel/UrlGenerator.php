<?php

declare(strict_types=1);

namespace App\Kernel;

use InvalidArgumentException;

final class UrlGenerator
{
    private string $origin;
    private string $basePath;

    public function __construct(string $baseUrl, private readonly string $adminPath)
    {
        $parts = parse_url(rtrim($baseUrl, '/'));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('base_url v konfiguraci musí být absolutní adresa (https://…).');
        }
        $this->origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->basePath = rtrim($parts['path'] ?? '', '/');
    }

    public function origin(): string
    {
        return $this->origin;
    }

    public function host(): string
    {
        return (string) parse_url($this->origin, PHP_URL_HOST);
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function adminPath(): string
    {
        return $this->adminPath;
    }

    /** @param array<string, scalar> $query */
    public function admin(string $path = '', array $query = []): string
    {
        return $this->basePath . $this->adminPath . $this->normalize($path) . $this->query($query);
    }

    /** @param array<string, scalar> $query */
    public function absoluteAdmin(string $path = '', array $query = []): string
    {
        return $this->origin . $this->admin($path, $query);
    }

    public function public(string $path): string
    {
        return $this->basePath . $this->normalize($path);
    }

    public function absolutePublic(string $path): string
    {
        return $this->origin . $this->public($path);
    }

    /** Kanonická adresa landing page modelky — vlastní doména, nebo /m/{slug}. */
    public function modelPage(string $slug, ?string $domain): string
    {
        return $domain !== null && $domain !== '' ? 'https://' . $domain . '/' : $this->absolutePublic('/m/' . $slug);
    }

    private function normalize(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '';
        }

        return '/' . ltrim($path, '/');
    }

    /** @param array<string, scalar> $query */
    private function query(array $query): string
    {
        $query = array_filter($query, static fn ($v): bool => $v !== '' && $v !== null);

        return $query === [] ? '' : '?' . http_build_query($query);
    }
}
