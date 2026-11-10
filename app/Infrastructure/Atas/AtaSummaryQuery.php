<?php

namespace App\Infrastructure\Atas;

use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Security\LockActor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Read-only public business fields from the same global catalog as the ata report. */
class AtaSummaryQuery
{
    public function __construct(private LockActor $actors) {}

    public function for(User $expected, int $id): array
    {
        $this->actors->current($expected);
        $ata = DB::table('tb_ATA')->where('ID', $id)->whereNull('deleted_at')->first([
            'ATA', 'DATA_ATA', 'PROCESSO', 'LICITACAO', 'N_LICIT', 'FORNECEDOR',
            'FUNDO', 'CNPJ_FUNDO', 'OBJETO DA ATA', 'PERIODO_ATA', 'INICIO_ATA', 'FINAL_ATA',
        ]);
        abort_unless($ata, 404);

        $identity = new AtaAmendmentReader;
        $amendment = DB::table('tb_ADT_ATA')->where('ADT', '>', 0)->whereNotNull('Proc_ATA')
            ->orderByDesc('Data_ADT')->orderByDesc('ID')->get(['ATA', 'Proc_ATA', 'ADT', 'Proc_ADT', 'FINAL'])
            ->first(fn ($row) => $identity->key($row->ATA) === $identity->key($ata->ATA)
                && trim($row->Proc_ATA) !== '' && trim($row->Proc_ATA) === trim($ata->PROCESSO));

        $funds = DB::table('tb_FUNDOS_ATA')->where('ID_ATA', $id)->whereNull('deleted_at')
            ->orderBy('FUNDO')->orderBy('ID')->get(['FUNDO', 'CNPJ_FUNDO', 'papel'])
            ->map(fn ($row) => ['name' => $row->FUNDO, 'cnpj' => $row->CNPJ_FUNDO,
                'manager' => $row->papel === 'gerenciador' || $row->CNPJ_FUNDO === $ata->CNPJ_FUNDO]);
        if (! $funds->contains('manager', true) && trim((string) $ata->FUNDO) !== '') {
            $funds->prepend(['name' => $ata->FUNDO, 'cnpj' => $ata->CNPJ_FUNDO, 'manager' => true]);
        }
        $funds = $funds->sortByDesc('manager')->values();

        $total = '0.0000';
        $products = DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $id)->whereNull('deleted_at')
            ->orderBy('ID')->get(['ITEM', 'DISCRIMINACAO', 'MARCA', 'UND', 'QTD', 'VL_UNIT', 'VL_TOTAL', 'ADITIVO'])
            ->map(function ($row) use (&$total) {
                $amount = DecimalAmount::normalize((string) $row->VL_TOTAL);
                $total = bcadd($total, $amount, 4);

                return ['item' => $row->ITEM, 'description' => $row->DISCRIMINACAO, 'brand' => $row->MARCA,
                    'unit' => $row->UND, 'quantity' => DecimalAmount::normalize((string) $row->QTD),
                    'unit_price' => DecimalAmount::normalize((string) $row->VL_UNIT), 'amount' => $amount,
                    'amendment' => $row->ADITIVO];
            })->sortBy('item', SORT_NATURAL)->values();

        return ['ata' => ['number' => $ata->ATA, 'date' => $ata->DATA_ATA, 'process' => $ata->PROCESSO,
            'bidding' => $ata->LICITACAO, 'bidding_number' => $ata->N_LICIT, 'supplier' => $ata->FORNECEDOR,
            'object' => $ata->{'OBJETO DA ATA'}, 'period' => $ata->PERIODO_ATA, 'starts' => $ata->INICIO_ATA,
            'ends' => $amendment?->FINAL ?? $ata->FINAL_ATA,
            'amendment' => $amendment ? ['number' => $amendment->ADT, 'process' => $amendment->Proc_ADT] : null],
            'funds' => $funds, 'products' => $products, 'total' => $total];
    }
}
