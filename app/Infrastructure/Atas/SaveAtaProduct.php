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
                'places' => 'required|integer|min:2|max:4',
            ], [], ['item' => 'Item', 'code' => 'Código', 'unit' => 'UND',
                'description' => 'Descrição', 'quantity' => 'QTD', 'unit_price' => 'VL. Unitário'])->validate();
            abort_if((int) $data['version'] !== (int) $ata->version, 409, 'A ata foi alterada. Consulte novamente pelo Nº ATA antes de salvar o produto.');
            $quantity = $this->decimal($data['quantity'], 'quantity', positive: true);
            $price = $this->decimal($data['unit_price'], 'unit_price');
            $matches = DB::table('tb_Produtos')->whereRaw('TRIM(Processo) = ?', [trim($ata->PROCESSO)])
                ->whereRaw('TRIM(item) = ?', [$data['item']])->orderBy('id')->lockForUpdate()
                ->get(['id', 'Cod_Prod', 'Discriminacao', 'Marca', 'Unidade', 'QTD', 'VL_Unitario']);
            if ($matches->count() > 1 || (! $data['manual'] && $matches->count() !== 1)) {
                throw ValidationException::withMessages(['item' => 'Consulte um Item único do Processo da Ata antes de salvar.']);
            }
            if ($matches->count() === 1) {
                $source = $matches->first();
                $available = $this->availability->remaining($ata->PROCESSO, $data['item'], $source->QTD, lock: true);
                if (bccomp($available, '0', 4) === 0) {
                    throw ValidationException::withMessages(['quantity' => 'Não é possível fazer novo lançamento: este produto já atingiu o limite para esta ATA.']);
                }
                if (bccomp($quantity, $available, 4) > 0) {
                    throw ValidationException::withMessages(['quantity' => 'A Quantidade limite é '.$available]);
                }
            }
            if (! $data['manual']) {
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
            if ($originals->contains(fn ($row) => $row->deleted_at === null)) {
                throw ValidationException::withMessages(['item' => 'Este Item já está cadastrado nos produtos originais desta Ata e Processo.']);
            }
            // Historical ficha entries remain intact. Reuse only deleted originals without ficha.
            $previous = $originals->first(fn ($row) => $row->FICHA === null && $row->deleted_at !== null);
            abort_if(DB::table('tb_ATA')->where('ATA', $ata->ATA)->where('PROCESSO', $ata->PROCESSO)
                ->where('ID', '<>', $ataId)->exists(), 409, 'A identificação da Ata e Processo não é única. Revise o cadastro antes de salvar o produto.');
            $now = now();
            $record = ['ID_ATA' => $ataId, 'ATA' => $ata->ATA, 'PROCESSO' => $ata->PROCESSO,
                'LICITACAO' => $ata->LICITACAO, 'N_LICIT' => $ata->N_LICIT, 'ADITIVO' => 0,
                'ID_ADITIVO' => null, 'DATA_ADT' => null, 'PROC_ADT' => null,
                'ITEM' => $data['item'], 'COD_PROD' => $data['code'] ?? null, 'FICHA' => null,
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
            DB::table('tb_ATA')->where('ID', $ataId)->update(['version' => $ata->version + 1, 'updated_at' => $now]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_product_saved', 'metadata' => [
                'ata_id' => $ataId, 'product_id' => $id, 'before_version' => $ata->version, 'after_version' => $ata->version + 1,
                'before' => $previous ? (array) $previous : null, 'after' => $record,
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
