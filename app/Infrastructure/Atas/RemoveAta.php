<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class RemoveAta
{
    public function __construct(private LockActor $actors, private SelectedFund $funds, private AtaWritePolicy $policy) {}

    public function remove(User $expected, int $id, array $input, ?int $sessionFundId): array
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
            abort_if((int) $data['version'] !== (int) $ata->version, 409, 'A ata foi alterada. Consulte novamente antes de excluir.');
            $now = now();
            TransactionGuard::requireActive();
            $identity = new AtaAmendmentReader;
            $numberKey = $identity->key($ata->ATA);
            $legacyAmendments = DB::table('tb_ADT_ATA')->whereNull('deleted_at')
                ->where(fn ($query) => $query->where('ID_ATA', $id)->orWhere(fn ($legacy) => $legacy->whereNull('ID_ATA')->whereNull('source_path')))
                ->orderBy('ID')->lockForUpdate()
                ->get(['ID', 'ATA', 'Proc_ATA'])->filter(fn ($row) => $numberKey !== null
                    && $identity->key($row->ATA) === $numberKey && trim((string) $row->Proc_ATA) === trim($ata->PROCESSO));
            if ($legacyAmendments->isNotEmpty()) {
                $ambiguous = DB::table('tb_ATA')->where('ID', '<>', $id)->get(['ATA', 'PROCESSO'])
                    ->contains(fn ($row) => $identity->key($row->ATA) === $numberKey && trim($row->PROCESSO) === trim($ata->PROCESSO));
                abort_if($ambiguous, 409, 'Há atas com o mesmo número e processo. Revise os vínculos dos aditivos antes de excluir.');
            }
            $related = [];
            $amendments = DB::table('tb_ADT_ATA')->where('ID_ATA', $id)->orderBy('ID')->lockForUpdate()->pluck('ID')->all();
            $drafts = DB::table('ata_participant_drafts')->where('ata_id', $id)->pluck('id')->all();
            $queries = [
                'tb_PRODUTOS_ATA' => DB::table('tb_PRODUTOS_ATA')->where(function ($query) use ($id, $amendments) {
                    $query->where('ID_ATA', $id)->orWhere(fn ($query) => $query->whereNull('ID_ATA')->whereIn('amendment_id', $amendments));
                }),
                'tb_FUNDOS_ATA' => DB::table('tb_FUNDOS_ATA')->where('ID_ATA', $id),
                'tb_Participantes' => DB::table('tb_Participantes')->whereIn('draft_id', $drafts),
                'tb_ADT_ATA' => DB::table('tb_ADT_ATA')->whereIn('ID', array_unique([...$amendments, ...$legacyAmendments->pluck('ID')->all()])),
            ];
            foreach ($queries as $table => $query) {
                $primary = $table === 'tb_Participantes' ? 'id' : 'ID';
                $ids = $query->whereNull('deleted_at')->orderBy($primary)->lockForUpdate()->pluck($primary)->all();
                $related[$table] = $ids;
                $timestamps = ['deleted_at' => $now];
                if ($table !== 'tb_ADT_ATA') {
                    $timestamps['updated_at'] = $now;
                }
                DB::table($table)->whereIn($primary, $ids)->update($timestamps);
            }
            DB::table('tb_ATA')->where('ID', $id)->update(['deleted_at' => $now, 'updated_at' => $now, 'version' => $ata->version + 1]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_deleted', 'created_at' => $now,
                'metadata' => ['ata_id' => $id, 'before_version' => $ata->version, 'after_version' => $ata->version + 1,
                    'before' => array_diff_key((array) $ata, ['Doc_ATA' => true]), 'related_deleted_ids' => $related,
                    'deleted_at' => $now->toISOString()]]);

            return ['id' => $id, 'number' => $ata->ATA, 'version' => $ata->version + 1];
        }, 3);
    }
}
