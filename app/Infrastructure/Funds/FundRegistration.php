<?php

namespace App\Infrastructure\Funds;

use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class FundRegistration
{
    public const DETAILS = ['End_Fundo' => ['Endereço completo', 2000], 'Abreviatuda' => ['Abreviatura', 50],
        'Codigo' => ['Código de gestão', 50], 'Telefone' => ['Telefone de contato', 32], 'Nome' => ['Nome do contato', 255]];

    public function __construct(private LockActor $actors) {}

    public function revision(object $record): string
    {
        return hash_hmac('sha256', json_encode((array) $record, JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function linked(object $record): bool
    {
        return DB::table('contracts')->where(function ($query) use ($record) {
            $query->where('fund_id', $record->id)->orWhereIn('fundo_nome_snapshot', [$record->name, $record->name_key]);
        })->exists()
            || DB::table('access_scopes')->where(function ($query) use ($record) {
                $query->where('fund_id', $record->id)->orWhereIn('fund', [$record->name, $record->name_key]);
            })->exists()
            || DB::table('tb_N_Contrato')->where('fund_id', $record->id)->exists()
            || DB::table('tb_Gestor')->where('fund_name_key', $record->name_key)->exists()
            || DB::table('contract_stewards')->where('fund_name_key', $record->name_key)->exists()
            || DB::table('contract_inspectors')->where('fund_name_key', $record->name_key)->exists();
    }

    public function save(User $expected, ?int $id, array $input): int
    {
        return DB::transaction(function () use ($expected, $id, $input) {
            $actor = $this->actors->lock($expected);
            abort_unless($actor->isAdmin(), 403);
            $record = $id ? DB::table('contract_funds')->whereNull('deleted_at')->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $record, 404);
            if ($record) {
                $this->checkRevision($record, $input);
            }
            $rules = ['name' => 'required|string|max:255', 'document' => 'required|string|max:32'];
            foreach (self::DETAILS as $key => [, $max]) {
                $rules[$key] = 'sometimes|nullable|string|max:'.$max;
            }
            $data = Validator::make($input, $rules)->validate();
            $document = Normalizer::document($data['document']);
            if (! preg_match('/^[\d\s.\/\-]+$/D', $data['document']) || strlen($document) !== 14 || ! Normalizer::validDocument($document)) {
                throw ValidationException::withMessages(['document' => 'Informe um CNPJ válido.']);
            }
            $name = trim($data['name']);
            $nameKey = Normalizer::name($name);
            if ($record && $nameKey !== $record->name_key && ($record->legacy_id !== null || $this->linked($record))) {
                throw ValidationException::withMessages(['name' => 'Este Fundo possui origem ou vínculos existentes. Preserve o nome para manter suas associações.']);
            }
            $duplicates = DB::table('contract_funds')->when($id, fn ($query) => $query->where('id', '!=', $id));
            if ((clone $duplicates)->where('document_key', $document)->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages(['document' => 'Já existe um Fundo com este CNPJ. Abra o cadastro existente.']);
            }
            if ((clone $duplicates)->whereNull('deleted_at')->where('name_key', $nameKey)->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages(['name' => 'Já existe um Fundo com este nome. Abra o cadastro existente.']);
            }
            $raw = $record ? json_decode($record->raw_payload, true, 512, JSON_THROW_ON_ERROR) : [];
            $before = $record ? ['name' => $record->name, 'document' => $record->document] + array_intersect_key($raw, self::DETAILS) : null;
            foreach (self::DETAILS as $key => $_) {
                if (array_key_exists($key, $data)) {
                    $raw[$key] = $data[$key];
                }
            }
            $raw['Fundo'] = $name;
            $raw['CNPJ_Fundo'] = $document;
            $values = ['name' => $name, 'name_key' => $nameKey, 'document' => $document, 'document_key' => $document,
                'raw_payload' => json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now()];
            if ($record) {
                DB::table('contract_funds')->where('id', $id)->update($values);
            } else {
                $id = DB::table('contract_funds')->insertGetId($values + ['created_at' => now()]);
            }
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => $record ? 'fund_updated' : 'fund_created',
                'metadata' => ['id' => $id, 'before' => $before, 'after' => ['name' => $name, 'document' => $document] + array_intersect_key($raw, self::DETAILS)], 'created_at' => now()]);

            return $id;
        }, 3);
    }

    public function delete(User $expected, int $id, array $input): void
    {
        DB::transaction(function () use ($expected, $id, $input) {
            $actor = $this->actors->lock($expected);
            abort_unless($actor->isAdmin(), 403);
            $record = DB::table('contract_funds')->whereNull('deleted_at')->where('id', $id)->lockForUpdate()->first();
            abort_unless($record, 404);
            Validator::make($input, ['confirmation' => 'accepted'])->validate();
            $this->checkRevision($record, $input);
            if ($this->linked($record)) {
                throw ValidationException::withMessages(['confirmation' => 'Este Fundo possui contratos, permissões, numeração, gestores ou fiscais vinculados e não pode ser excluído.']);
            }
            $now = now();
            DB::table('contract_funds')->where('id', $id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'fund_deleted', 'metadata' => ['id' => $id, 'name' => $record->name], 'created_at' => $now]);
        }, 3);
    }

    private function checkRevision(object $record, array $input): void
    {
        $data = Validator::make($input, ['revision' => 'required|string'])->validate();
        abort_unless(hash_equals($this->revision($record), $data['revision']), 409, 'Este Fundo foi alterado. Reabra o cadastro antes de continuar.');
    }
}
