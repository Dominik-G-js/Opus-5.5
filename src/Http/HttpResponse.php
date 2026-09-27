<?php

declare(strict_types=1);

namespace App\Http;

use JsonException;

final class HttpResponse
{
    /** @param array<string, string> $headers Názvy hlaviček malými písmeny. */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array<string|int, mixed>
     * @throws HttpClientException
     */
    public function json(): array
    {
        try {
            $data = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new HttpClientException('Neplatná JSON odpověď (HTTP ' . $this->status . '): ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data)) {
            throw new HttpClientException('JSON odpověď není objekt ani pole.');
        }

        return $data;
    }
}
