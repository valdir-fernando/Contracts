<?php

namespace App\Infrastructure\ContractStewards;

use App\Infrastructure\Security\CatalogAccess;
use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ContractStewardRegistration
{
    public const FIELDS = [
        'CPF_Gestor' => ['CPF do gestor', 'text'],
        'RG_Gestor' => ['RG / CIC do gestor', 'text'],
        'Data_Nasc' => ['Data de nascimento', 'date'],
        'Sexo' => ['Sexo', 'select'],
        'Nome_Gestor' => ['Nome do gestor', 'text'],
        'Celular' => ['Celular', 'tel'],
        'WhatsApp' => ['WhatsApp', 'tel'],
        'Situacao' => ['Situação', 'select'],
        'Decreto' => ['Decreto', 'text'],
        'Data_Decreto' => ['Data do decreto', 'date'],
        'Endereco' => ['Endereço completo', 'text'],
    ];

    public function __construct(private LockActor $actors, private StewardLegacyTable $legacy = new StewardLegacyTable,
        private StewardAssignments $assignments = new StewardAssignments) {}

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
            CatalogAccess::authorize($actor, 'contract-steward');
            $this->assignments->lockFunds($id, null);
            $record = CatalogAccess::byOrganization($actor, DB::table('contract_stewards'), 'fund_name_key', 'department_name_key')->whereNull('deleted_at')->where('id', $id)->lockForUpdate()->first();
            abort_unless($record, 404);
            $data = Validator::make($input, ['revision' => 'required|string', 'confirmation' => 'accepted'])->validate();
            abort_unless(hash_equals($this->revision($record), $data['revision']), 409, 'Este gestor foi alterado. Reabra o cadastro antes de excluir.');
            $now = now();
            DB::table('contract_stewards')->where('id', $id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            $this->legacy->sync(DB::table('contract_stewards')->find($id));
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'contract_steward_deleted',
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
            CatalogAccess::authorize($actor, 'contract-steward');
            $destination = Validator::make($input, ['fund_id' => 'required|integer|exists:contract_funds,id'])->validate();
            $this->assignments->lockFunds($id, (int) $destination['fund_id']);
            $record = $id ? CatalogAccess::byOrganization($actor, DB::table('contract_stewards'), 'fund_name_key', 'department_name_key')->whereNull('deleted_at')->where('id', $id)->lockForUpdate()->first() : null;
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
                'Nome_Gestor' => 'required|string|max:255', 'CPF_Gestor' => 'nullable|string|max:18',
                'RG_Gestor' => 'nullable|string|max:32', 'Data_Nasc' => 'nullable|date_format:Y-m-d',
                'Sexo' => ['nullable', Rule::in(array_filter(['Feminino', 'Masculino', 'Não informado', $raw['Sexo'] ?? null]))],
                'Celular' => 'nullable|string|max:32', 'WhatsApp' => 'nullable|string|max:32',
                'Situacao' => ['sometimes', 'required', Rule::in(['Ativo', 'Inativo'])],
                'Decreto' => 'nullable|string|max:100', 'Data_Decreto' => 'nullable|date_format:Y-m-d',
                'Endereco' => 'nullable|string|max:2000', 'fund_id' => 'required|integer|exists:contract_funds,id',
                'department_id' => 'nullable|integer|exists:contract_departments,id',
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
            $department = empty($data['department_id']) ? null : DB::table('contract_departments')->where('id', $data['department_id'])->lockForUpdate()->first();
            if (! array_key_exists('department_id', $data) && $record && Normalizer::name($fund->name) === StewardAssignments::MUNICIPALITY
                && $record->fund_name_key === Normalizer::name($fund->name)
                && filled($record->department_name_key)) {
                $departments = DB::table('contract_departments')->where('name_key', $record->department_name_key)->orderBy('id')->lockForUpdate()->limit(2)->get();
                if ($departments->count() !== 1) {
                    throw ValidationException::withMessages(['department_id' => 'Selecione novamente uma Secretaria válida antes de salvar.']);
                }
                $department = $departments->first();
            }
            if (! empty($data['department_id']) && (! $department || Normalizer::name($fund->name) !== 'MUNICIPIO DE NIQUELANDIA')) {
                throw ValidationException::withMessages(['department_id' => 'Selecione uma secretaria somente para o Município de Niquelândia.']);
            }
            CatalogAccess::destination($actor, (int) $fund->id, $department ? (int) $department->id : null);
            $this->assignments->assertAvailable($fund, $department, $id);
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
            $values = ['name' => $data['Nome_Gestor'], 'name_key' => Normalizer::name($data['Nome_Gestor']),
                'fund_name_key' => Normalizer::name($fund->name), 'department_name_key' => Normalizer::name($department?->name),
                'raw_payload' => json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now()];
            if ($record) {
                DB::table('contract_stewards')->where('id', $id)->update($values);
            } else {
                $id = DB::table('contract_stewards')->insertGetId($values + ['created_at' => now()]);
            }
            $this->legacy->sync(DB::table('contract_stewards')->find($id));
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => $record ? 'contract_steward_updated' : 'contract_steward_created',
                'metadata' => ['id' => $id, 'changed_fields' => array_values(array_filter(array_keys($raw), fn ($key) => ! array_key_exists($key, $previous) || $previous[$key] !== $raw[$key])),
                    'before' => $record ? ['name' => $record->name, 'fund' => $record->fund_name_key, 'department' => $record->department_name_key] : null,
                    'after' => ['name' => $values['name'], 'fund' => $values['fund_name_key'], 'department' => $values['department_name_key']]], 'created_at' => now()]);

            return $id;
        }, 3);
    }
}
