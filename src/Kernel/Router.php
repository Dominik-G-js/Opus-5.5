<?php

declare(strict_types=1);

namespace App\Kernel;

/**
 * Jednoduchý router. Vzor „/models/{id}“ → id je číslo, „{slug}“ → [a-z0-9-], „{code}“ → [A-Za-z0-9_-].
 */
final class Router
{
    private const PARAM_PATTERNS = [
        'id' => '[1-9][0-9]{0,9}',
        'versionId' => '[1-9][0-9]{0,9}',
        'toolId' => '[1-9][0-9]{0,9}',
        'slug' => '[a-z0-9](?:[a-z0-9-]{0,78}[a-z0-9])?',
        'code' => '[A-Za-z0-9_-]{3,40}',
        'publicId' => '[a-f0-9]{32}',
    ];

    /** @var list<array{method: string, regex: string, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $parts = preg_split('/\{([a-zA-Z]+)\}/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$pattern];
        $regex = '';
        foreach ($parts as $index => $part) {
            // Liché indexy jsou názvy parametrů, sudé doslovný text.
            $regex .= $index % 2 === 1
                ? '(?P<' . $part . '>' . (self::PARAM_PATTERNS[$part] ?? '[^/]+') . ')'
                : preg_quote($part, '#');
        }
        $this->routes[] = ['method' => $method, 'regex' => '#^' . $regex . '$#', 'handler' => $handler];
    }

    /**
     * @return array{0: callable, 1: array<string, string>}
     */
    public function match(string $method, string $path): array
    {
        $allowed = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }
            $effective = $method === 'HEAD' ? 'GET' : $method;
            if ($route['method'] !== $effective) {
                $allowed = true;
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            return [$route['handler'], array_map('strval', $params)];
        }

        throw new HttpException($allowed ? 405 : 404);
    }
}
