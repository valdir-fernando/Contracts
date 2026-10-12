<?php

namespace App\Infrastructure\Security;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\User;

/** Revalidate actor snapshots; lock() requires a surrounding mutation transaction. */
class LockActor
{
    public function current(User $expected): User
    {
        $actor = User::find($expected->id);
        abort_unless($actor && $actor->is_active && $actor->session_version === $expected->session_version, 403);

        return $actor;
    }

    public function lock(User $expected): User
    {
        TransactionGuard::requireActive();
        $actor = User::whereKey($expected->id)->lockForUpdate()->firstOrFail();
        abort_unless($actor->is_active && ! $actor->trashed() && $actor->session_version === $expected->session_version, 403);

        return $actor;
    }
}
