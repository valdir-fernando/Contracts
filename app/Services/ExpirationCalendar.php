<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class ExpirationCalendar
{
    public function query(User $user, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return Contract::visibleTo($user)->reportEndingBetween($from, $to)
            ->orderBy('vigencia_fim_atual')->orderBy('id');
    }
}
