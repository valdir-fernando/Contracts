<?php

namespace App\Infrastructure\Atas;

use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Persistence\TransactionGuard;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveAtaProduct
{
    public function __construct(private LockActor $actors, private SelectedFund $funds, private AtaWritePolicy $policy) {}

    public function save(User $expected, int $ataId, array $input, ?int $sessionFundId): array
    {
        return DB::transaction(function () use ($expected, $ataId, $input, $sessionFundId) {
            $actor = $this->actors->lock($expected);
            abort_unless($sessionFundId !== null && $this->funds->allows($actor, $sessionFundId), 403);
            $ata = DB::table('tb_ATA')->where('ID', $ataId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($ata, 404);
            if ($ata->fund_id === null) {
                abort_unless($actor->isAdmin(), 403);
            } else {
                $this->policy->authorize($actor, (int) $ata->fund_id);
            }
            if (trim($ata->PROCESSO) === '') {
                throw ValidationException::withMessages(['item' => 'A Ata precisa ter um Processo antes de cadastrar produtos.']);
            }
            $input = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $input);
            $data = Validator::make($input, [
                'version' => 'required|integer|min:1', 'manual' => 'required|boolean',
                'ficha' => 'required|string|max:64', 'item' => 'required|string|max:32',
                'code' => 'required|string|max:100', 'unit' => 'required|string|max:100',
                'description' => 'required|string|max:5000', 'brand' => 'nullable|string|max:255',
                'quantity' => 'required|string|max:40', 'unit_price' => 'required|string|max:40',
                'places' => 'required|integer|min:2|max:4',
            ], [], ['ficha' => 'Ficha', 'item' => 'Item', 'code' => 'Código', 'unit' => 'UND',
                'description' => 'Descrição', 'quantity' => 'QTD', 'unit_price' => 'VL. Unitário'])->validate();
            abort_if((int) $data['version'] !== (int) $ata->version, 409, 'A ata foi alterada. Consulte novamente pelo Nº ATA antes de salvar o produto.');
            $quantity = $this->decimal($data['quantity'], 'quantity', positive: true);
            $price = $this->decimal($data['unit_price'], 'unit_price');
            if (! $data['manual']) {
                $matches = DB::table('tb_Produtos')->whereRaw('TRIM(Processo) = ?', [trim($ata->PROCESSO)])
                    ->whereRaw('TRIM(item) = ?', [$data['item']])->orderBy('id')->lockForUpdate()
                    ->get(['id', 'Cod_Prod', 'Discriminacao', 'Marca', 'Unidade', 'QTD', 'VL_Unitario']);
                if ($matches->count() !== 1) {
                    throw ValidationException::withMessages(['item' => 'Consulte um Item único do Processo da Ata antes de salvar.']);
                }
                $source = $matches->first();
                $available = $this->decimal($source->QTD, 'quantity');
                if (bccomp($quantity, $available, 4) > 0) {
                    throw ValidationException::withMessages(['quantity' => 'A Quantidade limite é '.$available]);
                }
                $price = $this->decimal($source->VL_Unitario, 'unit_price');
                $canonical = Validator::make(['code' => trim((string) $source->Cod_Prod), 'unit' => trim((string) $source->Unidade),
                    'description' => trim((string) $source->Discriminacao), 'brand' => $source->Marca], [
                        'code' => 'required|string|max:100', 'unit' => 'required|string|max:100',
                        'description' => 'required|string|max:5000', 'brand' => 'nullable|string|max:255',
                    ])->validate();
                $data = array_replace($data, $canonical);
            }
            $total = $this->decimal(bcmul($quantity, $price, (int) $data['places']), 'total');
            $previous = DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $ataId)->where('ADITIVO', 0)
                ->where('ITEM', $data['item'])->lockForUpdate()->first();
            if ($previous && ($previous->deleted_at === null || $previous->ID_ADITIVO !== null)) {
                throw ValidationException::withMessages(['item' => 'Este Item já está cadastrado nos produtos originais da Ata.']);
            }
            // The ata lock serializes writes even when the first ficha row does not exist yet.
            abort_if(DB::table('tb_ATA')->where('ATA', $ata->ATA)->where('PROCESSO', $ata->PROCESSO)
                ->where('ID', '<>', $ataId)->exists(), 409, 'A identificação da Ata e Processo não é única. Revise o cadastro antes de gravar a Ficha.');
            $fichaQuery = fn () => DB::table('tb_ValorFicha_ATA')->where('ATA', $ata->ATA)
                ->where('Processo', $ata->PROCESSO)->where('Ficha', $data['ficha']);
            $fichas = $fichaQuery()->lockForUpdate()->get();
            abort_if($fichas->count() > 1, 409, 'Há registros duplicados desta Ficha na Ata. Revise o cadastro antes de salvar.');
            $beforeFicha = $fichas->first();
            $previousFichaTotal = $beforeFicha ? $this->decimal((string) $beforeFicha->VALOR, 'ficha') : '0.0000';
            $fichaTotal = $this->decimal(bcadd($previousFichaTotal, $total, 4), 'ficha');
            $now = now();
            $record = ['ID_ATA' => $ataId, 'ATA' => $ata->ATA, 'PROCESSO' => $ata->PROCESSO,
                'LICITACAO' => $ata->LICITACAO, 'N_LICIT' => $ata->N_LICIT, 'ADITIVO' => 0,
                'ID_ADITIVO' => null, 'DATA_ADT' => null, 'PROC_ADT' => null,
                'ITEM' => $data['item'], 'COD_PROD' => $data['code'], 'FICHA' => $data['ficha'],
                'DISCRIMINACAO' => $data['description'], 'MARCA' => $data['brand'] ?? null, 'UND' => $data['unit'],
                'QTD' => $quantity, 'VL_UNIT' => $price, 'VL_TOTAL' => $total, 'deleted_at' => null,
                'source_row' => json_encode(['origin' => 'native', 'manual' => (bool) $data['manual'], 'places' => (int) $data['places']], JSON_THROW_ON_ERROR),
                'updated_at' => $now];
            TransactionGuard::requireActive();
            if ($previous) {
                $id = $previous->ID;
                DB::table('tb_PRODUTOS_ATA')->where('ID', $id)->update($record);
            } else {
                $id = DB::table('tb_PRODUTOS_ATA')->insertGetId($record + ['created_at' => $now], 'ID');
            }
            if ($beforeFicha) {
                $fichaQuery()->update(['VALOR' => $fichaTotal]);
            } else {
                DB::table('tb_ValorFicha_ATA')->insert(['ATA' => $ata->ATA, 'Processo' => $ata->PROCESSO, 'Ficha' => $data['ficha'], 'VALOR' => $fichaTotal]);
            }
            DB::table('tb_ATA')->where('ID', $ataId)->update(['version' => $ata->version + 1, 'updated_at' => $now]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_product_saved', 'metadata' => [
                'ata_id' => $ataId, 'product_id' => $id, 'before_version' => $ata->version, 'after_version' => $ata->version + 1,
                'before' => $previous ? (array) $previous : null, 'after' => $record,
                'ficha' => $data['ficha'], 'before_ficha' => $beforeFicha ? (array) $beforeFicha : null, 'after_ficha_total' => $fichaTotal,
            ], 'created_at' => $now]);

            return ['id' => $id, 'number' => $ata->ATA, 'version' => $ata->version + 1, 'ficha' => $data['ficha'],
                'ficha_total' => $fichaTotal, 'total' => $total];
        }, 3);
    }

    private function decimal(?string $value, string $field, bool $positive = false): string
    {
        $decimal = DecimalAmount::normalize($value);
        if ($decimal === null || bccomp($decimal, '0', 4) < ($positive ? 1 : 0)) {
            throw ValidationException::withMessages([$field => 'Informe um valor decimal válido'.($positive ? ' maior que zero.' : ' não negativo.')]);
        }

        return $decimal;
    }
}
