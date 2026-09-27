<?php

declare(strict_types=1);

namespace App\Http;

interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @throws HttpClientException při chybě spojení (ne při HTTP 4xx/5xx)
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
