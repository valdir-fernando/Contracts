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

final class RemoveAtaProduct
{
    public function __construct(private LockActor $actors, private SelectedFund $funds, private AtaWritePolicy $policy) {}

    public function remove(User $expected, int $ataId, array $input, ?int $sessionFundId): array
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
            if (array_key_exists('product_ids', $input)) {
                return $this->removeSelected($actor, $ata, $input);
            }
            $data = Validator::make($input, ['version' => 'required|integer|min:1',
                'item' => 'required|string|max:100', 'quantity' => 'required|string|max:40'])->validate();
            abort_if((int) $data['version'] !== (int) $ata->version, 409, 'A ata foi alterada. Consulte novamente pelo Nº ATA antes de excluir.');
            $quantity = DecimalAmount::normalize($data['quantity']);
            if ($quantity === null || bccomp($quantity, '0', 4) <= 0) {
                throw ValidationException::withMessages(['quantity' => 'Informe uma quantidade maior que zero para excluir.']);
            }
            $matches = DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $ataId)
                ->whereRaw('TRIM(PROCESSO) = ?', [trim($ata->PROCESSO)])
                ->whereRaw('TRIM(ITEM) = ?', [trim($data['item'])])
                ->where('ADITIVO', 0)->whereNull('ID_ADITIVO')->whereNull('deleted_at')->orderBy('ID')->lockForUpdate()->get();
            if ($matches->isEmpty()) {
                throw ValidationException::withMessages(['item' => 'Este Item não está cadastrado nos produtos originais desta ata e processo.']);
            }
            if ($matches->count() !== 1) {
                throw ValidationException::withMessages(['item' => 'Há mais de um registro deste Item. Peça ao administrador para conferir o cadastro antes de excluir.']);
            }
            $product = $matches->first();
            $registered = DecimalAmount::normalize($product->QTD);
            $total = DecimalAmount::normalize($product->VL_TOTAL);
            if ($registered === null || bccomp($registered, '0', 4) <= 0 || $total === null || bccomp($total, '0', 4) < 0) {
                throw ValidationException::withMessages(['quantity' => 'O item tem quantidade ou valor inválido no cadastro. Peça ao administrador para conferir.']);
            }
            if (bccomp($quantity, $registered, 4) > 0) {
                [$integer, $fraction] = explode('.', $registered);
                $formatted = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer).','.$fraction;
                throw ValidationException::withMessages(['quantity' => 'A quantidade máxima que pode ser excluída deste Item é '.$formatted.'.']);
            }
            $remaining = bcsub($registered, $quantity, 4);
            $deleted = bccomp($remaining, '0', 4) === 0;
            $now = now();
            $record = $deleted ? ['deleted_at' => $now, 'updated_at' => $now]
                : ['QTD' => $remaining, 'VL_TOTAL' => bcdiv(bcmul($total, $remaining, 8), $registered, 4), 'updated_at' => $now];
            TransactionGuard::requireActive();
            DB::table('tb_PRODUTOS_ATA')->where('ID', $product->ID)->update($record);
            DB::table('tb_ATA')->where('ID', $ataId)->update(['version' => $ata->version + 1, 'updated_at' => $now]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_product_removed', 'created_at' => $now,
                'metadata' => ['ata_id' => $ataId, 'product_id' => $product->ID, 'quantity_removed' => $quantity,
                    'before_version' => $ata->version, 'after_version' => $ata->version + 1,
                    'before' => (array) $product, 'after' => (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $product->ID)->first()]]);

            return ['id' => $product->ID, 'number' => $ata->ATA, 'version' => $ata->version + 1,
                'deleted' => $deleted, 'quantity' => $remaining, 'total' => $deleted ? '0.0000' : $record['VL_TOTAL']];
        }, 3);
    }

    private function removeSelected(User $actor, object $ata, array $input): array
    {
        $data = Validator::make($input, ['version' => 'required|integer|min:1',
            'product_ids' => 'required|array|min:1|max:1000', 'product_ids.*' => 'required|integer|min:1|distinct'])->validate();
        abort_if((int) $data['version'] !== (int) $ata->version, 409, 'A ata foi alterada. Consulte novamente pelo Nº ATA antes de excluir.');
        $products = DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $ata->ID)
            ->whereRaw('TRIM(PROCESSO) = ?', [trim($ata->PROCESSO)])
            ->where('ADITIVO', 0)->whereNull('ID_ADITIVO')->whereNull('deleted_at')
            ->whereIn('ID', $data['product_ids'])->orderBy('ID')->lockForUpdate()->get();
        if ($products->count() !== count($data['product_ids'])) {
            throw ValidationException::withMessages(['product_ids' => 'Um ou mais itens selecionados não estão disponíveis nesta ata e processo. Consulte novamente a ata antes de excluir.']);
        }
        $now = now();
        TransactionGuard::requireActive();
        DB::table('tb_PRODUTOS_ATA')->whereIn('ID', $products->pluck('ID'))->update(['deleted_at' => $now, 'updated_at' => $now]);
        DB::table('tb_ATA')->where('ID', $ata->ID)->update(['version' => $ata->version + 1, 'updated_at' => $now]);
        ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_products_removed', 'created_at' => $now,
            'metadata' => ['ata_id' => $ata->ID, 'before_version' => $ata->version, 'after_version' => $ata->version + 1,
                'before' => $products->map(fn ($row) => (array) $row)->all(),
                'after' => DB::table('tb_PRODUTOS_ATA')->whereIn('ID', $products->pluck('ID'))->orderBy('ID')->get()->map(fn ($row) => (array) $row)->all()]]);

        return ['id' => $ata->ID, 'number' => $ata->ATA, 'version' => $ata->version + 1, 'deleted' => true, 'removed_count' => $products->count()];
    }
}
