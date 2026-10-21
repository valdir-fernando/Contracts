<?php

namespace App\Infrastructure\Documents;

use App\Infrastructure\Contracts\OriginalContractProducts;
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

    public static function modelFor(?string $type): ?string
    {
        return match (Normalizer::name($type)) {
            'FORNECIMENTO' => 'supply',
            'SERVICOS DIVERSOS' => 'services',
            'LOCACAO DE IMOVEL' => 'property-lease',
            default => null,
        };
    }

    public function generate(User $expected, int $id, int $version): string
    {
        $snapshot = DB::transaction(function () use ($expected, $id, $version) {
            [$actor, $contract] = $this->authorize($expected, $id);
            abort_unless((int) $contract->version === $version, 409, 'O contrato mudou. Recarregue a página antes de emitir.');
            abort_if(self::modelFor($contract->tipo) === null, 422, 'O modelo de contrato está disponível para Fornecimento, Serviços Diversos e Locação de Imóvel.');

            return $this->snapshot($actor, $contract);
        });
        $templateHash = hash_file('sha256', $this->template->path($snapshot['model']));

        try {
            $products = $this->figures->products($snapshot['products'], $snapshot['contractTotal'], $snapshot['model'] === 'supply');
            $budgets = $this->figures->budgets($snapshot['products'], $snapshot['budgets'], $snapshot['contractTotal']);
        } catch (InvalidArgumentException $error) {
            abort(422, $error->getMessage());
        }
        $bytes = $this->render($this->template->render($snapshot['fields'], $products['items'], $budgets, $products['total'], $snapshot['model']));

        DB::transaction(function () use ($expected, $id, $snapshot, $templateHash, $bytes) {
            [$actor, $contract] = $this->authorize($expected, $id);
            abort_unless($actor->user_type === $snapshot['role'], 403);
            // Recheck the rendered child records as well as the contract revision.
            $this->signatories->assertUnchanged($contract, $snapshot['signer']);
            $current = $this->snapshot($actor, $contract);
            abort_unless(hash_equals($this->fingerprint($snapshot), $this->fingerprint($current)), 409,
                'Os dados do contrato, produtos, dotações ou administração mudaram durante a emissão. Gere o documento novamente.');
            abort_unless(hash_equals($templateHash, hash_file('sha256', $this->template->path($snapshot['model']))), 409,
                'O modelo foi atualizado durante a emissão. Gere o documento novamente.');
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'contract_document_generated',
                'metadata' => ['contract_id' => $id, 'contract_version' => $snapshot['version'], 'model' => $snapshot['model'],
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
        $originalIds = (new OriginalContractProducts)->forContract($contract)->pluck('id');
        $products = ContractProduct::where('contract_id', $contract->id)->whereIn('id', $originalIds)->orderBy('id')->lockForUpdate()
            ->get(['id', 'item', 'code', 'description', 'brand', 'unit', 'quantity', 'unit_price', 'total', 'Ficha'])
            ->map(fn ($row) => $row->only(['id', 'item', 'code', 'description', 'brand', 'unit', 'quantity', 'unit_price', 'total', 'Ficha']))->all();
        $fichas = array_values(array_unique(array_map(static fn ($row) => trim((string) ($row['Ficha'] ?? '')), $products), SORT_STRING));
        $budgets = DB::table('tb_Dotacao')->whereNull('deleted_at')->whereIn(DB::raw('TRIM(Ficha)'), $fichas)->orderBy('ID_Dotacao')->lockForUpdate()
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

        if (self::modelFor($contract->tipo) === 'property-lease') {
            $appointment = $this->leaseAppointment($contract, $signer);
            $digits = Normalizer::document($contract->fornecedor_documento_snapshot);
            $individual = strlen($digits) === 11;
            $details = ($individual ? 'CPF: ' : 'CNPJ: ').$this->document($contract->fornecedor_documento_snapshot, $actor)
                .', endereço: '.$text($contract->end_fornec);
            if ($individual) {
                $details .= ', RG: '.$display('RG_Fornec');
            } else {
                $details .= ', representante: '.$text($contract->socio).', CPF: '.$this->document($contract->cpf_socio, $actor)
                    .', RG: '.$display('RG_Socio');
            }
            $fields += [
                '#Contrato' => $fields['#CONTRATO'], '#Processo' => $fields['#PROCESSO'],
                '#Modalidade' => $fields['#MODALIDADE'], '#N_Modalidade' => $fields['#N_LICIT'],
                '#End_Fundo' => $fields['#END_FUNDO'], '#Cargo_Gestor' => $fields['#CARGO_GESTOR'],
                '#NomeGestor' => $fields['#NOME_GESTOR'], '#Decreto' => $text($appointment['Decreto'] ?? null),
                '#Matricula' => $text($appointment['Matricula'] ?? null),
                '#PF_PJ' => $individual ? 'a pessoa física' : (strlen($digits) === 14 ? 'a pessoa jurídica' : 'o(a) locador(a)'),
                '#NomeLocador' => $fields['#FORNECEDOR'], '#Dados_Locador' => $details,
                '#DOCUMENTO_LOCADOR' => ($individual ? 'CPF: ' : 'CNPJ: ').$fields['#CNPJ_FORNECEDOR'],
                '#Destinação' => $text($contract->instalacoes), '#Vigencia' => $fields['#VIGENCIA'],
                '#VLMensal' => str_replace('R$ ', '', Contract::moneyLabel($contract->vl_parcial)), '#ExtMensal' => $text($contract->ext_parcial),
                '#VLTotal' => $fields['#VL_CONTRATO'], '#ExtTotal' => $fields['#EXTENSO'], '#DataContrato' => $fields['#DATA_CONTRATO'],
            ];
        }

        return ['version' => (int) $contract->version, 'type' => $contract->tipo, 'model' => self::modelFor($contract->tipo), 'role' => $actor->user_type,
            'settingsVersion' => (int) $settings->version, 'signer' => $this->signatories->fingerprint($signer),
            'contractTotal' => $contract->valor_total, 'fields' => $fields, 'products' => $products, 'budgets' => $budgets];
    }

    private function fingerprint(array $snapshot): string
    {
        return hash_hmac('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function leaseAppointment(Contract $contract, array $signer): array
    {
        // Do not attach a decree from another person with the same name or another Fund.
        $cpf = Normalizer::document($signer['CPF_Gestor'] ?? null);
        if (strlen($cpf) !== 11 || ! filled($signer['Nome_Gestor'] ?? null)) {
            return [];
        }
        $matches = DB::table('tb_Gestor')->whereNull('deleted_at')
            ->where('fund_name_key', Normalizer::name($contract->fundo_nome_snapshot))
            ->orderBy('id')->lockForUpdate()->get(['name', 'raw_payload'])
            ->filter(function ($row) use ($cpf, $signer) {
                $raw = json_decode($row->raw_payload, true, 512, JSON_THROW_ON_ERROR);

                return Normalizer::name($row->name) === Normalizer::name($signer['Nome_Gestor'])
                    && Normalizer::document($raw['CPF_Gestor'] ?? null) === $cpf;
            });
        if ($matches->count() !== 1) {
            return [];
        }
        $raw = json_decode($matches->first()->raw_payload, true, 512, JSON_THROW_ON_ERROR);

        return array_intersect_key($raw, array_flip(['Decreto', 'Matricula']));
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
        $lease = str_contains($html, 'class="lease-footer"');
        $separator = $lease ? ' - Página ' : ' & Página ';
        $pdf->getCanvas()->page_script(static function ($page, $pages, $canvas, $metrics) use ($reference, $separator, $lease) {
            $font = $metrics->getFont('DejaVu Sans');
            $bold = $metrics->getFont('DejaVu Sans', 'bold');
            $parts = [];
            foreach (preg_split('/(Contrato|Processo)/u', $reference, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $text) {
                $parts[] = [$text, $lease && in_array($text, ['Contrato', 'Processo'], true) ? $bold : $font];
            }
            array_push($parts, [$separator, $font], [(string) $page, $lease ? $bold : $font], [' de ', $font], [(string) $pages, $lease ? $bold : $font]);
            $width = 0;
            foreach ($parts as [$text, $face]) {
                $width += $metrics->getTextWidth($text, $face, 8);
            }
            $size = min(8, 8 * 514.28 / max(1, $width));
            $position = 565.28 - $width * $size / 8;
            foreach ($parts as [$text, $face]) {
                $canvas->text($position, 88, $text, $face, $size, [0.15, 0.15, 0.15]);
                $position += $metrics->getTextWidth($text, $face, $size);
            }
        });

        return $pdf->output();
    }
}
