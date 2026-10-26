<?php

namespace App\Infrastructure\Accounts;

use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class UpdateExpirationAlertDays
{
    public function __construct(private LockActor $actors) {}

    public function save(User $expected, mixed $days): void
    {
        DB::transaction(function () use ($expected, $days) {
            $actor = $this->actors->lock($expected);
            Gate::forUser($actor)->authorize('viewAny', Contract::class);
            $data = Validator::make(['alertDaysInput' => $days], [
                'alertDaysInput' => 'required|integer|min:1|max:365',
            ], [], ['alertDaysInput' => 'antecedência em dias'])->validate();
            $actor->expiration_alert_days = (int) $data['alertDaysInput'];
            $actor->save();
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'expiration_preferences_updated',
                'metadata' => ['enabled' => $actor->expiration_alert_enabled, 'days' => $actor->expiration_alert_days], 'created_at' => now()]);
        });
    }
}
