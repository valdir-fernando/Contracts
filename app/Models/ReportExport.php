<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ReportExport extends Model
{
    // Worker timeout is 900s; allow a minute before declaring an attempt abandoned.
    public const PROCESSING_TIMEOUT_SECONDS = 960;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'expires_at' => 'datetime', 'processing_started_at' => 'datetime', 'size' => 'integer'];
    }

    public static function accessHash(User $user): string
    {
        // Keep the read snapshot across batches, without hydrating all visible models.
        // A versioned format intentionally revokes exports produced with the old hash.
        return DB::transaction(function () use ($user) {
            $current = User::withTrashed()->find($user->id);
            $hash = hash_init('sha256');
            hash_update($hash, json_encode(['v2', $user->id, $current?->is_active, $current?->user_type,
                $current?->session_version, $current?->deleted_at], JSON_THROW_ON_ERROR)."\n");
            if ($current) {
                foreach ($current->scopes()->orderBy('access_scopes.id')->get(['access_scopes.id', 'fund_id', 'department_id', 'department']) as $scope) {
                    hash_update($hash, json_encode($scope->toArray(), JSON_THROW_ON_ERROR)."\n");
                }
                foreach (Contract::visibleTo($current)->select(['id', 'fund_id', 'department_id', 'ownership_resolved', 'version', 'updated_at'])
                    ->toBase()->lazyById(500) as $contract) {
                    hash_update($hash, json_encode($contract, JSON_THROW_ON_ERROR)."\n");
                }
            }

            return hash_final($hash);
        });
    }
}
