<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Security\SelectedFund;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class AtaWritePolicy
{
    public const PUBLISHED_MESSAGE = 'Esta ATA consta como publicada no TCM e portanto sua edição está bloqueada';

    public function __construct(private SelectedFund $funds) {}

    public function ensureEditable(object $ata): void
    {
        if ($ata->DATA_PUBLIC_INPC !== null) {
            throw ValidationException::withMessages(['publication_date' => self::PUBLISHED_MESSAGE])->status(409);
        }
    }

    public function authorize(User $actor, int $fundId): void
    {
        abort_unless($this->funds->allows($actor, $fundId), 403);
        // Atas have no department field: department-only grants cannot authorize a fund-wide write.
        abort_unless($actor->isAdmin() || $actor->scopes()->where('fund_id', $fundId)->whereNull('department_id')
            ->where(fn ($query) => $query->whereNull('department')->orWhere('department', ''))->exists(), 403);
    }
}
