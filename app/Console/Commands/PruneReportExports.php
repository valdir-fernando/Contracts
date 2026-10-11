<?php

namespace App\Console\Commands;

use App\Models\ReportExport;
use Illuminate\Console\Command;

class PruneReportExports extends Command
{
    protected $signature = 'contracts:prune-exports';

    protected $description = 'Remove arquivos temporários expirados de relatórios, preservando eventos e metadados';

    public function handle(): int
    {
        foreach (ReportExport::where('expires_at', '<', now())->whereNotNull('path')->cursor() as $export) {
            $path = storage_path('app/private/'.basename($export->path));
            if (is_file($path)) {
                unlink($path);
            }
            $export->update(['status' => 'expired', 'path' => null]);
        }
        foreach (glob(storage_path('app/private/{report-,detail-,audit-}*'), GLOB_BRACE) ?: [] as $path) {
            if (is_file($path) && filemtime($path) < now()->subDay()->timestamp) {
                unlink($path);
            }
        }
        $this->info('Arquivos temporários expirados removidos.');

        return self::SUCCESS;
    }
}
