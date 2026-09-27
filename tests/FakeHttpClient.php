<?php

declare(strict_types=1);

use App\Http\HttpClient;
use App\Http\HttpResponse;

/**
 * Testovací HTTP klient: vrací předem připravené odpovědi a zaznamenává požadavky.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @param list<HttpResponse|callable(string, string): HttpResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($this->responses);
        if ($next === null) {
            throw new RuntimeException("Neočekávaný požadavek {$method} {$url}");
        }

        return $next instanceof HttpResponse ? $next : $next($method, $url);
    }

    /** @param array<string|int, mixed> $data */
    public static function json(array $data, int $status = 200, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers, json_encode($data, JSON_THROW_ON_ERROR));
    }
}
