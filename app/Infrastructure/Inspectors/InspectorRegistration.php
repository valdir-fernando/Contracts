<?php

namespace App\Infrastructure\Inspectors;

use App\Infrastructure\Security\CatalogAccess;
use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class InspectorRegistration
{
    public const FIELDS = [
        'CPF_Fiscal' => ['CPF do fiscal', 'text'],
        'RG_Fiscal' => ['RG / CIC do fiscal', 'text'],
        'Data_Nasc' => ['Data de nascimento', 'date'],
        'Sexo' => ['Sexo', 'select'],
        'Nome_Fiscal' => ['Nome do fiscal', 'text'],
        'Celular' => ['Celular', 'tel'],
        'WhatsApp' => ['WhatsApp', 'tel'],
        'Situacao' => ['Situação', 'select'],
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
            CatalogAccess::authorize($actor, 'inspector');
            $record = CatalogAccess::byOrganization($actor, DB::table('contract_inspectors'), 'fund_name_key', 'department_name_key')->whereNull('deleted_at')->where('id', $id)->lockForUpdate()->first();
            abort_unless($record, 404);
            $data = Validator::make($input, ['revision' => 'required|string', 'confirmation' => 'accepted'])->validate();
            abort_unless(hash_equals($this->revision($record), $data['revision']), 409, 'Este fiscal foi alterado. Reabra o cadastro antes de excluir.');
            $now = now();
            DB::table('contract_inspectors')->where('id', $id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'inspector_deleted',
                'metadata' => ['id' => $id, 'name' => $record->name, 'fund' => $record->fund_name_key, 'department' => $record->department_name_key], 'created_at' => $now]);
        }, 3);
    }

    public function revision(object $record): string
    {
        return hash_hmac('sha256', json_encode((array) $record, JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function save(User $expected, ?int $id, array $input): int
    {
        return DB::transaction(function () use ($expected, $id, $input) {
            $actor = $this->actors->lock($expected);
            CatalogAccess::authorize($actor, 'inspector');
            // Serialize CPF checks across funds, including concurrent writes by different administrators.
            DB::table('contract_funds')->orderBy('id')->lockForUpdate()->get(['id']);
            $record = $id ? CatalogAccess::byOrganization($actor, DB::table('contract_inspectors'), 'fund_name_key', 'department_name_key')->whereNull('deleted_at')->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $record, 404);
            if ($record) {
                abort_unless(is_string($input['revision'] ?? null) && hash_equals($this->revision($record), $input['revision']), 409, 'Este fiscal foi alterado. Reabra o cadastro antes de salvar.');
            }
            $raw = $record ? json_decode($record->raw_payload, true, 512, JSON_THROW_ON_ERROR) : [];
            $previous = $raw;
            if (is_string($input['Nome_Fiscal'] ?? null)) {
                $input['Nome_Fiscal'] = mb_strtoupper($input['Nome_Fiscal'], 'UTF-8');
            }
            $rules = [
                'Nome_Fiscal' => 'required|string|max:255', 'CPF_Fiscal' => 'nullable|string|max:18',
                'RG_Fiscal' => 'nullable|string|max:32', 'Data_Nasc' => 'nullable|date_format:Y-m-d',
                'Sexo' => ['nullable', Rule::in(array_filter(['Feminino', 'Masculino', 'Não informado', $raw['Sexo'] ?? null]))],
                'Celular' => 'nullable|string|max:32', 'WhatsApp' => 'nullable|string|max:32',
                'Situacao' => ['sometimes', 'required', Rule::in(['Ativo', 'Inativo'])],
                'Decreto' => 'nullable|string|max:100', 'Data_Decreto' => 'nullable|date_format:Y-m-d',
                'Endereco' => 'nullable|string|max:2000', 'fund_id' => 'required|integer|exists:contract_funds,id',
                'department_id' => 'nullable|integer|exists:contract_departments,id',
            ];
            $data = Validator::make($input, $rules)->validate();
            if (filled($data['CPF_Fiscal'] ?? null)) {
                $cpf = Normalizer::document($data['CPF_Fiscal']);
                if (strlen($cpf) !== 11 || ! Normalizer::validDocument($cpf)) {
                    throw ValidationException::withMessages(['CPF_Fiscal' => 'Informe um CPF válido.']);
                }
                $data['CPF_Fiscal'] = $cpf;
            }
            $fund = DB::table('contract_funds')->whereNull('deleted_at')->where('id', $data['fund_id'])->lockForUpdate()->first();
            abort_unless($fund, 422);
            CatalogAccess::destination($actor, (int) $fund->id, empty($data['department_id']) ? null : (int) $data['department_id']);
            $department = empty($data['department_id']) ? null : DB::table('contract_departments')->where('id', $data['department_id'])->lockForUpdate()->first();
            if (! empty($data['department_id']) && (! $department || Normalizer::name($fund->name) !== 'MUNICIPIO DE NIQUELANDIA')) {
                throw ValidationException::withMessages(['department_id' => 'Selecione uma secretaria somente para o Município de Niquelândia.']);
            }
            $effectiveCpf = Normalizer::document($data['CPF_Fiscal'] ?? (array_key_exists('CPF_Fiscal', $data) ? '' : ($raw['CPF_Fiscal'] ?? '')));
            if ($effectiveCpf !== '') {
                $duplicate = DB::table('contract_inspectors')->whereNull('deleted_at')
                    ->when($id, fn ($query) => $query->where('id', '!=', $id))->orderBy('id')->lockForUpdate()->get(['raw_payload'])
                    ->contains(fn ($candidate) => Normalizer::document(json_decode($candidate->raw_payload, true, 512, JSON_THROW_ON_ERROR)['CPF_Fiscal'] ?? '') === $effectiveCpf);
                if ($duplicate) {
                    throw ValidationException::withMessages(['CPF_Fiscal' => 'Este CPF já pertence a outro cadastro de fiscal. Abra o cadastro existente ou corrija o CPF.']);
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
            $raw['Situacao'] ??= 'Ativo';
            $raw['Secretaria'] = $department?->name;
            $values = ['name' => $data['Nome_Fiscal'], 'name_key' => Normalizer::name($data['Nome_Fiscal']),
                'fund_name_key' => Normalizer::name($fund->name), 'department_name_key' => Normalizer::name($department?->name),
                'raw_payload' => json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now()];
            if ($record) {
                DB::table('contract_inspectors')->where('id', $id)->update($values);
            } else {
                $id = DB::table('contract_inspectors')->insertGetId($values + ['created_at' => now()]);
            }
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => $record ? 'inspector_updated' : 'inspector_created',
                'metadata' => ['id' => $id, 'changed_fields' => array_values(array_filter(array_keys($raw), fn ($key) => ! array_key_exists($key, $previous) || $previous[$key] !== $raw[$key])),
                    'before' => $record ? ['name' => $record->name, 'fund' => $record->fund_name_key, 'department' => $record->department_name_key] : null,
                    'after' => ['name' => $values['name'], 'fund' => $values['fund_name_key'], 'department' => $values['department_name_key']]], 'created_at' => now()]);

            return $id;
        }, 3);
    }
}
