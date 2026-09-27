<?php

declare(strict_types=1);

namespace App\Http;

final class CurlHttpClient implements HttpClient
{
    private const MAX_BODY_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly int $timeoutSeconds = 20,
        private readonly string $userAgent = 'AIModelStudio/1.0 (+self-hosted)',
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            throw new HttpClientException('Povolené jsou jen HTTPS požadavky.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new HttpClientException('Nelze inicializovat cURL.');
        }
        $responseHeaders = [];
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOPROGRESS => false,
            // Nenulová návratová hodnota přeruší přenos — ochrana proti obřím odpovědím.
            CURLOPT_PROGRESSFUNCTION => static fn ($ch, int $dlTotal, int $dlNow): int => $dlNow > self::MAX_BODY_BYTES ? 1 : 0,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        unset($handle);

        if ($result === false || !is_string($result)) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            throw new HttpClientException("Spojení s {$host} selhalo: {$error}");
        }

        return new HttpResponse($status, $responseHeaders, $result);
    }
}
