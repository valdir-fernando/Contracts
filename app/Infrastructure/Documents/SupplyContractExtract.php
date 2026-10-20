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

class SupplyContractExtract
{
    public function __construct(private LockActor $actors, private DocumentSettings $settings, private SupplyExtractTemplate $template, private ManagerSignatory $signatories = new ManagerSignatory) {}

    public static function modelFor(?string $type): ?string
    {
        return match (Normalizer::name($type)) {
            'FORNECIMENTO' => 'supply',
            'LOCACAO DE IMOVEL' => 'property-lease',
            default => null,
        };
    }

    public function generate(User $expected, int $id, int $version): string
    {
        $snapshot = DB::transaction(function () use ($expected, $id, $version) {
            $actor = $this->actors->lock($expected);
            $contract = Contract::whereKey($id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('issue', $contract);
            abort_unless((int) $contract->version === $version, 409, 'O contrato mudou. Recarregue a página antes de emitir o extrato.');
            $model = self::modelFor($contract->tipo);
            abort_if($model === null, 422, 'Extrato disponível para Fornecimento e Locação de Imóvel.');
            $settings = $this->settings->current();
            $signer = $this->signer($contract);

            return ['fields' => $this->fields($contract, $actor, $settings->administration_start_year.'/'.$settings->administration_end_year, $signer),
                'model' => $model, 'templateHash' => hash_file('sha256', $this->template->path($model)),
                'signer' => $this->signatories->fingerprint($signer), 'version' => (int) $contract->version, 'role' => $actor->user_type, 'settingsVersion' => (int) $settings->version];
        });
        $html = $this->template->render($snapshot['fields'], $snapshot['model']);
        $bytes = $this->render($html);
        // Rendering is outside the write transaction. Reauthorize before delivering any personal data.
        DB::transaction(function () use ($expected, $id, $snapshot, $bytes) {
            $actor = $this->actors->lock($expected);
            $contract = Contract::whereKey($id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('issue', $contract);
            abort_unless($actor->user_type === $snapshot['role'], 403);
            abort_unless((int) $contract->version === $snapshot['version'], 409, 'O contrato mudou durante a emissão. Gere o extrato novamente.');
            abort_unless(self::modelFor($contract->tipo) === $snapshot['model'], 409, 'O tipo do contrato mudou durante a emissão. Gere o extrato novamente.');
            abort_unless(hash_equals($snapshot['signer'], $this->signatories->fingerprint($this->signer($contract))), 409,
                'O gestor salvo no contrato mudou durante a emissão. Gere o extrato novamente.');
            $settings = DB::table('document_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            abort_unless((int) $settings->version === $snapshot['settingsVersion'], 409, 'A administração mudou durante a emissão. Gere o extrato novamente.');
            abort_unless(hash_equals($snapshot['templateHash'], hash_file('sha256', $this->template->path($snapshot['model']))), 409, 'O modelo foi atualizado. Gere o extrato novamente.');
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'contract_extract_generated',
                'metadata' => ['contract_id' => $id, 'contract_version' => $snapshot['version'], 'model' => $snapshot['model'],
                    'model_sha256' => $snapshot['templateHash'], 'document_settings_version' => $snapshot['settingsVersion'],
                    'signatory_fingerprint' => $snapshot['signer'], 'administration' => $snapshot['fields']['#ADM'], 'sha256' => hash('sha256', $bytes)], 'created_at' => now()]);
        });

        return $bytes;
    }

    private function signer(Contract $contract): array
    {
        return ['Nome_Gestor' => $contract->nome_gestor, 'CPF_Gestor' => $contract->cpf_gestor, 'Cargo' => $contract->cargo_gestor];
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
        $document = Normalizer::document($contract->fornecedor_documento_snapshot);
        $label = strlen($document) === 11 ? 'CPF' : (strlen($document) === 14 ? 'CNPJ' : 'CPF/CNPJ');

        return [
            '#Fundo' => $text($contract->fundo_nome_snapshot), '#ADM' => $administration, '#Contrato' => $text($contract->numero),
            '#Processo' => $text($contract->processo), '#Licitacao' => $text($contract->modalidade), '#N_Licit' => $text($contract->n_licit),
            '#Fornecedor' => $text($contract->fornecedor_nome_snapshot), '#CNPJ' => $label.': '.$this->document($document, $actor),
            '#FUNDO' => $text($contract->fundo_nome_snapshot), '#NomeLocador' => $text($contract->fornecedor_nome_snapshot),
            '#EndImovel' => $text($contract->end_imovel), '#Instalacoes' => $text($contract->instalacoes),
            '#VLMensal' => str_replace('R$ ', '', Contract::moneyLabel($contract->vl_parcial)), '#ExtMensal' => $text($contract->ext_parcial),
            '#Objeto' => $text($contract->objeto), '#Vigencia' => $text($contract->vigencia),
            '#Início' => $contract->vigencia_inicio?->format('d/m/Y') ?? 'Não informado',
            '#Final' => $contract->vigencia_fim_original?->format('d/m/Y') ?? 'Não informado',
            '#ExtTotal' => $text($contract->ext_total), '#VLTotal' => str_replace('R$ ', '', Contract::moneyLabel($contract->valor_total)),
            '#NomeGestor' => $text($signer['Nome_Gestor']), '#CPFGestor' => $this->document(Normalizer::document($signer['CPF_Gestor']), $actor),
            '#Cargo' => $text($signer['Cargo']), '#DataContrato' => $contract->data_contrato?->format('d/m/Y') ?? 'Não informado',
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
