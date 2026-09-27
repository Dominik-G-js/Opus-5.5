<?php

declare(strict_types=1);

namespace App\Security;

use App\Kernel\HttpException;
use App\Kernel\Request;
use App\Kernel\Session;

final class Csrf
{
    private const KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }

        return $token;
    }

    public function rotate(): void
    {
        $this->session->set(self::KEY, bin2hex(random_bytes(32)));
    }

    /**
     * Ověří token formuláře a (pokud prohlížeč pošle Origin) i původ požadavku.
     */
    public function verify(Request $request, string $expectedOrigin): void
    {
        $origin = $request->header('Origin');
        if ($origin !== '' && $origin !== 'null' && rtrim($origin, '/') !== $expectedOrigin) {
            throw new HttpException(403, 'Požadavek z cizího původu byl zablokován.');
        }
        $sent = $request->post['_csrf'] ?? '';
        $expected = $this->session->get(self::KEY);
        if (!is_string($sent) || !is_string($expected) || $expected === '' || !hash_equals($expected, $sent)) {
            throw new HttpException(419);
        }
    }
}
