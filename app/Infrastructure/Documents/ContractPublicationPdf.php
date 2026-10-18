<?php

namespace App\Infrastructure\Documents;

use App\Infrastructure\Managers\ManagerSignatory;
use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ContractPublicationPdf
{
    public function __construct(private LockActor $actors, private DocumentSettings $settings, private PublicationDocumentTemplate $template, private ManagerSignatory $signatories = new ManagerSignatory) {}

    public function generate(User $expected, int $id, int $version): string
    {
        $templateHash = hash_file('sha256', $this->template->path());
        $snapshot = DB::transaction(function () use ($expected, $id, $version) {
            $actor = $this->actors->lock($expected);
            $contract = Contract::whereKey($id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('view', $contract);
            abort_unless((int) $contract->version === $version, 409, 'O contrato mudou. Recarregue a página antes de emitir a publicação.');
            $settings = $this->settings->current();
            $signer = $this->signatories->forContract($contract);

            return ['fields' => $this->fields($contract, $actor, $settings->administration_start_year.'/'.$settings->administration_end_year, $signer),
                'signer' => $this->signatories->fingerprint($signer), 'version' => (int) $contract->version, 'role' => $actor->user_type, 'settingsVersion' => (int) $settings->version];
        });
        $html = $this->template->render($snapshot['fields']);
        $bytes = $this->render($html);

        DB::transaction(function () use ($expected, $id, $snapshot, $templateHash, $bytes) {
            $actor = $this->actors->lock($expected);
            $contract = Contract::whereKey($id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('view', $contract);
            abort_unless($actor->user_type === $snapshot['role'], 403);
            abort_unless((int) $contract->version === $snapshot['version'], 409, 'O contrato mudou durante a emissão. Gere a publicação novamente.');
            $this->signatories->assertUnchanged($contract, $snapshot['signer']);
            $settings = DB::table('document_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            abort_unless((int) $settings->version === $snapshot['settingsVersion'], 409, 'A administração mudou durante a emissão. Gere a publicação novamente.');
            abort_unless(hash_equals($templateHash, hash_file('sha256', $this->template->path())), 409, 'O modelo foi atualizado. Gere a publicação novamente.');
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'contract_publication_generated',
                'metadata' => ['contract_id' => $id, 'contract_version' => $snapshot['version'], 'model' => 'publication',
                    'model_sha256' => $templateHash, 'document_settings_version' => $snapshot['settingsVersion'],
                    'signatory_fingerprint' => $snapshot['signer'], 'administration' => $snapshot['fields']['#ADM'], 'sha256' => hash('sha256', $bytes)], 'created_at' => now()]);
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

    private function fields(Contract $contract, User $actor, string $administration, array $signer): array
    {
        $text = static fn ($value) => filled($value) ? (string) $value : 'Não informado';
        $supplierDocument = Normalizer::document($contract->fornecedor_documento_snapshot);
        $supplierLabel = strlen($supplierDocument) === 11 ? 'CPF' : (strlen($supplierDocument) === 14 ? 'CNPJ' : 'CPF/CNPJ');

        return [
            '#FUNDO' => $text($contract->fundo_nome_snapshot),
            '#ADM' => $administration,
            '#CONTRATO' => $text($contract->numero),
            '#TIPO' => $text($contract->tipo),
            '#PROCESSO' => $text($contract->processo),
            '#MODALIDADE' => $text($contract->modalidade),
            '#N_LICIT' => $text($contract->n_licit),
            '#FORNECEDOR' => $text($contract->fornecedor_nome_snapshot),
            '#CNPJ_FORNECEDOR' => $supplierLabel.': '.$this->document($supplierDocument, $actor),
            '#OBJETO' => $text($contract->objeto),
            '#VALOR' => Contract::moneyLabel($contract->valor_total),
            '#EXTENSO' => $text($contract->ext_total),
            '#DATA' => $contract->data_contrato?->format('d/m/Y') ?? 'Não informado',
            '#NOME_GESTOR' => $text($signer['Nome_Gestor']),
            '#CPF_GESTOR' => $this->document(Normalizer::document($signer['CPF_Gestor']), $actor),
            '#CARGO_GESTOR' => $text($signer['Cargo']),
        ];
    }

    private function document(string $digits, User $actor): string
    {
        if (strlen($digits) === 14) {
            return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digits);
        }
        if (strlen($digits) === 11) {
            return $actor->isAdmin() ? preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $digits) : Normalizer::mask($digits);
        }

        return 'Não informado';
    }
}
