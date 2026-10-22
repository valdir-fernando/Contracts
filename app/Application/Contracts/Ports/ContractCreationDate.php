<?php

namespace App\Application\Contracts\Ports;

interface ContractCreationDate
{
    /** Trusted creation date in YYYY-MM-DD; resolved before the write transaction. */
    public function today(): string;
}
