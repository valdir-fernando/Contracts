<?php

namespace App\Application\Ports;

interface TransactionManager
{
    /** Run the entire mutation, including authorization and audit, atomically. */
    public function run(callable $operation): mixed;
}
