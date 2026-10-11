<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportExport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'expires_at' => 'datetime'];
    }

    public static function accessHash(User $user): string
    {
        return hash('sha256', json_encode([$user->id, $user->is_active, $user->user_type, $user->session_version,
            Contract::visibleTo($user)->orderBy('id')->get(['id', 'fund_id', 'department_id', 'ownership_resolved'])->toArray(),
            $user->scopes()->orderBy('access_scopes.id')->get(['access_scopes.id', 'fund_id', 'department_id', 'department'])->toArray()]));
    }
}
