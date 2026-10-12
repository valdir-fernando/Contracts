<?php

namespace App\Application\Contracts\Ports;

use App\Application\Contracts\AuthorizedContract;
use App\Application\Contracts\ValidatedContractInput;

interface ContractInputValidator
{
    /** Validate against current authorized state; never before acquiring its locks. */
    public function validate(AuthorizedContract $context, array $input): ValidatedContractInput;

    public function validateLifecycle(AuthorizedContract $context, array $input): array;
}
