<?php

namespace App\Infrastructure\Documents;

use App\Infrastructure\Managers\ManagerSignatory;
use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\ContractProduct;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class SupplyContractPdf
{
    public function __construct(private LockActor $actors, private SupplyContractTemplate $template, private ManagerSignatory $signatories, private SupplyContractFigures $figures = new SupplyContractFigures) {}

    public function generate(User $expected, int $id, int $version): string
    {
        $templateHash = hash_file('sha256', $this->template->path());
        $snapshot = DB::transaction(function () use ($expected, $id, $version) {
            [$actor, $contract] = $this->authorize($expected, $id);
            abort_unless((int) $contract->version === $version, 409, 'O contrato mudou. Recarregue a página antes de emitir.');
            abort_unless(Normalizer::name($contract->tipo) === 'FORNECIMENTO', 422, 'O modelo de contrato está disponível somente para Fornecimento.');

            return $this->snapshot($actor, $contract);
        });

        try {
            $products = $this->figures->products($snapshot['products'], $snapshot['contractTotal']);
            $budgets = $this->figures->budgets($snapshot['products'], $snapshot['budgets'], $snapshot['contractTotal']);
        } catch (InvalidArgumentException $error) {
            abort(422, $error->getMessage());
        }
        $bytes = $this->render($this->template->render($snapshot['fields'], $products['items'], $budgets, $products['total']));

        DB::transaction(function () use ($expected, $id, $snapshot, $templateHash, $bytes) {
            [$actor, $contract] = $this->authorize($expected, $id);
            abort_unless($actor->user_type === $snapshot['role'], 403);
            // Recheck the rendered child records as well as the contract revision.
            $this->signatories->assertUnchanged($contract, $snapshot['signer']);
            $current = $this->snapshot($actor, $contract);
            abort_unless(hash_equals($this->fingerprint($snapshot), $this->fingerprint($current)), 409,
                'Os dados do contrato, produtos, dotações ou administração mudaram durante a emissão. Gere o documento novamente.');
            abort_unless(hash_equals($templateHash, hash_file('sha256', $this->template->path())), 409,
                'O modelo foi atualizado durante a emissão. Gere o documento novamente.');
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'contract_document_generated',
                'metadata' => ['contract_id' => $id, 'contract_version' => $snapshot['version'], 'model' => 'supply',
                    'model_sha256' => $templateHash, 'source_fingerprint' => $this->fingerprint($snapshot),
                    'document_settings_version' => $snapshot['settingsVersion'], 'administration' => $snapshot['fields']['#ADM'],
                    'signatory_fingerprint' => $snapshot['signer'], 'sha256' => hash('sha256', $bytes)], 'created_at' => now()]);
        });

        return $bytes;
    }

    private function authorize(User $expected, int $id): array
    {
        $actor = $this->actors->lock($expected);
        $contract = Contract::whereKey($id)->lockForUpdate()->firstOrFail();
        Gate::forUser($actor)->authorize('issue', $contract);

        return [$actor, $contract];
    }

    private function snapshot(User $actor, Contract $contract): array
    {
        $signer = $this->signatories->forContract($contract);
        $settings = DB::table('document_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
        $products = ContractProduct::where('contract_id', $contract->id)->orderBy('id')->lockForUpdate()
            ->get(['id', 'item', 'code', 'description', 'brand', 'unit', 'quantity', 'unit_price', 'total', 'Ficha'])
            ->map(fn ($row) => $row->only(['id', 'item', 'code', 'description', 'brand', 'unit', 'quantity', 'unit_price', 'total', 'Ficha']))->all();
        $fichas = array_values(array_unique(array_map(static fn ($row) => trim((string) ($row['Ficha'] ?? '')), $products), SORT_STRING));
        $budgets = DB::table('tb_Dotacao')->whereIn(DB::raw('TRIM(Ficha)'), $fichas)->orderBy('ID_Dotacao')->lockForUpdate()
            ->get(['ID_Dotacao', 'Ficha', 'Dotacao'])->map(static fn ($row) => ['id' => $row->ID_Dotacao,
                'ficha' => trim((string) $row->Ficha), 'allocation' => $row->Dotacao])->all();

        $text = static fn ($value) => filled($value) ? (string) $value : 'Não informado';
        $display = fn (string $source) => $contract->displayField($source, $actor);
        $personal = static fn ($value) => ! filled($value) ? 'Não informado' : ($actor->isAdmin() ? (string) $value : Normalizer::mask($value));
        $fields = [
            '#CONTRATO' => $text($contract->numero), '#PROCESSO' => $text($contract->processo),
            '#MODALIDADE' => $text($contract->modalidade), '#N_LICIT' => $text($contract->n_licit),
            '#FUNDO' => $text($contract->fundo_nome_snapshot), '#CNPJ_FUNDO' => $this->document($contract->fundo_documento_snapshot, $actor),
            '#END_FUNDO' => $text($contract->end_fundo), '#NOME_GESTOR' => $text($signer['Nome_Gestor']),
            '#CARGO_GESTOR' => $text($signer['Cargo']), '#CPF_GESTOR' => $this->document($signer['CPF_Gestor'], $actor),
            '#RG_GESTOR' => $personal($signer['RG_Gestor']), '#FORNECEDOR' => $text($contract->fornecedor_nome_snapshot),
            '#CNPJ_FORNECEDOR' => $this->document($contract->fornecedor_documento_snapshot, $actor), '#END_FORNECEDOR' => $text($contract->end_fornec),
            '#REPRESENTANTE_FORNECEDOR' => $text($contract->socio), '#CPF_REPRESENTANTE' => $this->document($contract->cpf_socio, $actor),
            '#RG_REPRESENTANTE' => $display('RG_Socio'), '#DATA_CONTRATO' => $display('Data_Cont'),
            '#DATA_LICIT' => $display('Data_Licit'), '#OBJETO' => $text($contract->objeto), '#VIGENCIA' => $text($contract->vigencia),
            '#INICIO' => $display('Inicio_Vig'), '#FINAL' => $display('Final_Vig'),
            '#VL_CONTRATO' => str_replace('R$ ', '', Contract::moneyLabel($contract->valor_total)), '#EXTENSO' => $text($contract->ext_total),
            '#DOTAÇÃO' => '', '#VL_FICHA' => '',
            '#ADM' => $settings->administration_start_year.'/'.$settings->administration_end_year,
        ];

        return ['version' => (int) $contract->version, 'type' => $contract->tipo, 'role' => $actor->user_type,
            'settingsVersion' => (int) $settings->version, 'signer' => $this->signatories->fingerprint($signer),
            'contractTotal' => $contract->valor_total, 'fields' => $fields, 'products' => $products, 'budgets' => $budgets];
    }

    private function fingerprint(array $snapshot): string
    {
        return hash_hmac('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function document(?string $value, User $actor): string
    {
        $digits = Normalizer::document($value);
        if (strlen($digits) === 14) {
            return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digits);
        }
        if (strlen($digits) === 11) {
            return $actor->isAdmin() ? preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $digits) : Normalizer::mask($digits);
        }

        return 'Não informado';
    }

    protected function render(string $html): string
    {
        $pdf = new Dompdf(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf->setPaper('A4');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $reference = (new \DOMXPath($document))->evaluate('string(//meta[@name="contract-reference"]/@content)');
        $pdf->getCanvas()->page_script(static function ($page, $pages, $canvas, $metrics) use ($reference) {
            $font = $metrics->getFont('DejaVu Sans');
            $text = $reference.' & Página '.$page.' de '.$pages;
            $size = min(8, 8 * 514.28 / max(1, $metrics->getTextWidth($text, $font, 8)));
            $width = $metrics->getTextWidth($text, $font, $size);
            $canvas->text(565.28 - $width, 88, $text, $font, $size, [0.15, 0.15, 0.15]);
        });

        return $pdf->output();
    }
}
