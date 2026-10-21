<?php

namespace App\Infrastructure\Budgets;

use App\Domain\Contracts\BudgetCode;
use App\Infrastructure\Security\CatalogAccess;
use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class BudgetRegistration
{
    public const FIELDS = ['Ficha', 'Orgao', 'Gestao', 'Unidade', 'Funcao', 'SubFunc', 'Programa', 'Projeto', 'Codigo', 'Fonte'];

    public function __construct(private LockActor $actors) {}

    public function visible(User $actor)
    {
        return CatalogAccess::byOrganization($actor, DB::table('tb_Dotacao')->whereNull('deleted_at'), 'Fundo', 'Secretaria', false);
    }

    public function revision(object $record): string
    {
        return hash_hmac('sha256', json_encode((array) $record, JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function save(User $expected, ?int $id, array $input, bool $delete = false): int
    {
        return Cache::lock('contracts-budget-registration', 30)->block(5, fn () => DB::transaction(function () use ($expected, $id, $input, $delete) {
            $actor = $this->actors->lock($expected);
            CatalogAccess::authorize($actor, 'budget');
            $record = $id ? $this->visible($actor)->where('ID_Dotacao', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $record, 404);
            if ($record && (! is_string($input['revision'] ?? null) || ! hash_equals($this->revision($record), $input['revision']))) {
                throw new ConflictHttpException('Esta dotação foi alterada. Abra o cadastro novamente.');
            }
            if ($delete) {
                abort_unless($record, 404);
                Validator::make($input, ['confirm_delete' => 'accepted'])->validate();
                DB::table('tb_Dotacao')->where('ID_Dotacao', $id)->update(['deleted_at' => now()]);
                $after = null;
            } else {
                $rules = ['fund_id' => 'required|integer|min:1', 'department_id' => 'nullable|integer|min:1', 'Descricao' => 'required|string|max:5000'];
                foreach (self::FIELDS as $field) {
                    $rules[$field] = ['required', 'string', 'max:32', 'regex:/^[0-9]+$/D'];
                }
                $data = Validator::make($input, $rules)->validate();
                $departmentId = empty($data['department_id']) ? null : (int) $data['department_id'];
                CatalogAccess::destination($actor, (int) $data['fund_id'], $departmentId);
                $fund = CatalogAccess::funds($actor)->where('id', $data['fund_id'])->lockForUpdate()->first();
                abort_unless($fund, 422, 'Selecione um Fundo disponível.');
                $municipal = Normalizer::name($fund->name) === 'MUNICIPIO DE NIQUELANDIA';
                if (! $municipal && $departmentId !== null) {
                    throw ValidationException::withMessages(['department_id' => 'Secretaria está disponível somente para o MUNICIPIO DE NIQUELÂNDIA.']);
                }
                $department = $departmentId ? CatalogAccess::departments($actor)->where('id', $departmentId)->lockForUpdate()->first() : null;
                abort_if($departmentId && ! $department, 422, 'Selecione uma Secretaria disponível.');
                if (DB::table('tb_Dotacao')->whereRaw('TRIM(Ficha) = ?', [$data['Ficha']])->when($id, fn ($query) => $query->where('ID_Dotacao', '!=', $id))->exists()) {
                    throw ValidationException::withMessages(['Ficha' => 'Esta ficha já possui cadastro, inclusive no histórico. Abra a dotação existente.']);
                }
                $after = array_intersect_key($data, array_flip(self::FIELDS));
                $componentsChanged = ! $record;
                foreach ([...BudgetCode::PARTS, 'Fonte', 'Ficha'] as $field) {
                    $componentsChanged = $componentsChanged || (string) ($record->$field ?? '') !== $data[$field];
                }
                $after += ['Descricao' => $data['Descricao'], 'Dotacao' => $componentsChanged ? BudgetCode::compose($data) : $record->Dotacao,
                    'Fundo' => $fund->name, 'Secretaria' => $department?->name];
                if (! $municipal && $record && ! array_key_exists('department_id', $input) && Normalizer::name($record->Fundo) === Normalizer::name($fund->name)) {
                    $after['Secretaria'] = $record->Secretaria;
                }
                if ($record) {
                    DB::table('tb_Dotacao')->where('ID_Dotacao', $id)->update($after);
                } else {
                    $id = DB::table('tb_Dotacao')->insertGetId($after, 'ID_Dotacao');
                }
            }
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => $delete ? 'budget_deleted' : ($record ? 'budget_updated' : 'budget_created'),
                'metadata' => ['id' => $id, 'before' => $record ? (array) $record : null, 'after' => $after], 'created_at' => now()]);

            return $id;
        }, 3));
    }
}
