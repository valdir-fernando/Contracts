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
    public function __construct(private LockActor $actors, private SelectedFund $funds, private AtaWritePolicy $policy, private AtaProductAvailability $availability) {}

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
                'item' => 'required|string|max:32',
                'code' => 'nullable|string|max:100', 'unit' => 'required|string|max:100',
                'description' => 'required|string|max:5000', 'brand' => 'nullable|string|max:255',
                'quantity' => 'required|string|max:40', 'unit_price' => 'required|string|max:40',
                'special_quantity' => 'sometimes|nullable|string|max:40',
                'places' => 'required|integer|min:2|max:4',
            ], [], ['item' => 'Item', 'code' => 'Código', 'unit' => 'UND',
                'description' => 'Descrição', 'quantity' => 'QTD', 'unit_price' => 'VL. Unitário'])->validate();
            abort_if((int) $data['version'] !== (int) $ata->version, 409, 'A ata foi alterada. Consulte novamente pelo Nº ATA antes de salvar o produto.');
            $quantity = $this->decimal($data['quantity'], 'quantity', positive: true);
            $price = $this->decimal($data['unit_price'], 'unit_price');
            $special = $data['special_quantity'] ?? null;
            if ($special !== null && $special !== '') {
                if (! $ata->QTD_ESPECIAL_NOME) {
                    throw ValidationException::withMessages(['special_quantity' => 'Configure o nome da QTD Especial nesta ata antes de informar seu valor.']);
                }
                $special = $this->decimal($special, 'special_quantity');
            } else {
                $special = null;
            }
            if (! $data['manual']) {
                $matches = DB::table('tb_Produtos')->whereRaw('TRIM(Processo) = ?', [trim($ata->PROCESSO)])
                    ->whereRaw('TRIM(item) = ?', [$data['item']])->orderBy('id')->lockForUpdate()
                    ->get(['id', 'Cod_Prod', 'Discriminacao', 'Marca', 'Unidade', 'QTD', 'VL_Unitario']);
                if ($matches->count() !== 1) {
                    throw ValidationException::withMessages(['item' => 'Consulte um Item único do Processo da Ata antes de salvar.']);
                }
                $source = $matches->first();
                $available = $this->availability->remaining($ata->PROCESSO, $data['item'], $source->QTD, lock: true);
                if (bccomp($quantity, $available, 4) > 0) {
                    throw ValidationException::withMessages(['quantity' => 'A Quantidade limite é '.$available]);
                }
                $price = $this->decimal($source->VL_Unitario, 'unit_price');
                $canonical = Validator::make(['code' => trim((string) $source->Cod_Prod), 'unit' => trim((string) $source->Unidade),
                    'description' => trim((string) $source->Discriminacao), 'brand' => $source->Marca], [
                        'code' => 'nullable|string|max:100', 'unit' => 'required|string|max:100',
                        'description' => 'required|string|max:5000', 'brand' => 'nullable|string|max:255',
                    ])->validate();
                $data = array_replace($data, $canonical);
            }
            $total = $this->decimal(bcmul($quantity, $price, (int) $data['places']), 'total');
            $entries = DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $ataId)
                ->whereRaw('TRIM(PROCESSO) = ?', [trim($ata->PROCESSO)])
                ->whereRaw('TRIM(ITEM) = ?', [$data['item']])->orderBy('ID')->lockForUpdate()->get();
            $originals = $entries->filter(fn ($row) => (int) $row->ADITIVO === 0 && $row->ID_ADITIVO === null);
            $active = $originals->filter(fn ($row) => $row->deleted_at === null);
            foreach ($active as $existing) {
                if ($existing->QTD_ESPECIAL !== null) {
                    $special = $this->decimal(bcadd($special ?? '0', $this->decimal($existing->QTD_ESPECIAL, 'special_quantity'), 4), 'special_quantity');
                }
                if (strcasecmp(trim((string) $existing->UND), $data['unit']) !== 0) {
                    throw ValidationException::withMessages(['unit' => 'A unidade é diferente da cadastrada para este Item. Use a mesma unidade para somar os lançamentos.']);
                }
                if (bccomp($this->decimal($existing->VL_UNIT, 'unit_price'), $price, 4) !== 0) {
                    throw ValidationException::withMessages(['unit_price' => 'O valor unitário é diferente do cadastrado para este Item. Use o mesmo valor para somar os lançamentos.']);
                }
                $quantity = $this->decimal(bcadd($quantity, $this->decimal($existing->QTD, 'quantity', positive: true), 4), 'quantity', positive: true);
                $total = $this->decimal(bcadd($total, $this->decimal($existing->VL_TOTAL, 'total'), 4), 'total');
            }
            // A single active original accumulates the entries. Keep historical fields intact.
            $previous = $active->first() ?? $originals->first(fn ($row) => $row->FICHA === null && $row->deleted_at !== null);
            abort_if(DB::table('tb_ATA')->where('ATA', $ata->ATA)->where('PROCESSO', $ata->PROCESSO)
                ->where('ID', '<>', $ataId)->exists(), 409, 'A identificação da Ata e Processo não é única. Revise o cadastro antes de salvar o produto.');
            $now = now();
            $record = ['ID_ATA' => $ataId, 'ATA' => $ata->ATA, 'PROCESSO' => $ata->PROCESSO,
                'LICITACAO' => $ata->LICITACAO, 'N_LICIT' => $ata->N_LICIT, 'ADITIVO' => 0,
                'ID_ADITIVO' => null, 'DATA_ADT' => null, 'PROC_ADT' => null,
                'ITEM' => $data['item'], 'COD_PROD' => $data['code'] ?? null, 'FICHA' => null,
                'DISCRIMINACAO' => $data['description'], 'MARCA' => $data['brand'] ?? null, 'UND' => $data['unit'],
                'QTD' => $quantity, 'QTD_ESPECIAL' => $special, 'VL_UNIT' => $price, 'VL_TOTAL' => $total, 'deleted_at' => null,
                'source_row' => json_encode(['origin' => 'native', 'manual' => (bool) $data['manual'], 'places' => (int) $data['places']], JSON_THROW_ON_ERROR),
                'updated_at' => $now];
            TransactionGuard::requireActive();
            $consolidated = [];
            if ($active->isNotEmpty()) {
                $record = ['QTD' => $quantity, 'QTD_ESPECIAL' => $special, 'VL_TOTAL' => $total, 'updated_at' => $now];
                $consolidated = $active->where('ID', '<>', $previous->ID)->values()->all();
                if ($consolidated !== []) {
                    DB::table('tb_PRODUTOS_ATA')->whereIn('ID', array_column($consolidated, 'ID'))
                        ->update(['deleted_at' => $now, 'updated_at' => $now]);
                }
            }
            if ($previous) {
                $id = $previous->ID;
                DB::table('tb_PRODUTOS_ATA')->where('ID', $id)->update($record);
            } else {
                $id = DB::table('tb_PRODUTOS_ATA')->insertGetId($record + ['created_at' => $now], 'ID');
            }
            DB::table('tb_ATA')->where('ID', $ataId)->update(['version' => $ata->version + 1, 'updated_at' => $now]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_product_saved', 'metadata' => [
                'ata_id' => $ataId, 'product_id' => $id, 'before_version' => $ata->version, 'after_version' => $ata->version + 1,
                'manual' => (bool) $data['manual'],
                'before' => $previous ? (array) $previous : null,
                'after' => (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $id)->first(),
                'consolidated_before' => array_map(fn ($row) => (array) $row, $consolidated),
                'consolidated_after' => $consolidated === [] ? [] : DB::table('tb_PRODUTOS_ATA')
                    ->whereIn('ID', array_column($consolidated, 'ID'))->orderBy('ID')->get()->map(fn ($row) => (array) $row)->all(),
            ], 'created_at' => $now]);

            return ['id' => $id, 'number' => $ata->ATA, 'version' => $ata->version + 1, 'ficha' => null,
                'ficha_total' => null, 'total' => $total];
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
