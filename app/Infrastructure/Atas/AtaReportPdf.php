<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\User;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;

class AtaReportPdf
{
    public function __construct(private AtaReportQuery $query, private LockActor $actors) {}

    public function generate(User $expected, array $filters): string
    {
        $data = $this->query->for($expected, $filters, false);
        $hash = hash('sha256', json_encode([$data['rows'], $data['filters'], $data['total']], JSON_THROW_ON_ERROR));
        $content = $this->render($data);
        DB::transaction(function () use ($expected, $filters, $hash, $data) {
            $actor = $this->actors->lock($expected);
            $current = $this->query->for($actor, $filters, false);
            abort_unless(hash_equals($hash, hash('sha256', json_encode([$current['rows'], $current['filters'], $current['total']], JSON_THROW_ON_ERROR))), 409, 'Os dados das atas foram atualizados. Gere o relatório novamente.');
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'report_exported',
                'metadata' => ['format' => 'pdf', 'report' => 'ata-panel', 'count' => $data['count']], 'created_at' => now()]);
        });

        return $content;
    }

    protected function render(array $data): string
    {
        $pdf = new Dompdf(['isRemoteEnabled' => false]);
        $pdf->setPaper('A4', 'landscape');
        $pdf->loadHtml(view('reports.atas-pdf', $data)->render());
        $pdf->render();

        return $pdf->output();
    }
}
