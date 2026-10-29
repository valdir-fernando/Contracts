<?php

namespace App\Infrastructure\Dfd;

use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Security\CatalogAccess;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DfdRegistration
{
    public const TEXT_FIELDS = ['Processo_DFD', 'Necessidade', 'Desricao', 'Prioridade', 'Vigencia', 'Local', 'End_Local', 'Vinculacao', 'Observacoes', 'Responsavel', 'Decreto', 'Cargo', 'email'];

    public function __construct(private DfdAccess $access, private DfdNumbering $numbering) {}

    public function save(User $expected, ?int $fundId, ?int $id, array $input): int
    {
        return DB::transaction(function () use ($expected, $fundId, $id, $input) {
            [$actor, $fund] = $this->access->context($expected, $fundId, true);
            $record = $id ? $this->access->record($actor, $fund->id, $id, true) : null;
            if ($record) {
                $this->access->version($record, $input);
            }
            $rules = ['Categoria' => 'required|string|max:1000', 'Data_DFD' => 'required|date_format:Y-m-d',
                'Data_Conclusao' => 'nullable|date_format:Y-m-d', 'department_id' => 'nullable|integer|min:1', 'budget_id' => 'nullable|integer|min:1'];
            foreach (self::TEXT_FIELDS as $field) {
                $rules[$field] = 'nullable|string|max:15000';
            }
            $rules['Necessidade'] = $rules['Desricao'] = 'required|string|max:15000';
            $rules['email'] = 'nullable|email|max:255';
            $data = Validator::make($input, $rules)->validate();
            if (! DB::table('tb_Produtos_Prodata')->where('Classe', $data['Categoria'])->where('Inativo', 0)->exists()) {
                throw ValidationException::withMessages(['Categoria' => 'Selecione uma classe do catálogo de produtos.']);
            }
            if ($record && $data['Categoria'] !== $record->Categoria) {
                throw ValidationException::withMessages(['Categoria' => 'A classe de uma DFD salva não pode ser alterada. Crie uma nova DFD.']);
            }
            $departmentId = array_key_exists('department_id', $input) ? ($data['department_id'] ?? null) : $record?->department_id;
            $municipal = Normalizer::name($fund->name) === 'MUNICIPIO DE NIQUELANDIA';
            if (! $municipal && $departmentId) {
                throw ValidationException::withMessages(['department_id' => 'Secretaria disponível somente para o Município.']);
            }
            $department = $this->access->destination($actor, $fund, $departmentId ? (int) $departmentId : null);
            $patch = [];
            foreach (self::TEXT_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $patch[$field] = $data[$field] ?? '';
                }
            }
            $patch += ['Categoria' => $data['Categoria'], 'Data_DFD' => $data['Data_DFD'], 'Fundo' => $fund->name,
                'department_id' => $department?->id, 'Requisitante' => $department?->name ?? $fund->name];
            if (array_key_exists('Data_Conclusao', $data)) {
                $patch['Data_Conclusao'] = $data['Data_Conclusao'];
            }
            if (array_key_exists('budget_id', $data)) {
                $budget = ! empty($data['budget_id']) ? CatalogAccess::byOrganization($actor, DB::table('tb_Dotacao')->whereNull('deleted_at'), 'Fundo', 'Secretaria', false)
                    ->whereIn('Fundo', [$fund->name, Normalizer::name($fund->name)])->where('ID_Dotacao', $data['budget_id'])->lockForUpdate()->first() : null;
                if (! empty($data['budget_id']) && (! $budget || ($department && filled($budget->Secretaria) && Normalizer::name($budget->Secretaria) !== Normalizer::name($department->name)))) {
                    throw ValidationException::withMessages(['budget_id' => 'Selecione uma ficha do Fundo e da área requisitante autorizados.']);
                }
                $patch['Ficha'] = $budget?->Ficha ?? '';
            }
            $patch += ['updated_by' => $actor->id, 'updated_at' => now()];
            if ($record) {
                $patch['version'] = $record->version + 1;
                DB::table('tb_DFD')->where('ID', $id)->update($patch);
            } else {
                $patch += ['fund_id' => $fund->id, 'N_DFD' => $this->numbering->next(now()->year),
                    'version' => 1, 'created_by' => $actor->id, 'created_at' => now()];
                $id = DB::table('tb_DFD')->insertGetId($patch, 'ID');
            }
            $this->audit($actor, $record ? 'dfd_updated' : 'dfd_created', $id, $record ? (array) $record : null, $patch);

            return $id;
        }, 3);
    }

    public function delete(User $expected, ?int $fundId, int $id, array $input): void
    {
        DB::transaction(function () use ($expected, $fundId, $id, $input) {
            [$actor, $fund] = $this->access->context($expected, $fundId, true);
            $record = $this->access->record($actor, $fund->id, $id, true);
            $this->access->version($record, $input);
            Validator::make($input, ['confirmation' => 'accepted'])->validate();
            $patch = ['deleted_at' => now(), 'updated_at' => now(), 'updated_by' => $actor->id, 'version' => $record->version + 1];
            DB::table('tb_DFD')->where('ID', $id)->update($patch);
            $this->audit($actor, 'dfd_deleted', $id, (array) $record, $patch);
        }, 3);
    }

    public function product(User $expected, ?int $fundId, int $id, ?int $itemId, array $input, bool $delete = false): void
    {
        DB::transaction(function () use ($expected, $fundId, $id, $itemId, $input, $delete) {
            [$actor, $fund] = $this->access->context($expected, $fundId, true);
            $record = $this->access->record($actor, $fund->id, $id, true);
            $this->access->version($record, $input);
            $item = $itemId ? DB::table('tb_Produtos_DFD')->where('ID_DFD', $id)->where('ID', $itemId)->whereNull('deleted_at')->lockForUpdate()->first() : null;
            abort_if($itemId && ! $item, 404);
            if ($delete) {
                abort_unless($item, 404);
                Validator::make($input, ['confirmation' => 'accepted'])->validate();
                $patch = ['deleted_at' => now(), 'updated_at' => now()];
                DB::table('tb_Produtos_DFD')->where('ID', $itemId)->update($patch);
            } else {
                $data = Validator::make($input, ['product_id' => 'required|integer|min:1', 'quantity' => 'required|string|max:40', 'characteristics' => 'nullable|string|max:15000'])->validate();
                $catalog = DB::table('tb_Produtos_Prodata')->where('ID', $data['product_id'])->where('Classe', $record->Categoria)->where('Inativo', 0)->lockForUpdate()->first();
                if (! $catalog) {
                    throw ValidationException::withMessages(['product_id' => 'Selecione um produto ativo da classe desta DFD.']);
                }
                $quantity = DecimalAmount::normalize($data['quantity']);
                if ($quantity === null || str_starts_with($quantity, '-') || $quantity === '0.0000') {
                    throw ValidationException::withMessages(['quantity' => 'Informe uma quantidade positiva com até quatro casas decimais.']);
                }
                $characteristics = $data['characteristics'] ?? '';
                $patch = ['ID_PROD' => $catalog->ID, 'COD_PROD' => (string) $catalog->ID_Prodata, 'PROD_SIMPLES' => $catalog->Descricao,
                    'CARACT' => $characteristics, 'PRODUTO' => $catalog->Descricao.($characteristics !== '' ? "\n".$characteristics : ''),
                    'UND' => $catalog->UND, 'QTD' => $quantity, 'updated_at' => now()];
                if ($item) {
                    DB::table('tb_Produtos_DFD')->where('ID', $itemId)->update($patch);
                } else {
                    $patch += ['ID_DFD' => $id, 'N_DFD' => $record->N_DFD, 'ITEM' => (int) DB::table('tb_Produtos_DFD')->where('ID_DFD', $id)->max('ITEM') + 1, 'created_at' => now()];
                    $itemId = DB::table('tb_Produtos_DFD')->insertGetId($patch, 'ID');
                }
            }
            DB::table('tb_DFD')->where('ID', $id)->update(['version' => $record->version + 1, 'updated_at' => now(), 'updated_by' => $actor->id]);
            $this->audit($actor, $delete ? 'dfd_product_deleted' : ($item ? 'dfd_product_updated' : 'dfd_product_created'), $id, $item ? (array) $item : null, $patch + ['item_id' => $itemId]);
        }, 3);
    }

    private function audit(User $actor, string $action, int $id, ?array $before, array $after): void
    {
        ActivityEvent::create(['actor_id' => $actor->id, 'action' => $action, 'metadata' => ['dfd_id' => $id, 'before' => $before, 'after' => $after], 'created_at' => now()]);
    }
}
