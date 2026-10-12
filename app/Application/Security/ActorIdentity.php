<?php

namespace App\Application\Security;

/** Identity from the authenticated caller; permissions are reloaded under lock. */
final readonly class ActorIdentity
{
    public function __construct(public int $id, public int $sessionVersion) {}
}
