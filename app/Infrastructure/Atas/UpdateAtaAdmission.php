<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class UpdateAtaAdmission
{
    public function __construct(private LockActor $actors, private SelectedFund $funds, private AtaWritePolicy $policy) {}

    public function update(User $expected, int $ataId, array $input, ?int $sessionFundId): array
    {
        return DB::transaction(function () use ($expected, $ataId, $input, $sessionFundId) {
            $actor = $this->actors->lock($expected);
            abort_unless($sessionFundId !== null && $this->funds->allows($actor, $sessionFundId), 403);
            $ata = DB::table('tb_ATA')->where('ID', $ataId)->whereNull('deleted_at')->lockForUpdate()
                ->first(['ID', 'ATA', 'fund_id', 'version', 'ADMITIR_ADESAO', 'DATA_PUBLIC_INPC']);
            abort_unless($ata, 404);
            if ($ata->fund_id === null) {
                abort_unless($actor->isAdmin(), 403);
            } else {
                $this->policy->authorize($actor, (int) $ata->fund_id);
            }
            $this->policy->ensureEditable($ata);
            $data = Validator::make($input, ['version' => 'required|integer|min:1',
                'admitir_adesao' => 'present|nullable|in:SIM,NAO'], [], ['admitir_adesao' => 'Admitir Adesão a ATA'])->validate();
            abort_if((int) $data['version'] !== (int) $ata->version, 409, 'A ata foi alterada. Consulte novamente pelo Nº ATA antes de mudar a opção de adesão.');
            $value = $data['admitir_adesao'] ?: null;
            $version = (int) $ata->version;
            if ($ata->ADMITIR_ADESAO !== $value) {
                $now = now();
                TransactionGuard::requireActive();
                DB::table('tb_ATA')->where('ID', $ataId)->update(['ADMITIR_ADESAO' => $value, 'version' => ++$version, 'updated_at' => $now]);
                ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_admission_updated', 'created_at' => $now,
                    'metadata' => ['ata_id' => $ataId, 'before_version' => $ata->version, 'after_version' => $version,
                        'before' => ['ADMITIR_ADESAO' => $ata->ADMITIR_ADESAO], 'after' => ['ADMITIR_ADESAO' => $value]]]);
            }

            return ['id' => $ataId, 'number' => $ata->ATA, 'version' => $version, 'admitir_adesao' => $value];
        }, 3);
    }
}
