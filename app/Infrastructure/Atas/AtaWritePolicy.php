<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Security\SelectedFund;
use App\Models\User;

final class AtaWritePolicy
{
    public function __construct(private SelectedFund $funds) {}

    public function authorize(User $actor, int $fundId): void
    {
        abort_unless($this->funds->allows($actor, $fundId), 403);
        // Atas have no department field: department-only grants cannot authorize a fund-wide write.
        abort_unless($actor->isAdmin() || $actor->scopes()->where('fund_id', $fundId)->whereNull('department_id')
            ->where(fn ($query) => $query->whereNull('department')->orWhere('department', ''))->exists(), 403);
    }
}
