<?php

namespace App\Console\Commands;

use App\Models\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneReportExports extends Command
{
    protected $signature = 'contracts:prune-exports';

    protected $description = 'Expira arquivos e encerra gerações abandonadas, preservando eventos e metadados';

    public function handle(): int
    {
        $cutoff = now()->subSeconds(ReportExport::PROCESSING_TIMEOUT_SECONDS);
        $candidates = ReportExport::where(function ($query) use ($cutoff) {
            $query->where(fn ($expired) => $expired->where('expires_at', '<=', now())
                ->where(fn ($state) => $state->where('status', '!=', 'expired')->orWhereNotNull('path')))
                ->orWhere(fn ($processing) => $processing->where('status', 'processing')
                    ->where(fn ($started) => $started->where('processing_started_at', '<=', $cutoff)
                        ->orWhere(fn ($legacy) => $legacy->whereNull('processing_started_at')->where('updated_at', '<=', $cutoff))));
        });
        foreach ($candidates->lazyById(100) as $candidate) {
            DB::transaction(function () use ($candidate, $cutoff) {
                $export = ReportExport::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                $expired = ! $export->expires_at->isFuture();
                $abandoned = $export->status === 'processing' && ($export->processing_started_at ?? $export->updated_at)->lte($cutoff);
                if (! $expired && ! $abandoned) {
                    return;
                }
                if ($export->path !== null) {
                    $path = storage_path('app/private/'.basename($export->path));
                    if (is_file($path) && ! unlink($path)) {
                        throw new \RuntimeException('Não foi possível remover a exportação expirada.');
                    }
                }
                $export->update(['status' => $expired ? 'expired' : 'failed', 'path' => null, 'sha256' => null, 'size' => null,
                    'processing_token' => null, 'processing_started_at' => null]);
            });
        }
        foreach (glob(storage_path('app/private/{report-,detail-,audit-}*'), GLOB_BRACE) ?: [] as $path) {
            if (is_file($path) && filemtime($path) < now()->subDay()->timestamp) {
                unlink($path);
            }
        }
        $this->info('Exportações expiradas removidas e gerações abandonadas encerradas.');

        return self::SUCCESS;
    }
}
