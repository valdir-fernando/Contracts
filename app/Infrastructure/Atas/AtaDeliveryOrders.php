<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AtaDeliveryOrders
{
    public function __construct(private LockActor $actors, private SelectedFund $funds, private AtaWritePolicy $policy) {}

    public function store(User $expected, int $ataId, ?int $fundId, int $version, string $requestId): array
    {
        return DB::transaction(function () use ($expected, $ataId, $fundId, $version, $requestId) {
            $actor = $this->actors->lock($expected);
            $ata = $this->authorizedAta($actor, $ataId, $fundId);
            $orders = DB::table('ata_delivery_orders');
            $existing = (clone $orders)->where('ata_id', $ataId)->where('request_id', $requestId)->lockForUpdate()->first(['number', 'deleted_at']);
            if ($existing !== null) {
                abort_if($existing->deleted_at !== null, 409, 'Este pedido foi excluído. Use Novo para cadastrar outro.');

                return ['number' => $existing->number];
            }

            abort_unless((int) $ata->version === $version, 409, 'A ata foi alterada. Consulte-a novamente antes de salvar o pedido.');
            TransactionGuard::requireActive();
            $now = now();
            $year = $now->year;
            $counters = DB::table('ata_delivery_order_sequences');
            $counters->insertOrIgnore(['year' => $year, 'last_number' => 0]);
            $counter = $counters->where('year', $year);
            $sequence = (int) (clone $counter)->lockForUpdate()->value('last_number') + 1;
            $number = sprintf('%03d/%02d', $sequence, $year % 100);
            $counter->update(['last_number' => $sequence]);
            $id = $orders->insertGetId(['ata_id' => $ataId, 'year' => $year, 'sequence' => $sequence,
                'number' => $number, 'request_id' => $requestId, 'created_at' => $now]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_delivery_order_created', 'created_at' => $now,
                'metadata' => ['ata_id' => $ataId, 'order_id' => $id, 'number' => $number]]);

            return ['number' => $number];
        }, 3);
    }

    public function lookup(User $expected, int $ataId, ?int $fundId, string $number): array
    {
        return DB::transaction(function () use ($expected, $ataId, $fundId, $number) {
            $this->authorizedAta($this->actors->lock($expected), $ataId, $fundId);
            $found = $this->activeOrder($ataId, $number)->value('number');
            abort_if($found === null, 404, 'Pedido não encontrado nesta ATA.');

            return ['number' => $found];
        }, 3);
    }

    public function remove(User $expected, int $ataId, ?int $fundId, int $version, string $number): void
    {
        DB::transaction(function () use ($expected, $ataId, $fundId, $version, $number) {
            $actor = $this->actors->lock($expected);
            $ata = $this->authorizedAta($actor, $ataId, $fundId);
            abort_unless((int) $ata->version === $version, 409, 'A ata foi alterada. Consulte-a novamente antes de excluir o pedido.');
            $order = $this->activeOrder($ataId, $number)->lockForUpdate()->first(['id']);
            abort_unless($order, 404, 'Pedido não encontrado nesta ATA.');

            TransactionGuard::requireActive();
            $now = now();
            DB::table('ata_delivery_orders')->where('id', $order->id)->update(['deleted_at' => $now]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_delivery_order_removed', 'created_at' => $now,
                'metadata' => ['ata_id' => $ataId, 'order_id' => $order->id, 'number' => $number]]);
        }, 3);
    }

    private function activeOrder(int $ataId, string $number): Builder
    {
        return DB::table('ata_delivery_orders')->where('ata_id', $ataId)->where('number', $number)->whereNull('deleted_at');
    }

    private function authorizedAta(User $actor, int $ataId, ?int $fundId): object
    {
        abort_unless($fundId !== null && $this->funds->allows($actor, $fundId), 403);
        $ata = DB::table('tb_ATA')->where('ID', $ataId)->whereNull('deleted_at')->lockForUpdate()->first(['fund_id', 'version']);
        abort_unless($ata, 404);
        if ($ata->fund_id === null) {
            abort_unless($actor->isAdmin(), 403);
        } else {
            $this->policy->authorize($actor, (int) $ata->fund_id);
        }

        return $ata;
    }
}
