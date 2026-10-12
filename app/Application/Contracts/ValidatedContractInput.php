<?php

namespace App\Application\Contracts;

/** Separates validated control values from the writable, normalized field patch. */
final readonly class ValidatedContractInput
{
    public function __construct(public array $values, public array $fields) {}
}
