<?php

declare(strict_types=1);

namespace App\Integration\Fanvue;

use RuntimeException;

final class FanvueException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $reconnectRequired = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
