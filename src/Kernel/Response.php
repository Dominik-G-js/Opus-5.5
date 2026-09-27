<?php

declare(strict_types=1);

namespace App\Kernel;

final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var (callable(): void)|null */
    private $streamer = null;

    public function __construct(private string $body = '', private int $status = 200)
    {
    }

    public static function html(string $body, int $status = 200): self
    {
        return (new self($body, $status))->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public static function text(string $body, int $status = 200, string $type = 'text/plain'): self
    {
        return (new self($body, $status))->withHeader('Content-Type', $type . '; charset=UTF-8');
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return (new self('', $status))->withHeader('Location', $location);
    }

    public static function file(string $path, string $mime, bool $public): self
    {
        $response = new self('', 200);
        $response->streamer = static function () use ($path): void {
            readfile($path);
        };

        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', $public ? 'public, max-age=604800, immutable' : 'private, max-age=3600')
            ->withHeader('Content-Disposition', 'inline');
    }

    public function withHeader(string $name, string $value): self
    {
        // Ochrana proti header injection.
        $this->headers[$name] = str_replace(["\r", "\n"], '', $value);

        return $this;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function send(): void
    {
        header_remove('X-Powered-By');
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->streamer !== null) {
            ($this->streamer)();

            return;
        }
        echo $this->body;
    }
}
