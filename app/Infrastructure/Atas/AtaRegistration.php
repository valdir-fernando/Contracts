<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AtaRegistration
{
    public function __construct(
        private LockActor $actors,
        private SelectedFund $funds,
        private AtaWritePolicy $policy,
        private AtaParticipants $participants,
        private AtaNumbering $numbering,
        private AtaManagingFundSnapshot $managingFund,
        private AtaSupplierSnapshot $suppliers,
    ) {}

    public function save(User $expected, array $input, ?int $sessionFundId): array
    {
        return DB::transaction(function () use ($expected, $input, $sessionFundId) {
            $actor = $this->actors->lock($expected);
            abort_unless($sessionFundId !== null && $this->funds->allows($actor, $sessionFundId), 403);
            $data = Validator::make($input, [
                'draft_token' => 'required|string|max:2048', 'version' => 'required|integer|min:0',
                'managing_fund_id' => 'required|integer|min:1', 'Data_ATA' => 'required|date_format:Y-m-d',
                'Data_Orçamento' => 'required|date_format:Y-m-d',
                'admitir_adesao' => 'sometimes|nullable|in:SIM,NAO',
                'ata_process' => 'required|string|max:64', 'bidding' => 'required|string|max:100',
                'bidding_number' => 'required|string|max:32', 'fornecedor_documento_snapshot' => 'required|string|max:32',
                'publication_date' => 'sometimes|nullable|date_format:Y-m-d',
            ], [], ['managing_fund_id' => 'Fundo Gerenciador', 'Data_ATA' => 'Data_ATA',
                'Data_Orçamento' => 'Data_Orçamento Estimado',
                'admitir_adesao' => 'Admitir Adesão a ATA',
                'ata_process' => 'Processo ATA', 'bidding' => 'Licitação', 'bidding_number' => 'Nº Licit',
                'fornecedor_documento_snapshot' => 'CPF/CNPJ do fornecedor'])->validate();
            $draft = $this->participants->lockForSave($actor, $data['draft_token']);
            $this->policy->authorize($actor, (int) $data['managing_fund_id']);
            if ($draft?->ata_id !== null) {
                $this->policy->authorize($actor, (int) $draft->managing_fund_id);
                $ata = DB::table('tb_ATA')->where('ID', $draft->ata_id)->whereNull('deleted_at')->lockForUpdate()->first(['ID', 'ATA', 'version', 'fund_id']);
                abort_unless($ata, 409, 'A ata deste rascunho não está disponível.');
                $this->policy->authorize($actor, (int) $ata->fund_id);

                return ['id' => $ata->ID, 'number' => $ata->ATA, 'version' => $ata->version];
            }
            abort_if((int) $data['version'] !== (int) ($draft->version ?? 0), 409, 'O rascunho mudou. Recarregue o painel antes de salvar.');
            $draftId = $this->participants->saveDraftId($actor, $data['draft_token']);
            $members = DB::table('tb_Participantes')->where('draft_id', $draftId)->whereNull('deleted_at')->orderBy('fund_id')->lockForUpdate()->get();
            $memberFunds = DB::table('contract_funds')->whereIn('id', $members->pluck('fund_id')->push((int) $data['managing_fund_id']))
                ->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $fund = $memberFunds->get((int) $data['managing_fund_id']);
            abort_unless($fund, 403);
            $contractor = $this->managingFund->capture($actor, $fund);
            $supplier = $this->suppliers->capture($actor, $data['fornecedor_documento_snapshot']);
            $now = now();
            if (! $draft) {
                DB::table('ata_participant_drafts')->insert(['id' => $draftId, 'actor_id' => $actor->id,
                    'managing_fund_id' => $fund->id, 'process' => $data['ata_process'], 'version' => 0,
                    'created_at' => $now, 'updated_at' => $now]);
            }
            foreach ($members as $member) {
                if (! isset($memberFunds[$member->fund_id]) || ! Normalizer::validDocument(Normalizer::document($member->CNPJ_Fundo))) {
                    throw ValidationException::withMessages(['participants' => 'Revise os Fundos participantes antes de salvar.']);
                }
            }
            $number = $this->numbering->next();
            $record = $contractor + $supplier + (new AtaValidityInput)->validate($input) + [
                'ATA' => $number, 'DATA_ATA' => $data['Data_ATA'], 'PROCESSO' => trim($data['ata_process']),
                'DATA_ORCAMENTO' => $data['Data_Orçamento'],
                'ADMITIR_ADESAO' => ($data['admitir_adesao'] ?? null) ?: null,
                'DATA_PUBLIC_INPC' => $data['publication_date'] ?? null,
                'LICITACAO' => trim($data['bidding']), 'N_LICIT' => trim($data['bidding_number']), 'fund_id' => $fund->id,
                'source_files' => '[]', 'quality_issues' => '[]', 'import_hash' => hash('sha256', 'native:'.$draftId),
                'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ];
            TransactionGuard::requireActive();
            $id = DB::table('tb_ATA')->insertGetId($record, 'ID');
            $fundRows = [$contractor['CNPJ_FUNDO'] => ['fund_id' => $fund->id, 'FUNDO' => $fund->name, 'papel' => 'gerenciador']];
            foreach ($members as $member) {
                $document = Normalizer::document($member->CNPJ_Fundo);
                $fundRows[$document] ??= ['fund_id' => $member->fund_id, 'FUNDO' => $member->FUNDO, 'papel' => 'participante'];
            }
            foreach ($fundRows as $document => $row) {
                DB::table('tb_FUNDOS_ATA')->insert($row + ['ID_ATA' => $id, 'ATA' => $number, 'CNPJ_FUNDO' => (string) $document, 'created_at' => $now, 'updated_at' => $now]);
            }
            DB::table('tb_Participantes')->where('draft_id', $draftId)->whereNull('deleted_at')->update([
                'ATA' => $number, 'Processo_ATA' => $record['PROCESSO'], 'updated_at' => $now,
            ]);
            DB::table('ata_participant_drafts')->where('id', $draftId)->update(['ata_id' => $id,
                'managing_fund_id' => $fund->id, 'process' => $record['PROCESSO'], 'version' => (int) $data['version'] + 1, 'updated_at' => $now]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_created',
                'metadata' => ['id' => $id, 'draft_id' => $draftId, 'after' => $record, 'funds' => $fundRows], 'created_at' => $now]);

            return ['id' => $id, 'number' => $number, 'version' => 1];
        }, 3);
    }
}
