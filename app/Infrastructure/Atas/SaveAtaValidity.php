<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SaveAtaValidity
{
    public function __construct(private LockActor $actors, private SelectedFund $funds, private AtaWritePolicy $policy, private AtaValidityInput $input) {}

    public function save(User $expected, int $id, array $input, ?int $sessionFundId): array
    {
        return DB::transaction(function () use ($expected, $id, $input, $sessionFundId) {
            $actor = $this->actors->lock($expected);
            abort_unless($sessionFundId !== null && $this->funds->allows($actor, $sessionFundId), 403);
            $ata = DB::table('tb_ATA')->where('ID', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($ata, 404);
            if ($ata->fund_id === null) {
                abort_unless($actor->isAdmin(), 403);
            } else {
                $this->policy->authorize($actor, (int) $ata->fund_id);
            }
            $this->policy->ensureEditable($ata);
            $data = Validator::make($input, ['version' => 'required|integer|min:1'])->validate();
            abort_if((int) $data['version'] !== (int) $ata->version, 409, 'A ata foi alterada. Consulte novamente antes de salvar a vigência.');
            $previous = ['one_year_inpc' => $ata->VIGENCIA_ANO_INPC === null ? false : (bool) $ata->VIGENCIA_ANO_INPC,
                'start_date' => $ata->INICIO_ATA, 'end_date' => $ata->FINAL_ATA, 'validity_period' => $ata->PERIODO_ATA];
            $record = $this->input->validate(array_replace($previous, $input));
            $before = array_intersect_key((array) $ata, $record);
            $same = true;
            foreach ($record as $key => $value) {
                $same = $same && ($key === 'VIGENCIA_ANO_INPC'
                    ? $ata->{$key} !== null && (bool) $ata->{$key} === $value
                    : ($value === null ? $ata->{$key} === null : (string) $ata->{$key} === (string) $value));
            }
            $version = (int) $ata->version;
            if (! $same) {
                $now = now();
                TransactionGuard::requireActive();
                DB::table('tb_ATA')->where('ID', $id)->update($record + ['version' => ++$version, 'updated_at' => $now]);
                ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_validity_saved', 'created_at' => $now,
                    'metadata' => ['ata_id' => $id, 'before_version' => $ata->version, 'after_version' => $version, 'before' => $before, 'after' => $record]]);
            }

            return ['id' => $id, 'number' => $ata->ATA, 'version' => $version];
        }, 3);
    }
}
