<?php

namespace App\Infrastructure\Documents;

use App\Infrastructure\Managers\ManagerSignatory;
use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractServiceOrder;
use App\Models\User;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ServiceOrderPdf
{
    public function __construct(private LockActor $actors, private ServiceOrderTemplate $template = new ServiceOrderTemplate) {}

    private function snapshot(User $expected, int $contractId, int $orderId): array
    {
        $actor = $this->actors->lock($expected);
        $contract = Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
        Gate::forUser($actor)->authorize('update', $contract);
        $order = ContractServiceOrder::where('contract_id', $contractId)->whereKey($orderId)->lockForUpdate()->firstOrFail();
        $model = $this->template->model($contract->tipo);
        $settings = DB::table('document_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
        $signatory = (new ManagerSignatory)->forContract($contract);
        $amendment = $order->amendment_id ? ContractAmendment::withTrashed()->where('contract_id', $contractId)
            ->whereKey($order->amendment_id)->lockForUpdate()->firstOrFail() : null;

        return ['version' => (int) $contract->version, 'role' => $actor->user_type,
            'model' => $model, 'template_hash' => $this->template->hash($model), 'settings_version' => (int) $settings->version,
            'details' => ['fund_document' => $contract->displayField('CNPJ_Fundo', $actor), 'fund_address' => $contract->end_fundo,
                'administration' => $settings->administration_start_year.'/'.$settings->administration_end_year,
                'process' => $contract->processo, 'procurement' => $contract->modalidade, 'bid' => $contract->n_licit,
                'supplier_document' => $contract->displayField('CPF_CNPJ', $actor), 'location' => $contract->local_servico,
                'inspector' => $contract->nome_fiscal, 'manager' => $signatory['Nome_Gestor'] ?? null,
                'manager_role' => $signatory['Cargo'] ?? null, 'supplier_signatory' => $contract->socio,
                'amendment' => $amendment ? 'Aditivo '.$amendment->number : null],
            'fields' => ['Fundo' => $contract->fundo_nome_snapshot, 'Fornecedor' => $contract->fornecedor_nome_snapshot,
                'Contrato' => $contract->numero, 'Objeto' => $contract->objeto, 'Nº OS' => $order->number,
                'Data OS' => $order->issued_on?->format('d/m/Y'), 'Início' => $order->starts_on?->format('d/m/Y'),
                'Final' => $order->ends_on?->format('d/m/Y'), 'Período' => $order->deadline, 'Observações' => $order->notes],
            'amendment_id' => $order->amendment_id];
    }

    public function generate(User $expected, int $contractId, int $orderId): string
    {
        $snapshot = DB::transaction(fn () => $this->snapshot($expected, $contractId, $orderId));
        $html = $this->template->render($snapshot);
        $bytes = $this->render($html);
        DB::transaction(function () use ($expected, $contractId, $orderId, $snapshot, $bytes) {
            $current = $this->snapshot($expected, $contractId, $orderId);
            abort_unless($current === $snapshot, 409, 'Os dados mudaram durante a emissão. Gere o PDF novamente.');
            ActivityEvent::create(['actor_id' => $expected->id, 'action' => 'service_order_pdf_generated',
                'metadata' => ['contract_id' => $contractId, 'service_order_id' => $orderId,
                    'contract_version' => $snapshot['version'], 'model' => $snapshot['model'], 'model_sha256' => $snapshot['template_hash'],
                    'document_settings_version' => $snapshot['settings_version'], 'sha256' => hash('sha256', $bytes)], 'created_at' => now()]);
        });

        return $bytes;
    }

    protected function render(string $html): string
    {
        $pdf = new Dompdf(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf->setPaper('A4');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }
}
