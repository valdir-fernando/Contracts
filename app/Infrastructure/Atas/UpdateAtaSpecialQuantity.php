<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class UpdateAtaSpecialQuantity
{
    public function __construct(private LockActor $actors, private SelectedFund $funds, private AtaWritePolicy $policy) {}

    public function update(User $expected, int $ataId, array $input, ?int $sessionFundId): array
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
            if (isset($input['special_quantity_name']) && is_string($input['special_quantity_name'])) {
                $input['special_quantity_name'] = trim($input['special_quantity_name']);
            }
            $data = Validator::make($input, ['version' => 'required|integer|min:1',
                'special_quantity_name' => 'present|nullable|string|max:60'], [], ['special_quantity_name' => 'QTD de ...'])->validate();
            abort_if((int) $data['version'] !== (int) $ata->version, 409, 'A ata foi alterada. Consulte novamente pelo Nº ATA antes de mudar a QTD Especial.');
            $name = $data['special_quantity_name'];
            $version = (int) $ata->version;
            // An empty confirmation must never clear an existing configuration.
            if ($name !== null && $name !== '' && $ata->QTD_ESPECIAL_NOME !== $name) {
                $now = now();
                TransactionGuard::requireActive();
                DB::table('tb_ATA')->where('ID', $ataId)->update(['QTD_ESPECIAL_NOME' => $name, 'version' => ++$version, 'updated_at' => $now]);
                ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_special_quantity_updated', 'created_at' => $now,
                    'metadata' => ['ata_id' => $ataId, 'before_version' => $ata->version, 'after_version' => $version,
                        'before' => ['QTD_ESPECIAL_NOME' => $ata->QTD_ESPECIAL_NOME], 'after' => ['QTD_ESPECIAL_NOME' => $name]]]);
            }

            return ['id' => $ataId, 'number' => $ata->ATA, 'version' => $version,
                'special_quantity_name' => $name === null || $name === '' ? $ata->QTD_ESPECIAL_NOME : $name];
        }, 3);
    }
}
