<?php

namespace App\Infrastructure\Atas;

use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            'ID', 'ATA', 'version', 'fund_id', 'DATA_ATA', 'PROCESSO', 'LICITACAO', 'N_LICIT',
            'CNPJ_FUNDO', 'FUNDO', 'END_FUNDO', 'GESTOR', 'CPF_GESTOR', 'RG_GESTOR', 'DECRETO_GESTOR', 'MATRICULA_GESTOR',
            'FORNECEDOR', 'CNPJ_FORNECEDOR', 'END_FORNECEDOR', 'SOCIO', 'CPF_SOCIO', 'RG_SOCIO', 'EMAIL', 'TELEFONE1', 'TELEFONE2',
        ]);
        abort_unless($ata, 404);
        $actor = $this->actors->current($expected);
        abort_unless($actor->isAdmin() || $this->funds->allows($actor, (int) $ata->fund_id), 404);
        $contractor = ['fundo_documento_snapshot' => $ata->CNPJ_FUNDO, 'end_fundo' => $ata->END_FUNDO,
            'nome_gestor' => $ata->GESTOR, 'decreto_gestor' => $ata->DECRETO_GESTOR, 'matricula_gestor' => $ata->MATRICULA_GESTOR,
            'cpf_gestor' => $actor->isAdmin() ? $ata->CPF_GESTOR : Normalizer::mask($ata->CPF_GESTOR),
            'rg_gestor' => $actor->isAdmin() ? $ata->RG_GESTOR : Normalizer::mask($ata->RG_GESTOR)];
        $supplierDocument = Normalizer::document($ata->CNPJ_FORNECEDOR);
        $supplier = ['fornecedor_nome_snapshot' => $ata->FORNECEDOR,
            'fornecedor_documento_snapshot' => $actor->isAdmin() || strlen($supplierDocument) === 14 ? $ata->CNPJ_FORNECEDOR : Normalizer::mask($ata->CNPJ_FORNECEDOR),
            'end_fornec' => $ata->END_FORNECEDOR, 'socio' => $ata->SOCIO,
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
        $products = DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $ata->ID)->whereNull('deleted_at')->orderBy('ID')
            ->get(['ITEM', 'DISCRIMINACAO', 'MARCA', 'UND', 'QTD', 'VL_UNIT', 'VL_TOTAL'])->map(function ($row) use (&$total) {
                $amount = DecimalAmount::normalize((string) $row->VL_TOTAL);
                $total = bcadd($total, $amount, 4);

                return ['item' => $row->ITEM, 'description' => $row->DISCRIMINACAO, 'brand' => $row->MARCA, 'unit' => $row->UND,
                    'quantity' => DecimalAmount::normalize((string) $row->QTD), 'unit_price' => DecimalAmount::normalize((string) $row->VL_UNIT), 'total' => $amount];
            })->all();

        $actor = $this->actors->current($expected);
        abort_unless($actor->isAdmin() || $this->funds->allows($actor, (int) $ata->fund_id), 404);

        return ['id' => $ata->ID, 'number' => $ata->ATA, 'version' => $ata->version,
            'managing_fund_id' => $ata->fund_id === null ? 'saved-'.$ata->ID : (string) $ata->fund_id, 'managing_fund_name' => $ata->FUNDO,
            'process' => $ata->PROCESSO, 'date' => $ata->DATA_ATA, 'bidding' => $ata->LICITACAO, 'bidding_number' => $ata->N_LICIT,
            'contractor' => $contractor, 'supplier' => $supplier, 'participants' => $participants, 'products' => $products, 'total' => $total];
    }
}
