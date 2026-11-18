<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UpdateAta
{
    public function __construct(private LockActor $actors, private SelectedFund $funds, private AtaWritePolicy $policy, private AtaValidityInput $validity) {}

    public function update(User $expected, int $id, array $input, ?int $sessionFundId): array
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
            $data = Validator::make($input, [
                'version' => 'required|integer|min:1', 'Data_ATA' => 'sometimes|required|date_format:Y-m-d',
                'ata_process' => 'sometimes|required|string|max:64', 'bidding' => 'sometimes|required|string|max:100',
                'bidding_number' => 'sometimes|required|string|max:32', 'admitir_adesao' => 'sometimes|nullable|in:SIM,NAO',
            ])->validate();
            abort_if((int) $data['version'] !== (int) $ata->version, 409, 'A ata foi alterada. Consulte novamente pelo Nº ATA antes de salvar.');
            $record = [];
            foreach (['Data_ATA' => 'DATA_ATA', 'ata_process' => 'PROCESSO', 'bidding' => 'LICITACAO',
                'bidding_number' => 'N_LICIT', 'admitir_adesao' => 'ADMITIR_ADESAO'] as $field => $column) {
                if (array_key_exists($field, $data)) {
                    $record[$column] = $data[$field] === null ? null : trim($data[$field]);
                }
            }
            if (isset($record['PROCESSO']) && $record['PROCESSO'] !== $ata->PROCESSO) {
                $identity = new AtaAmendmentReader;
                $key = $identity->key($ata->ATA);
                $sameNumber = fn ($number) => $key !== null ? $identity->key($number) === $key : trim((string) $number) === trim($ata->ATA);
                $legacyAmendment = DB::table('tb_ADT_ATA')->whereRaw('TRIM(Proc_ATA) = ?', [trim($ata->PROCESSO)])
                    ->get(['ATA'])->contains(fn ($row) => $sameNumber($row->ATA));
                $importedAmendment = DB::table('tb_ADITIVOS_ATA')->where('ID_ATA', $id)->exists()
                    || DB::table('tb_ADITIVOS_ATA')->whereNull('ID_ATA')->whereRaw('TRIM(PROCESSO_ORIGEM) = ?', [trim($ata->PROCESSO)])
                        ->get(['ATA'])->contains(fn ($row) => $sameNumber($row->ATA));
                $amendmentProduct = DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $id)
                    ->where(fn ($query) => $query->where('ADITIVO', '>', 0)->orWhereNotNull('ID_ADITIVO'))->exists();
                if ($legacyAmendment || $importedAmendment || $amendmentProduct) {
                    throw ValidationException::withMessages(['ata_process' => 'O Processo só pode mudar se não houver aditivos vinculados. Esta ATA possui aditivos vinculados.']);
                }
                $duplicate = DB::table('tb_ATA')->where('ID', '<>', $id)->whereRaw('TRIM(PROCESSO) = ?', [$record['PROCESSO']])
                    ->get(['ATA'])->contains(fn ($row) => $sameNumber($row->ATA));
                if ($duplicate) {
                    throw ValidationException::withMessages(['ata_process' => 'Já existe outra ATA com esse número e Processo. Informe um Processo diferente.']);
                }
            }
            if (array_intersect(['one_year_inpc', 'start_date', 'end_date', 'validity_period'], array_keys($input)) !== []) {
                $record += $this->validity->validate(array_replace([
                    'one_year_inpc' => (bool) $ata->VIGENCIA_ANO_INPC, 'start_date' => $ata->INICIO_ATA,
                    'end_date' => $ata->FINAL_ATA, 'validity_period' => $ata->PERIODO_ATA,
                ], $input));
            }
            $before = array_intersect_key((array) $ata, $record);
            $changes = array_filter($record, fn ($value, $key) => $value === null
                ? $ata->{$key} !== null : ($key === 'VIGENCIA_ANO_INPC'
                    ? $ata->{$key} === null || (bool) $ata->{$key} !== $value
                    : (string) $ata->{$key} !== (string) $value), ARRAY_FILTER_USE_BOTH);
            $version = (int) $ata->version;
            if ($changes !== []) {
                $now = now();
                TransactionGuard::requireActive();
                $related = [];
                if (array_key_exists('PROCESSO', $changes)) {
                    $products = DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $id)->orderBy('ID')->lockForUpdate()->get(['ID', 'PROCESSO']);
                    $drafts = DB::table('ata_participant_drafts')->where('ata_id', $id)->orderBy('id')->lockForUpdate()->get(['id', 'process']);
                    $participants = DB::table('tb_Participantes')->whereIn('draft_id', $drafts->pluck('id'))->orderBy('id')->lockForUpdate()->get(['id', 'Processo_ATA']);
                    DB::table('tb_PRODUTOS_ATA')->whereIn('ID', $products->pluck('ID'))->update(['PROCESSO' => $changes['PROCESSO'], 'updated_at' => $now]);
                    DB::table('ata_participant_drafts')->whereIn('id', $drafts->pluck('id'))->update(['process' => $changes['PROCESSO'], 'updated_at' => $now]);
                    DB::table('tb_Participantes')->whereIn('id', $participants->pluck('id'))->update(['Processo_ATA' => $changes['PROCESSO'], 'updated_at' => $now]);
                    $related = ['process' => $changes['PROCESSO'], 'products_before' => $products->map(fn ($row) => (array) $row)->all(),
                        'drafts_before' => $drafts->map(fn ($row) => (array) $row)->all(), 'participants_before' => $participants->map(fn ($row) => (array) $row)->all()];
                }
                DB::table('tb_ATA')->where('ID', $id)->update($changes + ['version' => ++$version, 'updated_at' => $now]);
                ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_updated', 'created_at' => $now,
                    'metadata' => ['ata_id' => $id, 'before_version' => $ata->version, 'after_version' => $version,
                        'before' => $before, 'after' => $record, 'related_process_changes' => $related]]);
            }

            return ['id' => $id, 'number' => $ata->ATA, 'version' => $version];
        }, 3);
    }
}
