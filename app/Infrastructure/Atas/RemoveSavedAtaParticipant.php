<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RemoveSavedAtaParticipant
{
    public function __construct(private LockActor $actors, private SelectedFund $funds,
        private AtaWritePolicy $policy, private AtaManagingFundSnapshot $managingFund) {}

    public function remove(User $expected, int $ataId, int $participantId, array $input, ?int $sessionFundId): array
    {
        return DB::transaction(function () use ($expected, $ataId, $participantId, $input, $sessionFundId) {
            $actor = $this->actors->lock($expected);
            abort_unless($sessionFundId !== null && $this->funds->allows($actor, $sessionFundId), 403);
            $data = Validator::make($input, ['version' => 'required|integer|min:1', 'replacement_fund_id' => 'nullable|integer|min:1'])->validate();
            $context = DB::table('tb_ATA')->where('ID', $ataId)->whereNull('deleted_at')->first(['fund_id']);
            abort_unless($context, 404);
            $this->authorize($actor, $context->fund_id);
            $replacementId = isset($data['replacement_fund_id']) ? (int) $data['replacement_fund_id'] : null;
            $funds = DB::table('contract_funds')->whereIn('id', array_filter([$context->fund_id, $replacementId]))
                ->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $ata = DB::table('tb_ATA')->where('ID', $ataId)->whereNull('deleted_at')->lockForUpdate()->first([
                'ID', 'ATA', 'PROCESSO', 'fund_id', 'version', 'CNPJ_FUNDO', 'FUNDO', 'END_FUNDO', 'GESTOR',
                'CPF_GESTOR', 'RG_GESTOR', 'CARGO_GESTOR', 'DECRETO_GESTOR', 'MATRICULA_GESTOR',
            ]);
            abort_unless($ata, 404);
            $this->authorize($actor, $ata->fund_id);
            abort_if($ata->fund_id !== $context->fund_id, 409, 'O órgão da ata mudou. Consulte novamente pelo Nº ATA.');
            abort_if((int) $data['version'] !== (int) $ata->version, 409, 'A ata foi alterada. Consulte novamente pelo Nº ATA antes de excluir o Fundo.');
            $rows = DB::table('tb_FUNDOS_ATA')->where('ID_ATA', $ataId)->orderBy('ID')->lockForUpdate()->get();
            $target = $rows->first(fn ($row) => (int) $row->ID === $participantId && $row->deleted_at === null);
            abort_unless($target, 404);
            $isManager = fn ($row) => $row->papel === 'gerenciador'
                || ($ata->fund_id !== null && (int) $row->fund_id === (int) $ata->fund_id)
                || Normalizer::document($row->CNPJ_FUNDO) === Normalizer::document($ata->CNPJ_FUNDO);
            $remainingManagers = $rows->filter(fn ($row) => $row->deleted_at === null && (int) $row->ID !== $participantId && $row->papel === 'gerenciador');
            $replaceManager = $isManager($target) || $remainingManagers->isEmpty();
            if ($replaceManager && $replacementId === null) {
                throw ValidationException::withMessages(['replacement_fund_id' => 'Uma ata salva não pode ficar sem Fundo Gerenciador. Selecione outro Fundo para assumir essa função antes de excluir.']);
            }
            $now = now();
            $newManager = [];
            $changedLinks = [];
            if ($replaceManager) {
                $fund = $funds->get($replacementId);
                if (! $fund || trim($fund->name) === '') {
                    throw ValidationException::withMessages(['replacement_fund_id' => 'Selecione um Fundo ativo para ser o novo gerenciador.']);
                }
                $this->policy->authorize($actor, $fund->id);
                $document = Normalizer::document($fund->document ?? '');
                if ((int) $fund->id === (int) $target->fund_id || $document === Normalizer::document($target->CNPJ_FUNDO)) {
                    throw ValidationException::withMessages(['replacement_fund_id' => 'Selecione um Fundo diferente daquele que será excluído.']);
                }
                $newManager = $this->managingFund->capture($actor, $fund) + ['fund_id' => $fund->id];
                $collision = DB::table('tb_ATA')->where('ID', '<>', $ataId)->where('ATA', $ata->ATA)
                    ->where('PROCESSO', $ata->PROCESSO)->orderBy('ID')->lockForUpdate()->get(['CNPJ_FUNDO'])
                    ->contains(fn ($other) => Normalizer::document($other->CNPJ_FUNDO) === $document);
                if ($collision) {
                    throw ValidationException::withMessages(['replacement_fund_id' => 'Já existe outra ata com este número, processo e órgão gerenciador.']);
                }
                $matches = $rows->filter(fn ($row) => (int) $row->ID !== $participantId
                    && ((int) $row->fund_id === (int) $fund->id || Normalizer::document($row->CNPJ_FUNDO) === $document));
                abort_if($matches->count() > 1, 409, 'Há vínculos ambíguos para o novo gerenciador. Revise o cadastro.');
                $existing = $matches->first();
                foreach ($rows as $row) {
                    if ($row->deleted_at === null && (int) $row->ID !== $participantId && $row->papel === 'gerenciador') {
                        DB::table('tb_FUNDOS_ATA')->where('ID', $row->ID)->update(['papel' => 'participante', 'updated_at' => $now]);
                        $changedLinks[] = ['before' => (array) $row, 'after' => ['papel' => 'participante']];
                    }
                }
                $link = ['ID_ATA' => $ataId, 'ATA' => $ata->ATA, 'fund_id' => $fund->id,
                    'CNPJ_FUNDO' => $document, 'FUNDO' => $fund->name, 'papel' => 'gerenciador', 'deleted_at' => null, 'updated_at' => $now];
                if ($existing) {
                    $newId = $existing->ID;
                    DB::table('tb_FUNDOS_ATA')->where('ID', $newId)->update($link);
                } else {
                    $newId = DB::table('tb_FUNDOS_ATA')->insertGetId($link + ['created_at' => $now], 'ID');
                }
                $changedLinks[] = ['id' => $newId, 'before' => $existing ? (array) $existing : null, 'after' => $link];
            }
            DB::table('tb_FUNDOS_ATA')->where('ID', $participantId)->update(['deleted_at' => $now, 'updated_at' => $now]);
            DB::table('tb_ATA')->where('ID', $ataId)->update($newManager + ['version' => $ata->version + 1, 'updated_at' => $now]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_saved_participant_removed',
                'metadata' => ['ata_id' => $ataId, 'id' => $participantId, 'before_version' => $ata->version,
                    'after_version' => $ata->version + 1, 'removed' => (array) $target, 'changed_links' => $changedLinks,
                    'manager_before' => $replaceManager ? array_intersect_key((array) $ata, $newManager) : null,
                    'manager_after' => $newManager], 'created_at' => $now]);

            return ['version' => $ata->version + 1, 'number' => $ata->ATA, 'manager_changed' => $replaceManager,
                'participants' => DB::table('tb_FUNDOS_ATA')->where('ID_ATA', $ataId)->whereNull('deleted_at')->orderBy('ID')
                    ->get(['ID', 'fund_id', 'FUNDO', 'CNPJ_FUNDO', 'papel'])->map(fn ($row) => [
                        'id' => $row->ID, 'fund_id' => $row->fund_id, 'ATA' => $ata->ATA, 'Processo_ATA' => $ata->PROCESSO,
                        'CNPJ_Fundo' => Normalizer::document($row->CNPJ_FUNDO), 'FUNDO' => $row->FUNDO,
                        'Tipo_Fundo' => $row->papel === 'gerenciador' ? 'Gerenciador' : 'Participante',
                    ])->all()];
        }, 3);
    }

    private function authorize(User $actor, ?int $fundId): void
    {
        if ($fundId === null) {
            abort_unless($actor->isAdmin(), 403);

            return;
        }
        $this->policy->authorize($actor, $fundId);
    }
}
