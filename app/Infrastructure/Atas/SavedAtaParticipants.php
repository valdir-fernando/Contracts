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

final class SavedAtaParticipants
{
    public function __construct(private LockActor $actors, private SelectedFund $funds, private AtaWritePolicy $policy) {}

    public function add(User $expected, int $ataId, array $input, ?int $sessionFundId): array
    {
        return DB::transaction(function () use ($expected, $ataId, $input, $sessionFundId) {
            $actor = $this->actors->lock($expected);
            abort_unless($sessionFundId !== null && $this->funds->allows($actor, $sessionFundId), 403);
            $data = Validator::make($input, ['version' => 'required|integer|min:1', 'participant_fund_id' => 'required|integer|min:1'])->validate();
            $context = DB::table('tb_ATA')->where('ID', $ataId)->whereNull('deleted_at')->first(['fund_id']);
            abort_unless($context, 404);
            $this->authorize($actor, $context->fund_id);
            // Keep the same ordering as native registration: actor, funds by ID, then ata.
            $funds = DB::table('contract_funds')->whereIn('id', array_filter([$context->fund_id, (int) $data['participant_fund_id']]))
                ->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $ata = DB::table('tb_ATA')->where('ID', $ataId)->whereNull('deleted_at')->lockForUpdate()
                ->first(['ID', 'ATA', 'PROCESSO', 'CNPJ_FUNDO', 'fund_id', 'version']);
            abort_unless($ata, 404);
            $this->authorize($actor, $ata->fund_id);
            abort_if($ata->fund_id !== $context->fund_id, 409, 'O órgão da ata mudou. Consulte novamente pelo Nº ATA.');
            abort_if((int) $data['version'] !== (int) $ata->version, 409, 'A ata foi alterada. Consulte novamente pelo Nº ATA antes de adicionar o Fundo.');
            $fund = $funds->get((int) $data['participant_fund_id']);
            $document = Normalizer::document($fund->document ?? '');
            if (! $fund || trim($fund->name) === '' || strlen($document) !== 14 || ! Normalizer::validDocument($document)) {
                throw ValidationException::withMessages(['participant_fund_id' => 'Selecione um Fundo ativo com nome e CNPJ válido no cadastro.']);
            }
            $rows = DB::table('tb_FUNDOS_ATA')->where('ID_ATA', $ataId)->orderBy('ID')->lockForUpdate()->get();
            $matching = $rows->filter(fn ($row) => (int) $row->fund_id === (int) $fund->id
                || Normalizer::document($row->CNPJ_FUNDO) === $document);
            if ($matching->contains(fn ($row) => $row->deleted_at === null)) {
                throw ValidationException::withMessages(['participant_fund_id' => 'Este Fundo já está na lista da ata.']);
            }
            abort_if($matching->count() > 1, 409, 'Há vínculos históricos ambíguos para este Fundo. Revise o cadastro.');
            $previous = $matching->first();
            $now = now();
            $isManager = (int) $ata->fund_id === (int) $fund->id || Normalizer::document($ata->CNPJ_FUNDO) === $document;
            $values = ['ID_ATA' => $ataId, 'ATA' => $ata->ATA, 'CNPJ_FUNDO' => $document, 'FUNDO' => $fund->name,
                'fund_id' => $fund->id, 'papel' => $isManager || $previous?->papel === 'gerenciador' ? 'gerenciador' : 'participante',
                'deleted_at' => null, 'updated_at' => $now];
            if ($previous) {
                $id = $previous->ID;
                DB::table('tb_FUNDOS_ATA')->where('ID', $id)->update($values);
            } else {
                $id = DB::table('tb_FUNDOS_ATA')->insertGetId($values + ['created_at' => $now], 'ID');
            }
            DB::table('tb_ATA')->where('ID', $ataId)->update(['version' => $ata->version + 1, 'updated_at' => $now]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_saved_participant_added',
                'metadata' => ['ata_id' => $ataId, 'id' => $id, 'before_version' => $ata->version,
                    'after_version' => $ata->version + 1, 'before' => $previous ? (array) $previous : null, 'after' => $values], 'created_at' => $now]);

            return ['version' => $ata->version + 1, 'participants' => DB::table('tb_FUNDOS_ATA')
                ->where('ID_ATA', $ataId)->whereNull('deleted_at')->orderBy('ID')
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
