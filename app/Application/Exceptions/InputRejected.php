<?php

namespace App\Application\Exceptions;

use RuntimeException;

final class InputRejected extends RuntimeException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Os dados informados precisam ser corrigidos.');
    }
}
