<?php

namespace App\Infrastructure\Atas;

use App\Domain\Atas\AtaTerm;
use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class AtaEditorQuery
{
    public function __construct(private LockActor $actors, private SelectedFund $funds) {}

    public function find(User $expected, string $number): array
    {
        $actor = $this->actors->current($expected);
        $identity = new AtaAmendmentReader;
        $key = $identity->key($number);
        if ($key === null) {
            throw ValidationException::withMessages(['number' => 'Informe o número e o ano da ata, por exemplo 001/2026.']);
        }
        $query = DB::table('tb_ATA')->whereNull('deleted_at');
        if (! $actor->isAdmin()) {
            $query->whereIn('fund_id', $this->funds->options($actor)->pluck('id'));
        }
        $matches = $query->get(['ID', 'ATA'])->filter(fn ($row) => $identity->key($row->ATA) === $key);
        abort_if($matches->isEmpty(), 404, 'Nenhuma ata disponível para este número.');
        abort_if($matches->count() !== 1, 409, 'Mais de uma ata disponível para este número. Confira o cadastro antes de continuar.');
        $ata = DB::table('tb_ATA')->where('ID', $matches->first()->ID)->whereNull('deleted_at')->first([
            'ID', 'ATA', 'version', 'fund_id', 'DATA_ATA', 'DATA_ORCAMENTO', 'ADMITIR_ADESAO', 'QTD_ESPECIAL_NOME', 'PROCESSO', 'LICITACAO', 'N_LICIT',
            'INICIO_ATA', 'FINAL_ATA', 'PERIODO_ATA', 'VIGENCIA_ANO_INPC', 'DATA_PUBLIC_INPC', 'validity_basis',
            'CNPJ_FUNDO', 'FUNDO', 'END_FUNDO', 'GESTOR', 'CPF_GESTOR', 'RG_GESTOR', 'CARGO_GESTOR', 'DECRETO_GESTOR', 'MATRICULA_GESTOR',
            'FORNECEDOR', 'CNPJ_FORNECEDOR', 'END_FORNECEDOR', 'SOCIO', 'CPF_SOCIO', 'RG_SOCIO', 'EMAIL', 'TELEFONE1', 'TELEFONE2',
            'RG_FORNECEDOR', 'DOC_PROF', 'PIS_PASEP',
        ]);
        abort_unless($ata, 404);
        $actor = $this->actors->current($expected);
        abort_unless($actor->isAdmin() || $this->funds->allows($actor, (int) $ata->fund_id), 404);
        $contractor = ['fundo_documento_snapshot' => $ata->CNPJ_FUNDO, 'end_fundo' => $ata->END_FUNDO,
            'nome_gestor' => $ata->GESTOR, 'cargo_gestor' => $ata->CARGO_GESTOR, 'decreto_gestor' => $ata->DECRETO_GESTOR, 'matricula_gestor' => $ata->MATRICULA_GESTOR,
            'cpf_gestor' => $actor->isAdmin() ? $ata->CPF_GESTOR : Normalizer::mask($ata->CPF_GESTOR),
            'rg_gestor' => $actor->isAdmin() ? $ata->RG_GESTOR : Normalizer::mask($ata->RG_GESTOR)];
        $supplierDocument = Normalizer::document($ata->CNPJ_FORNECEDOR);
        $supplier = ['fornecedor_nome_snapshot' => $ata->FORNECEDOR,
            'fornecedor_documento_snapshot' => $actor->isAdmin() || strlen($supplierDocument) === 14 ? $ata->CNPJ_FORNECEDOR : Normalizer::mask($ata->CNPJ_FORNECEDOR),
            'end_fornec' => $ata->END_FORNECEDOR, 'socio' => $ata->SOCIO, 'doc_prof' => $ata->DOC_PROF,
            'rg_fornec' => $actor->isAdmin() ? $ata->RG_FORNECEDOR : Normalizer::mask($ata->RG_FORNECEDOR),
            'pis_pasep' => $actor->isAdmin() ? $ata->PIS_PASEP : Normalizer::mask($ata->PIS_PASEP),
            'cpf_socio' => $actor->isAdmin() ? $ata->CPF_SOCIO : Normalizer::mask($ata->CPF_SOCIO),
            'rg_socio' => $actor->isAdmin() ? $ata->RG_SOCIO : Normalizer::mask($ata->RG_SOCIO)];
        if ($actor->canAccessCatalog('supplier')) {
            $supplier += ['supplier_email' => $ata->EMAIL, 'supplier_mobile' => $ata->TELEFONE1, 'supplier_whatsapp' => $ata->TELEFONE2];
        }
        $participants = DB::table('tb_FUNDOS_ATA')->where('ID_ATA', $ata->ID)->whereNull('deleted_at')->orderBy('ID')
            ->get(['ID', 'fund_id', 'FUNDO', 'CNPJ_FUNDO', 'papel'])->map(fn ($row) => [
                'id' => $row->ID, 'fund_id' => $row->fund_id, 'ATA' => $ata->ATA, 'Processo_ATA' => $ata->PROCESSO,
                'CNPJ_Fundo' => Normalizer::document($row->CNPJ_FUNDO), 'FUNDO' => $row->FUNDO,
                'Tipo_Fundo' => $row->papel === 'gerenciador' ? 'Gerenciador' : 'Participante',
            ])->all();
        $total = '0.0000';
        $updatedTotal = '0.0000';
        $products = [];
        $rows = DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $ata->ID)
            ->whereNull('deleted_at')->orderBy('ID')
            ->get(['ID', 'ADITIVO', 'ID_ADITIVO', 'ITEM', 'COD_PROD', 'FICHA', 'DISCRIMINACAO', 'MARCA', 'UND', 'QTD', 'QTD_ESPECIAL', 'VL_UNIT', 'VL_TOTAL']);
        foreach ($rows as $row) {
            $amount = DecimalAmount::normalize((string) $row->VL_TOTAL);
            $updatedTotal = bcadd($updatedTotal, $amount ?? '0.0000', 4);
            if ($row->ID_ADITIVO !== null || (string) $row->ADITIVO !== '0') {
                continue;
            }
            $total = bcadd($total, $amount ?? '0.0000', 4);

            $products[] = ['id' => $row->ID, 'item' => $row->ITEM, 'code' => $row->COD_PROD, 'ficha' => $row->FICHA, 'description' => $row->DISCRIMINACAO, 'brand' => $row->MARCA, 'unit' => $row->UND,
                'quantity' => DecimalAmount::normalize((string) $row->QTD), 'special_quantity' => $row->QTD_ESPECIAL === null ? null : DecimalAmount::normalize((string) $row->QTD_ESPECIAL),
                'unit_price' => DecimalAmount::normalize((string) $row->VL_UNIT), 'total' => $amount];
        }

        [$amendments, $registeredIds] = $this->amendments($ata, $identity, $key);
        if ($registeredIds !== []) {
            $excluded = DB::table('ata_amendment_excluded_products')->where('ata_id', $ata->ID)->whereIn('amendment_id', $registeredIds)
                ->whereNull('deleted_at')->orderBy('id')->get(['amendment_id', 'product_id'])->groupBy('amendment_id');
            foreach ($registeredIds as $number => $id) {
                if ($excluded->has($id)) {
                    $amendments[$number]['excluded_product_ids'] = $excluded[$id]->pluck('product_id')->map(fn ($productId) => (int) $productId)->all();
                }
            }
        }
        $productFields = array_flip(['id', 'item', 'code', 'description', 'brand', 'unit', 'unit_price', 'quantity', 'special_quantity']);
        $amendmentProducts = array_map(fn ($product) => array_intersect_key($product, $productFields) + [
            'total' => $product['quantity'] === null || $product['unit_price'] === null
                ? null
                : bcmul($product['quantity'], $product['unit_price'], 4),
        ], $products);
        $amendmentBaseTotal = array_reduce($amendmentProducts,
            fn (string $sum, array $product) => bcadd($sum, $product['total'] ?? '0.0000', 4), '0.0000');
        $actor = $this->actors->current($expected);
        abort_unless($actor->isAdmin() || $this->funds->allows($actor, (int) $ata->fund_id), 404);

        $signedPair = $actor->isAdmin() ? DB::table('ata_signed_document_copies')->where('ata_id', $ata->ID)
            ->whereNull('deleted_at')->first(['removed_copy', 'redacted_sha256']) : null;
        $signedCopies = AtaSignedDocuments::availableCopies($signedPair);
        $amendmentDocuments = [];
        if ($actor->isAdmin() && $registeredIds !== []) {
            $pairs = DB::table('ata_amendment_signed_documents')->where('ata_id', $ata->ID)->whereIn('amendment_id', $registeredIds)
                ->whereNull('deleted_at')->get(['amendment_id', 'removed_copy', 'redacted_sha256'])->keyBy('amendment_id');
            foreach ($registeredIds as $number => $id) {
                $amendmentDocuments[$number] = ['id' => $id, 'copies' => AtaSignedDocuments::availableCopies($pairs->get($id))];
            }
        }
        $basis = json_decode($ata->validity_basis ?? '{}', true);
        $amendedEnd = $ata->FINAL_ATA !== null && ($basis['rule'] ?? null) === UpdateAtaValidity::RULE && ! empty($basis['amendments'])
            && $ata->FINAL_ATA === ($basis['end'] ?? null);
        $originalEnd = $amendedEnd
            ? (array_key_exists('original_end', $basis) ? $basis['original_end'] : $this->termEnd($basis['start'] ?? null, $basis['period'] ?? null))
            : $ata->FINAL_ATA;

        return ['id' => $ata->ID, 'number' => $ata->ATA, 'version' => $ata->version, 'ficha_totals' => [],
            'managing_fund_id' => $ata->fund_id === null ? 'saved-'.$ata->ID : (string) $ata->fund_id, 'managing_fund_name' => $ata->FUNDO,
            'process' => $ata->PROCESSO, 'date' => $ata->DATA_ATA, 'admitir_adesao' => $ata->ADMITIR_ADESAO, 'special_quantity_name' => $ata->QTD_ESPECIAL_NOME,
            'budget_date' => $ata->DATA_ORCAMENTO,
            'start_date' => $ata->INICIO_ATA, 'end_date' => $originalEnd,
            'amendment_maximum_end' => AtaTerm::maximumAmendmentEnd($ata->INICIO_ATA)?->format('Y-m-d'),
            'end_date_derived' => $amendedEnd, 'validity_period' => $ata->PERIODO_ATA,
            'publication_date' => $ata->DATA_PUBLIC_INPC,
            'one_year_inpc' => $ata->VIGENCIA_ANO_INPC === null ? null : (bool) $ata->VIGENCIA_ANO_INPC,
            'bidding' => $ata->LICITACAO, 'bidding_number' => $ata->N_LICIT,
            'has_signed_document_copies' => $signedCopies !== [], 'signed_document_copies' => $signedCopies,
            'can_create_redacted_copy' => $signedCopies === ['original'],
            'amendment_signed_documents' => $amendmentDocuments,
            'contractor' => $contractor, 'supplier' => $supplier, 'participants' => $participants, 'products' => $products, 'total' => $total,
            'amendment_numbers' => array_keys($amendments), 'amendments' => $amendments, 'amendment_base_products' => $amendmentProducts,
            'amendment_base_total' => $amendmentBaseTotal, 'amendment_updated_total' => $updatedTotal];
    }

    private function termEnd(mixed $start, mixed $period): ?string
    {
        if (! is_string($start) || ! is_string($period)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        if (! $date || $date->format('Y-m-d') !== $start) {
            return null;
        }

        try {
            return AtaTerm::end($date, $period)->format('Y-m-d');
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function amendments(object $ata, AtaAmendmentReader $identity, string $key): array
    {
        $amendments = [];
        $registeredIds = [];
        $imported = DB::table('tb_ADITIVOS_ATA')->where('ID_ATA', $ata->ID)->whereNull('deleted_at')
            ->where('NUMERO', '>', 0)->orderByDesc('ID')->get(['NUMERO', 'PROCESSO_ADITIVO', 'INICIO_ADITIVO', 'FINAL_ADITIVO']);
        foreach ($imported as $row) {
            $amendments[(int) $row->NUMERO] ??= ['process' => $row->PROCESSO_ADITIVO, 'start' => $row->INICIO_ADITIVO, 'end' => $row->FINAL_ADITIVO];
        }

        $process = trim($ata->PROCESSO);
        // Legacy amendments lack a fund or parent ID. Only match them against an unambiguous ATA identity.
        if ($process !== '' && ! DB::table('tb_ATA')->where('ID', '!=', $ata->ID)
            ->whereRaw('TRIM(PROCESSO) = ?', [$process])->pluck('ATA')->contains(fn ($number) => $identity->key($number) === $key)) {
            $registered = DB::table('tb_ADT_ATA')->whereNull('deleted_at')->where('ADT', '>', 0)
                ->whereRaw('TRIM(Proc_ATA) = ?', [$process])->orderBy('Data_ADT')->orderBy('ID')
                ->get(['ID', 'ATA', 'ADT', 'Proc_ATA', 'Data_ADT', 'Proc_ADT', 'Tipo_ADT', 'INICIO', 'FINAL', 'Período', 'VANTAJOSIDADE', 'balance_products']);
            foreach ($registered as $row) {
                if (trim($row->Proc_ATA) === $process && $identity->key($row->ATA) === $key) {
                    $registeredIds[(int) $row->ADT] = (int) $row->ID;
                    $amendments[(int) $row->ADT] = ['date' => $row->Data_ADT, 'process' => $row->Proc_ADT,
                        'type' => $row->Tipo_ADT, 'start' => $row->INICIO, 'end' => $row->FINAL, 'period' => $row->{'Período'},
                        ...($row->VANTAJOSIDADE !== null ? ['advantage' => $row->VANTAJOSIDADE] : []),
                        ...($row->balance_products !== null && $row->Tipo_ADT === SaveAtaAmendment::EXTENSION
                            ? ['balance_products' => json_decode($row->balance_products, true, flags: JSON_THROW_ON_ERROR)] : [])];
                }
            }
        }

        krsort($amendments, SORT_NUMERIC);

        return [$amendments, $registeredIds];
    }
}
