<?php

namespace App\Jobs;

use App\Models\ActivityEvent;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\AuditReportRenderer;
use App\Services\Reports\ReportRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class GenerateReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $exportId) {}

    public function handle(ReportRenderer $renderer): void
    {
        $export = ReportExport::findOrFail($this->exportId);
        $actor = User::find($export->user_id);
        if (! $actor || ! $actor->is_active || $export->expires_at->isPast() || ! hash_equals($export->access_hash, ReportExport::accessHash($actor))) {
            $export->update(['status' => 'revoked']);

            return;
        }
        $path = storage_path('app/private/report-'.Str::uuid().'.'.$export->format);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        $export->update(['status' => 'processing']);
        try {
            if ($export->report_kind === 'audit') {
                app(AuditReportRenderer::class)->write($actor, $export->filters, $export->format, $path);
            } else {
                $renderer->write($actor, $export->filters, $export->format, $path);
            }
            $actor->refresh();
            if (! hash_equals($export->access_hash, ReportExport::accessHash($actor))) {
                unlink($path);
                $export->update(['status' => 'revoked']);

                return;
            }
            $export->update(['status' => 'ready', 'path' => basename($path)]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'report_generated', 'metadata' => ['export_id' => $export->id, 'format' => $export->format], 'created_at' => now()]);
        } catch (\Throwable $error) {
            @unlink($path);
            $export->update(['status' => 'failed']);
            // Do not expose bindings or documents in a failed queue payload.
            throw new \RuntimeException('Não foi possível gerar o relatório solicitado.');
        }
    }

    public function failed(?\Throwable $error): void
    {
        ReportExport::whereKey($this->exportId)->whereIn('status', ['pending', 'processing'])->update(['status' => 'failed']);
    }
}
