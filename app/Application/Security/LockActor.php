<?php

namespace App\Application\Security;

use App\Models\User;

/** Revalidate the actor snapshot under the surrounding mutation transaction. */
class LockActor
{
    public function lock(User $expected): User
    {
        $actor = User::whereKey($expected->id)->lockForUpdate()->firstOrFail();
        abort_unless($actor->is_active && ! $actor->trashed() && $actor->session_version === $expected->session_version, 403);

        return $actor;
    }
}
