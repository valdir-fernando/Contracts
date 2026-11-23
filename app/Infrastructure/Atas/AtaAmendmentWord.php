<?php

namespace App\Infrastructure\Atas;

use App\Domain\Atas\AtaTerm;
use App\Infrastructure\Documents\DocumentSettings;
use App\Infrastructure\Security\LockActor;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AtaAmendmentWord extends AtaExtractPdf
{
    public function __construct(LockActor $actors, AtaWritePolicy $policy, DocumentSettings $settings, AtaAmendmentTemplate $template)
    {
        parent::__construct($actors, $policy, $settings, $template);
    }

    /** @return array{bytes: string, number: string, supplier: string} */
    public function generateForAmendment(User $actor, int $ata, int $version, int $amendment, string $layout = 'continuous'): array
    {
        abort_unless(in_array($layout, ['continuous', 'parallel'], true), 422);

        return $this->generateDocumentResult($actor, $ata, $version, ['amendment_number' => $amendment, 'products_layout' => $layout]);
    }

    protected function auditAction(): string
    {
        return 'ata_amendment_word_generated';
    }

    protected function documentName(): string
    {
        return 'o aditivo';
    }

    protected function additionalColumns(): array
    {
        return ['CNPJ_FUNDO', 'END_FUNDO', 'END_FORNECEDOR', 'SOCIO', 'CPF_SOCIO', 'RG_SOCIO', 'RG_FORNECEDOR', 'QTD_ESPECIAL_NOME', 'validity_basis'];
    }

    protected function relatedSource(object $ata, array $presentation): array
    {
        $identity = new AtaAmendmentReader;
        $key = $identity->key($ata->ATA);
        $process = trim($ata->PROCESSO ?? '');
        if ($key === null || $process === '' || DB::table('tb_ATA')->where('ID', '!=', $ata->ID)
            ->whereRaw('TRIM(PROCESSO) = ?', [$process])->pluck('ATA')->contains(fn ($number) => $identity->key($number) === $key)) {
            return [];
        }

        $number = $presentation['amendment_number'];
        $amendments = DB::table('tb_ADT_ATA')->whereRaw('TRIM(Proc_ATA) = ?', [$process])
            ->where('ADT', $number)->whereNull('deleted_at')->orderBy('ID')->lockForUpdate()
            ->get(['ID', 'ATA', 'ADT', 'Data_ADT', 'Proc_ADT', 'Tipo_ADT', 'INICIO', 'FINAL', 'Período', 'VANTAJOSIDADE', 'balance_products'])
            ->filter(fn ($row) => $identity->key($row->ATA) === $key);
        if ($amendments->count() !== 1) {
            return [];
        }

        $amendment = $amendments->first();
        if ($amendment->Tipo_ADT === SaveAtaAmendment::EXTENSION) {
            $excluded = DB::table('ata_amendment_excluded_products')->where('ata_id', $ata->ID)->where('amendment_id', $amendment->ID)
                ->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->pluck('product_id')->map(fn ($id) => (int) $id)->all();
            $balances = json_decode($amendment->balance_products ?? '[]', true, flags: JSON_THROW_ON_ERROR);

            return ['amendment' => (array) $amendment, 'balances' => array_values(array_filter($balances,
                fn ($product) => ! in_array((int) $product['id'], $excluded, true) && bccomp($product['balance_quantity'], '0', 4) > 0)),
                'original_start' => $ata->INICIO_ATA, 'previous_end' => $this->previousEnd($ata, $identity, $key, $process, $number),
                'signature_date' => self::longDate($amendment->Data_ADT)];
        }

        $products = DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $ata->ID)->where('ADITIVO', $number)
            ->whereNull('ID_ADITIVO')->whereNull('deleted_at')->orderBy('ID')->lockForUpdate()
            ->get(['ID', 'ITEM', 'COD_PROD', 'DISCRIMINACAO', 'UND', 'MARCA', 'QTD', 'QTD_ESPECIAL', 'VL_UNIT', 'VL_TOTAL'])
            ->map(fn ($row) => (array) $row)->all();

        return ['amendment' => (array) $amendment, 'products' => $products,
            'signature_date' => self::longDate($amendment->Data_ADT)];
    }

    private function previousEnd(object $ata, AtaAmendmentReader $identity, string $key, string $process, int $number): ?string
    {
        $basis = json_decode($ata->validity_basis ?? '[]', true);
        $originalEnd = ($basis['start'] ?? null) === $ata->INICIO_ATA ? ($basis['original_end'] ?? null) : null;
        if ($originalEnd === null && $ata->INICIO_ATA && $ata->PERIODO_ATA) {
            try {
                $originalEnd = AtaTerm::end(new DateTimeImmutable($ata->INICIO_ATA), $ata->PERIODO_ATA)->format('Y-m-d');
            } catch (InvalidArgumentException) {
                // Older ATAs can lack sufficient data to reconstruct the original expiry.
            }
        }
        $registered = DB::table('tb_ADT_ATA')->whereRaw('TRIM(Proc_ATA) = ?', [$process])->where('ADT', '<', $number)
            ->whereNull('deleted_at')->orderBy('ID')->lockForUpdate()->get(['ATA', 'FINAL'])
            ->filter(fn ($row) => $identity->key($row->ATA) === $key)->max('FINAL');
        $imported = DB::table('tb_ADITIVOS_ATA')->where('ID_ATA', $ata->ID)->where('NUMERO', '<', $number)
            ->whereNull('deleted_at')->orderBy('ID')->lockForUpdate()->pluck('FINAL_ADITIVO')->max();

        return max($originalEnd, $registered, $imported);
    }

    protected function fields(object $ata, User $actor, object $settings): array
    {
        $fields = parent::fields($ata, $actor, $settings);
        $individual = $fields['supplier_document_label'] === 'CPF';
        $cpf = $individual ? $fields['supplier_document'] : $this->document(Normalizer::document($ata->CPF_SOCIO), $actor);
        $rg = trim(($individual ? $ata->RG_FORNECEDOR : $ata->RG_SOCIO) ?? '');
        $rg = $rg === '' ? 'Não informado' : ($actor->isAdmin() ? $rg : Normalizer::mask($rg));

        return $fields + [
            'fund_document' => $this->document(Normalizer::document($ata->CNPJ_FUNDO), $actor),
            'fund_address' => $ata->END_FUNDO,
            'supplier_address' => $ata->END_FORNECEDOR,
            'representative' => $individual ? $fields['supplier'] : $ata->SOCIO,
            'representative_document' => $cpf,
            'representative_identification' => 'RG: '.$rg.', CPF: '.$cpf,
            'special_quantity' => trim($ata->QTD_ESPECIAL_NOME ?? ''),
        ];
    }

    protected function render(string $html): string
    {
        return $html;
    }
}
