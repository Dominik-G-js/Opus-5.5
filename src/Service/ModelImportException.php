<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/** Import modelky se nezdařil; nese seznam všech nalezených chyb (nic se neuložilo). */
final class ModelImportException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
