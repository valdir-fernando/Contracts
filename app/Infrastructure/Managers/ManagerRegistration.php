<?php

namespace App\Infrastructure\Managers;

use App\Infrastructure\Security\CatalogAccess;
use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ManagerRegistration
{
    public const FIELDS = [
        'CPF_Gestor' => ['CPF do gestor', 'text'],
        'RG_Gestor' => ['RG / CIC do gestor', 'text'],
        'Data_Nasc' => ['Data de nascimento', 'date'],
        'Sexo' => ['Sexo', 'select'],
        'Nome_Gestor' => ['Nome do gestor', 'text'],
        'Fone_Celular' => ['Celular do gestor', 'tel'],
        'WhatsApp' => ['WhatsApp', 'tel'],
        'Email' => ['Email do gestor', 'email'],
        'Cargo' => ['Cargo do gestor', 'text'],
        'Cargo_Abreviado' => ['Cargo abreviado', 'text'],
        'Matricula' => ['Matrícula', 'text'],
        'Assinatura' => ['Assinar os contratos do Executivo', 'checkbox'],
        'Decreto' => ['Decreto', 'text'],
        'Data_Decreto' => ['Data do decreto', 'date'],
        'Endereco' => ['Endereço completo', 'text'],
    ];

    public function __construct(private LockActor $actors) {}

    public static function dateForForm(?string $value): ?string
    {
        $value = trim($value ?? '');
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/D', $value, $parts)) {
            $value = $parts[3].'-'.$parts[2].'-'.$parts[1];
        }

        return Normalizer::date($value);
    }

    public function delete(User $expected, int $id, array $input): void
    {
        DB::transaction(function () use ($expected, $id, $input) {
            $actor = $this->actors->lock($expected);
            CatalogAccess::authorize($actor, 'manager-fund');
            $this->lockFunds($id, null);
            $record = CatalogAccess::byOrganization($actor, DB::table('tb_Gestor'), 'fund_name_key')->whereNull('deleted_at')->where('id', $id)->lockForUpdate()->first();
            abort_unless($record, 404);
            $data = Validator::make($input, ['revision' => 'required|string', 'confirmation' => 'accepted'])->validate();
            abort_unless(hash_equals($this->revision($record), $data['revision']), 409, 'Este gestor foi alterado. Reabra o cadastro antes de excluir.');
            $now = now();
            DB::table('tb_Gestor')->where('id', $id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'manager_deleted',
                'metadata' => ['id' => $id, 'name' => $record->name, 'fund' => $record->fund_name_key], 'created_at' => $now]);
        }, 3);
    }

    public function revision(object $record): string
    {
        return hash_hmac('sha256', json_encode((array) $record, JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function lockFunds(?int $id, ?int $fundId): void
    {
        $oldKey = $id ? DB::table('tb_Gestor')->where('id', $id)->value('fund_name_key') : null;
        DB::table('contract_funds')->where(function ($query) use ($oldKey, $fundId) {
            $query->where('id', $fundId ?? -1);
            if ($oldKey !== null) {
                $query->orWhere('name_key', $oldKey);
            }
        })->orderBy('id')->lockForUpdate()->get(['id']);
    }

    public function save(User $expected, ?int $id, array $input): int
    {
        return DB::transaction(function () use ($expected, $id, $input) {
            $actor = $this->actors->lock($expected);
            CatalogAccess::authorize($actor, 'manager-fund');
            $fundInput = Validator::make($input, ['fund_id' => 'required|integer|exists:contract_funds,id'])->validate();
            $this->lockFunds($id, (int) $fundInput['fund_id']);
            $record = $id ? CatalogAccess::byOrganization($actor, DB::table('tb_Gestor'), 'fund_name_key')->whereNull('deleted_at')->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $record, 404);
            if ($record) {
                abort_unless(is_string($input['revision'] ?? null) && hash_equals($this->revision($record), $input['revision']), 409, 'Este gestor foi alterado. Reabra o cadastro antes de salvar.');
            }
            $raw = $record ? json_decode($record->raw_payload, true, 512, JSON_THROW_ON_ERROR) : [];
            $previous = $raw;
            if (is_string($input['Nome_Gestor'] ?? null)) {
                $input['Nome_Gestor'] = mb_strtoupper($input['Nome_Gestor'], 'UTF-8');
            }
            $rules = [
                'Nome_Gestor' => 'required|string|max:255', 'CPF_Gestor' => 'required|string|max:18',
                'RG_Gestor' => 'nullable|string|max:32', 'Data_Nasc' => 'nullable|date_format:Y-m-d',
                'Sexo' => ['nullable', Rule::in(array_filter(['Feminino', 'Masculino', 'Não informado', $raw['Sexo'] ?? null]))],
                'Fone_Celular' => 'nullable|string|max:32', 'WhatsApp' => 'nullable|string|max:32',
                'Email' => 'nullable|email|max:255', 'Cargo' => 'nullable|string|max:255',
                'Cargo_Abreviado' => 'nullable|string|max:255', 'Matricula' => 'nullable|string|max:100',
                'Assinatura' => 'sometimes|boolean',
                'Decreto' => 'nullable|string|max:100', 'Data_Decreto' => 'nullable|date_format:Y-m-d',
                'Endereco' => 'nullable|string|max:2000', 'fund_id' => 'required|integer|exists:contract_funds,id',
            ];
            $data = Validator::make($input, $rules)->validate();
            if (filled($data['CPF_Gestor'] ?? null)) {
                $cpf = Normalizer::document($data['CPF_Gestor']);
                if (! preg_match('/^[\d\s.\-]+$/D', $data['CPF_Gestor']) || strlen($cpf) !== 11 || ! Normalizer::validDocument($cpf)) {
                    throw ValidationException::withMessages(['CPF_Gestor' => 'Informe um CPF válido.']);
                }
                $data['CPF_Gestor'] = $cpf;
            }
            $fund = DB::table('contract_funds')->whereNull('deleted_at')->where('id', $data['fund_id'])->lockForUpdate()->first();
            abort_unless($fund, 422);
            CatalogAccess::destination($actor, (int) $fund->id, null);
            if (! ManagerSignatory::isMunicipal($fund->name)) {
                if (! empty($data['Assinatura'])) {
                    throw ValidationException::withMessages(['Assinatura' => 'A assinatura do Executivo está disponível somente para o Município de Niquelândia.']);
                }
                $data['Assinatura'] = 0;
            }
            $signature = $data['Assinatura'] ?? $raw['Assinatura'] ?? 0;
            if ((string) $signature === '1') {
                $others = DB::table('tb_Gestor')->whereNull('deleted_at')
                    ->where('fund_name_key', ManagerSignatory::MUNICIPAL_FUND)
                    ->when($id, fn ($query) => $query->where('id', '!=', $id))->orderBy('id')->lockForUpdate()->get(['raw_payload']);
                if ($others->contains(fn ($other) => (string) (json_decode($other->raw_payload, true)['Assinatura'] ?? 0) === '1')) {
                    throw ValidationException::withMessages(['Assinatura' => 'Já existe um gestor autorizado a assinar pelo Executivo. Desmarque o gestor atual antes de ativar outro.']);
                }
            }
            if (filled($data['CPF_Gestor'] ?? null)) {
                $column = DB::connection()->getQueryGrammar()->wrap('raw_payload->CPF_Gestor');
                $duplicate = DB::table('tb_Gestor')->whereNull('deleted_at')
                    ->when($id, fn ($query) => $query->where('id', '!=', $id))
                    ->whereRaw("replace(replace(replace({$column}, '.', ''), '-', ''), ' ', '') = ?", [$data['CPF_Gestor']])->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages(['CPF_Gestor' => 'Já existe um gestor com este CPF. Edite o cadastro existente.']);
                }
            }
            foreach (self::FIELDS as $key => $_) {
                if (array_key_exists($key, $data)) {
                    if (in_array($key, ['Data_Nasc', 'Data_Decreto'])) {
                        $originalDate = self::dateForForm($raw[$key] ?? null);
                        if (empty($data[$key]) && filled($raw[$key] ?? null) && ! $originalDate) {
                            continue;
                        }
                    }
                    $raw[$key] = $data[$key];
                }
            }
            $raw['Fundo'] = $fund->name;
            $values = ['name' => $data['Nome_Gestor'], 'name_key' => Normalizer::name($data['Nome_Gestor']),
                'fund_name_key' => Normalizer::name($fund->name),
                'document' => $raw['CPF_Gestor'] ?? null, 'document_key' => Normalizer::document($raw['CPF_Gestor'] ?? ''),
                'raw_payload' => json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now()];
            if ($record) {
                DB::table('tb_Gestor')->where('id', $id)->update($values);
            } else {
                $id = DB::table('tb_Gestor')->insertGetId($values + ['created_at' => now()]);
            }
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => $record ? 'manager_updated' : 'manager_created',
                'metadata' => ['id' => $id, 'changed_fields' => array_values(array_filter(array_keys($raw), fn ($key) => ! array_key_exists($key, $previous) || $previous[$key] !== $raw[$key])),
                    'before' => $record ? ['name' => $record->name, 'fund' => $record->fund_name_key] : null,
                    'after' => ['name' => $values['name'], 'fund' => $values['fund_name_key']]], 'created_at' => now()]);

            return $id;
        }, 3);
    }
}
