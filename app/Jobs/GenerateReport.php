<?php

namespace App\Jobs;

use App\Models\ActivityEvent;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\AuditReportRenderer;
use App\Services\Reports\ReportRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class GenerateReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public ?string $processingToken = null;

    public function __construct(public int $exportId)
    {
        // Persist in the queue payload too: Laravel reconstructs the job for failed().
        $this->processingToken = (string) Str::uuid();
    }

    public function handle(ReportRenderer $renderer): void
    {
        $export = ReportExport::findOrFail($this->exportId);
        if ($export->status !== 'pending') {
            return;
        }
        $actor = User::find($export->user_id);
        if (! $actor || ! $actor->is_active || ! $export->expires_at->isFuture() || ! hash_equals($export->access_hash, ReportExport::accessHash($actor))) {
            ReportExport::whereKey($export->id)->where('status', 'pending')->update(['status' => 'revoked']);

            return;
        }
        $token = $this->processingToken ??= (string) Str::uuid();
        if (! ReportExport::whereKey($export->id)->where('status', 'pending')->update([
            'status' => 'processing', 'processing_token' => $token, 'processing_started_at' => now(),
        ])) {
            return;
        }
        $path = storage_path('app/private/report-'.Str::uuid().'.'.$export->format);
        try {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0770, true);
            }
            if ($export->report_kind === 'audit') {
                app(AuditReportRenderer::class)->write($actor, $export->filters, $export->format, $path);
            } else {
                $renderer->write($actor, $export->filters, $export->format, $path);
            }
            $digest = hash_file('sha256', $path);
            $size = filesize($path);
            if ($digest === false || $size === false) {
                throw new \RuntimeException('Arquivo de relatório indisponível.');
            }
            $published = DB::transaction(function () use ($actor, $token, $path, $digest, $size) {
                // Actor first; a timed-out delivery must never publish another attempt's result.
                $currentActor = User::whereKey($actor->id)->lockForUpdate()->first();
                $current = ReportExport::whereKey($this->exportId)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'processing' || $current->processing_token !== $token) {
                    return false;
                }
                if (! $currentActor || ! $currentActor->is_active || ! $current->expires_at->isFuture()
                    || ! hash_equals($current->access_hash, ReportExport::accessHash($currentActor))) {
                    $current->update(['status' => 'revoked', 'processing_token' => null, 'processing_started_at' => null]);

                    return false;
                }
                $current->update(['status' => 'ready', 'path' => basename($path), 'sha256' => $digest, 'size' => $size,
                    'processing_token' => null, 'processing_started_at' => null]);
                ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'report_generated', 'metadata' => ['export_id' => $current->id, 'format' => $current->format], 'created_at' => now()]);

                return true;
            });
            if (! $published) {
                @unlink($path);
            }
        } catch (\Throwable $error) {
            @unlink($path);
            $revoked = $error instanceof HttpExceptionInterface && $error->getStatusCode() === 403;
            $this->finishFailedAttempt($revoked ? 'revoked' : 'failed');
            if ($revoked) {
                return;
            }
            // Do not expose bindings or documents in a failed queue payload.
            throw new \RuntimeException('Não foi possível gerar o relatório solicitado.');
        }
    }

    private function finishFailedAttempt(string $status): void
    {
        if ($this->processingToken === null) {
            // Older queued payloads have no attempt identity; cleanup handles abandonment.
            return;
        }
        ReportExport::whereKey($this->exportId)->where('status', 'processing')->where('processing_token', $this->processingToken)
            ->update(['status' => $status, 'path' => null, 'sha256' => null, 'size' => null,
                'processing_token' => null, 'processing_started_at' => null]);
    }

    public function failed(?\Throwable $error): void
    {
        $this->finishFailedAttempt('failed');
    }
}
