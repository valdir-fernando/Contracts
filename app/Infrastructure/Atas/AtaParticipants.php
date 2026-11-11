<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AtaParticipants
{
    public function __construct(private LockActor $actors, private SelectedFund $selectedFund) {}

    public function token(User $expected): string
    {
        $actor = $this->actors->current($expected);

        return Crypt::encryptString(json_encode(['id' => (string) Str::uuid(), 'actor_id' => $actor->id,
            'session_version' => $actor->session_version], JSON_THROW_ON_ERROR));
    }

    public function state(User $expected, string $token): array
    {
        $actor = $this->actors->current($expected);
        $id = $this->draftId($actor, $token);
        $draft = DB::table('ata_participant_drafts')->where('id', $id)->where('actor_id', $actor->id)->first();

        return $this->projection($draft);
    }

    public function add(User $expected, string $token, array $input, ?int $sessionFundId): array
    {
        return DB::transaction(function () use ($expected, $token, $input, $sessionFundId) {
            $actor = $this->actors->lock($expected);
            abort_if($sessionFundId !== null && ! $this->selectedFund->allows($actor, $sessionFundId), 403);
            // O módulo de atas é global; a escrita abaixo se limita ao rascunho do ator atual.
            $id = $this->draftId($actor, $token);
            $draft = DB::table('ata_participant_drafts')->where('id', $id)->where('actor_id', $actor->id)->lockForUpdate()->first();
            $data = Validator::make($input, ['version' => 'required|integer|min:0', 'ata_process' => 'required|string|max:64',
                'managing_fund_id' => 'required|integer|min:1', 'participant_fund_id' => 'required|integer|min:1|different:managing_fund_id'])->validate();
            abort_if((int) $data['version'] !== (int) ($draft->version ?? 0), 409, 'A lista foi alterada. Recarregue o painel antes de adicionar outro Fundo.');
            $process = trim($data['ata_process']);
            if ($process === '') {
                throw ValidationException::withMessages(['ata_process' => 'Informe o Processo ATA.']);
            }
            $funds = DB::table('contract_funds')->whereIn('id', [$data['managing_fund_id'], $data['participant_fund_id']])
                ->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get(['id', 'name', 'document'])->keyBy('id');
            foreach (['managing_fund_id', 'participant_fund_id'] as $field) {
                if (! $funds->has($data[$field]) || trim($funds[$data[$field]]->name) === '') {
                    throw ValidationException::withMessages([$field => 'Selecione um Fundo ativo.']);
                }
            }
            $fund = $funds[$data['participant_fund_id']];
            $document = Normalizer::document($fund->document ?? '');
            if (strlen($document) !== 14 || ! Normalizer::validDocument($document)) {
                throw ValidationException::withMessages(['participant_fund_id' => 'O Fundo selecionado precisa de um CNPJ válido no cadastro.']);
            }
            $existing = DB::table('tb_Participantes')->where('draft_id', $id)->whereNull('deleted_at');
            if ((clone $existing)->where('fund_id', $data['managing_fund_id'])->exists()) {
                throw ValidationException::withMessages(['managing_fund_id' => 'Este Fundo já foi adicionado como participante. Selecione outro gerenciador.']);
            }
            if ((clone $existing)->where(function ($query) use ($fund, $document) {
                $query->where('fund_id', $fund->id)->orWhere('CNPJ_Fundo', $document);
            })->exists()) {
                throw ValidationException::withMessages(['participant_fund_id' => 'Este Fundo já está na lista de participantes.']);
            }
            $now = now();
            $version = (int) ($draft->version ?? 0) + 1;
            $values = ['managing_fund_id' => (int) $data['managing_fund_id'], 'process' => $process, 'version' => $version, 'updated_at' => $now];
            if ($draft) {
                DB::table('ata_participant_drafts')->where('id', $id)->update($values);
                (clone $existing)->update(['Processo_ATA' => $process, 'updated_at' => $now]);
            } else {
                DB::table('ata_participant_drafts')->insert($values + ['id' => $id, 'actor_id' => $actor->id, 'created_at' => $now]);
            }
            $participant = ['draft_id' => $id, 'fund_id' => $fund->id, 'ATA' => null, 'Processo_ATA' => $process,
                'CNPJ_Fundo' => $document, 'FUNDO' => $fund->name, 'Tipo_Fundo' => 'Participante'];
            $participantId = DB::table('tb_Participantes')->insertGetId($participant + ['created_at' => $now, 'updated_at' => $now]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_participant_added', 'metadata' => [
                'id' => $participantId, 'draft_id' => $id, 'before_context' => $draft ? ['process' => $draft->process,
                    'managing_fund_id' => $draft->managing_fund_id, 'version' => $draft->version] : null,
                'after_context' => $values, 'participant' => $participant], 'created_at' => $now]);

            return $this->projection((object) ($values + ['id' => $id]));
        }, 3);
    }

    private function draftId(User $actor, string $token): string
    {
        try {
            $claims = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            abort(403);
        }
        abort_unless(is_array($claims) && ($claims['actor_id'] ?? null) === $actor->id
            && ($claims['session_version'] ?? null) === $actor->session_version
            && is_string($claims['id'] ?? null) && Str::isUuid($claims['id']), 403);

        return $claims['id'];
    }

    private function projection(?object $draft): array
    {
        return ['version' => (int) ($draft->version ?? 0), 'process' => $draft->process ?? '',
            'managing_fund_id' => isset($draft->managing_fund_id) ? (string) $draft->managing_fund_id : '',
            'participants' => $draft ? DB::table('tb_Participantes')->where('draft_id', $draft->id)->whereNull('deleted_at')
                ->orderBy('id')->get(['id', 'fund_id', 'ATA', 'Processo_ATA', 'CNPJ_Fundo', 'FUNDO', 'Tipo_Fundo'])->map(fn ($row) => (array) $row)->all() : []];
    }
}
