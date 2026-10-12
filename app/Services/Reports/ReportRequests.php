<?php

namespace App\Services\Reports;

use App\Infrastructure\Security\LockActor;
use App\Jobs\GenerateReport;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ReportRequests
{
    public function __construct(private LockActor $actors, private ContractReport $reports) {}

    public function create(User $expected, string $format, array $filters, string $kind = 'contracts'): ReportExport
    {
        $export = DB::transaction(function () use ($expected, $format, $filters, $kind) {
            $actor = $this->actors->lock($expected);
            Gate::forUser($actor)->authorize('viewAny', Contract::class);
            if ($kind === 'audit') {
                abort_unless($actor->isAdmin(), 403);
            } else {
                $this->reports->query($actor, $filters);
            }
            $export = ReportExport::create(['user_id' => $actor->id, 'format' => $format, 'filters' => $filters,
                'report_kind' => $kind, 'access_hash' => ReportExport::accessHash($actor), 'expires_at' => now()->addDay()]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'report_requested',
                'metadata' => ['export_id' => $export->id, 'format' => $format, 'kind' => $kind], 'created_at' => now()]);

            return $export;
        });
        try {
            GenerateReport::dispatch($export->id);
        } catch (\Throwable $error) {
            ReportExport::whereKey($export->id)->where('status', 'pending')->update(['status' => 'failed']);
            throw $error;
        }

        return $export;
    }
}
