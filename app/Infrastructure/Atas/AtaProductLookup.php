<?php

namespace App\Infrastructure\Atas;

use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AtaProductLookup
{
    public function __construct(private LockActor $actors, private SelectedFund $funds) {}

    public function listing(User $expected, int $ataId, array $input): array
    {
        $ata = $this->authorizedAta($expected, $ataId);
        $data = Validator::make($input, ['page' => 'sometimes|integer|min:1|max:1000000'])->validate();
        $process = trim($ata->PROCESSO);
        if ($process === '') {
            throw ValidationException::withMessages(['item' => 'A ata precisa ter um Processo para consultar os produtos.']);
        }
        $rows = DB::table('tb_Produtos')->whereRaw('TRIM(Processo) = ?', [$process])
            ->orderBy('item')->orderBy('id')->paginate(50,
                ['id', 'item', 'Cod_Prod', 'Discriminacao', 'Marca', 'Unidade', 'QTD', 'VL_Unitario'], 'page', $data['page'] ?? 1);
        $current = $this->authorizedAta($expected, $ataId);
        abort_if($current->PROCESSO !== $ata->PROCESSO || $current->fund_id !== $ata->fund_id || $current->version !== $ata->version,
            409, 'A ata foi alterada. Consulte novamente pelo Nº ATA antes de buscar os produtos.');

        return ['process' => $process, 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage(),
            'products' => $rows->map(fn ($row) => ['id' => $row->id, 'item' => $row->item, 'code' => $row->Cod_Prod,
                'description' => $row->Discriminacao, 'brand' => $row->Marca, 'unit' => $row->Unidade,
                'remaining' => DecimalAmount::normalize($row->QTD), 'unit_price' => DecimalAmount::normalize($row->VL_Unitario)])->all()];
    }

    public function find(User $expected, int $ataId, array $input): array
    {
        $ata = $this->authorizedAta($expected, $ataId);
        $data = Validator::make($input, ['item' => 'required|string|max:100'])->validate();
        $process = trim($ata->PROCESSO);
        if ($process === '') {
            throw ValidationException::withMessages(['item' => 'A ata precisa ter um Processo para consultar os produtos.']);
        }
        // The process is always derived from the authorized persisted ata.
        $matches = DB::table('tb_Produtos')->whereRaw('TRIM(Processo) = ?', [$process])
            ->whereRaw('TRIM(item) = ?', [trim($data['item'])])->orderBy('id')->limit(2)
            ->get(['id', 'item', 'Cod_Prod', 'Marca', 'Unidade', 'QTD', 'VL_Unitario', 'Discriminacao']);
        $current = $this->authorizedAta($expected, $ataId);
        abort_if($current->PROCESSO !== $ata->PROCESSO || $current->fund_id !== $ata->fund_id || $current->version !== $ata->version,
            409, 'A ata foi alterada. Consulte novamente pelo Nº ATA antes de buscar o Item.');
        abort_if($matches->isEmpty(), 404, 'Nenhum produto encontrado para este Item no Processo da Ata.');
        abort_if($matches->count() !== 1, 409, 'Há mais de um produto para este Item e Processo. Revise o catálogo antes de continuar.');
        $row = $matches->first();
        $remaining = DecimalAmount::normalize($row->QTD);
        $price = DecimalAmount::normalize($row->VL_Unitario);
        if ($remaining === null || $price === null || bccomp($remaining, '0', 4) < 0 || bccomp($price, '0', 4) < 0) {
            throw ValidationException::withMessages(['item' => 'O produto tem saldo ou valor unitário inválido no cadastro.']);
        }

        return ['process' => $process, 'product' => ['id' => $row->id, 'item' => $row->item,
            'code' => $row->Cod_Prod, 'brand' => $row->Marca, 'unit' => $row->Unidade,
            'remaining' => $remaining, 'unit_price' => $price, 'description' => $row->Discriminacao]];
    }

    private function authorizedAta(User $expected, int $ataId): object
    {
        $actor = $this->actors->current($expected);
        $ata = DB::table('tb_ATA')->where('ID', $ataId)->whereNull('deleted_at')->first(['ID', 'PROCESSO', 'fund_id', 'version']);
        abort_unless($ata && ($actor->isAdmin() || $this->funds->allows($actor, (int) $ata->fund_id)), 404);

        return $ata;
    }
}
