<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class RemoveAtaSpecialQuantity
{
    public function __construct(private LockActor $actors, private SelectedFund $funds, private AtaWritePolicy $policy) {}

    public function remove(User $expected, int $ataId, array $input, ?int $sessionFundId): array
    {
        return DB::transaction(function () use ($expected, $ataId, $input, $sessionFundId) {
            $actor = $this->actors->lock($expected);
            abort_unless($sessionFundId !== null && $this->funds->allows($actor, $sessionFundId), 403);
            $ata = DB::table('tb_ATA')->where('ID', $ataId)->whereNull('deleted_at')->lockForUpdate()
                ->first(['ID', 'ATA', 'fund_id', 'version', 'QTD_ESPECIAL_NOME']);
            abort_unless($ata, 404);
            if ($ata->fund_id === null) {
                abort_unless($actor->isAdmin(), 403);
            } else {
                $this->policy->authorize($actor, (int) $ata->fund_id);
            }
            $data = Validator::make($input, ['version' => 'required|integer|min:1'])->validate();
            abort_if((int) $data['version'] !== (int) $ata->version, 409, 'A ata foi alterada. Consulte novamente pelo Nº ATA antes de excluir a QTD Especial.');
            $products = DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $ataId)->whereNotNull('QTD_ESPECIAL')
                ->orderBy('ID')->lockForUpdate()->get(['ID', 'QTD_ESPECIAL']);
            $version = (int) $ata->version;
            if ($ata->QTD_ESPECIAL_NOME !== null || $products->isNotEmpty()) {
                $now = now();
                TransactionGuard::requireActive();
                DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $ataId)->whereNotNull('QTD_ESPECIAL')
                    ->update(['QTD_ESPECIAL' => null, 'updated_at' => $now]);
                DB::table('tb_ATA')->where('ID', $ataId)->update(['QTD_ESPECIAL_NOME' => null, 'version' => ++$version, 'updated_at' => $now]);
                ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_special_quantity_removed', 'created_at' => $now,
                    'metadata' => ['ata_id' => $ataId, 'before_version' => $ata->version, 'after_version' => $version,
                        'before' => ['QTD_ESPECIAL_NOME' => $ata->QTD_ESPECIAL_NOME, 'products' => $products->map(fn ($row) => (array) $row)->all()],
                        'after' => ['QTD_ESPECIAL_NOME' => null, 'products' => $products->map(fn ($row) => ['ID' => $row->ID, 'QTD_ESPECIAL' => null])->all()]]]);
            }

            return ['id' => $ataId, 'number' => $ata->ATA, 'version' => $version, 'special_quantity_name' => null];
        }, 3);
    }
}
