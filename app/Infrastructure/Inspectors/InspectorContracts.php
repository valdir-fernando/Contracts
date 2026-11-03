<?php

namespace App\Infrastructure\Inspectors;

use App\Infrastructure\Managers\ManagerSignatory;
use App\Infrastructure\Security\CatalogAccess;
use App\Infrastructure\Security\LockActor;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class InspectorContracts
{
    public function __construct(private LockActor $actors) {}

    public function listing(User $expected, int $inspector, string $status = 'vigente'): LengthAwarePaginator
    {
        $actor = $this->actors->current($expected);
        CatalogAccess::authorize($actor, 'inspector');
        Gate::forUser($actor)->authorize('viewAny', Contract::class);
        abort_unless(in_array($status, ['vigente', 'vencido'], true), 422);
        $record = CatalogAccess::byOrganization($actor, DB::table('contract_inspectors'), 'fund_name_key', 'department_name_key')
            ->whereNull('deleted_at')->find($inspector);
        abort_unless($record, 404);

        $municipal = ManagerSignatory::isMunicipal($record->fund_name_key);
        $raw = json_decode($record->raw_payload, true, 512, JSON_THROW_ON_ERROR);
        $cpf = Normalizer::document($raw['CPF_Fiscal'] ?? '');
        $hasCpf = strlen($cpf) === 11 && Normalizer::validDocument($cpf);
        $sameName = DB::table('contract_inspectors')->whereNull('deleted_at')->where('name_key', $record->name_key)
            ->where('fund_name_key', $record->fund_name_key)->where('department_name_key', $record->department_name_key)->count() === 1;
        $cpfColumn = DB::connection()->getQueryGrammar()->wrap('cpf_fiscal');
        $normalizedCpf = "replace(replace(replace({$cpfColumn}, '.', ''), '-', ''), ' ', '')";

        return Contract::visibleTo($actor)
            ->withReportValidity($status)
            ->whereIn('fund_id', CatalogAccess::funds($actor)->where('name_key', $record->fund_name_key)->select('id'))
            ->when($municipal, function ($query) use ($actor, $record) {
                if ($record->department_name_key) {
                    $query->whereIn('department_id', CatalogAccess::departments($actor)->where('name_key', $record->department_name_key)->select('id'));
                } else {
                    $query->whereNull('department_id');
                }
            })
            ->where(function ($query) use ($hasCpf, $cpf, $normalizedCpf, $sameName, $record) {
                $query->whereRaw('1 = 0');
                if ($hasCpf) {
                    $query->orWhereRaw("{$normalizedCpf} = ?", [$cpf]);
                }
                if ($sameName) {
                    $query->orWhere(function ($query) use ($record, $normalizedCpf) {
                        $query->where('nome_fiscal', $record->name)
                            ->where(fn ($query) => $query->whereNull('cpf_fiscal')->orWhereRaw("{$normalizedCpf} = ''"));
                    });
                }
            })
            ->select(['id', 'Contrato', 'tipo', 'fornecedor_nome_snapshot', 'fundo_nome_snapshot', 'secretaria_nome_snapshot', 'objeto', 'n_aditivo', 'vigencia_fim_atual'])
            ->addSelect(['latest_amendment_number' => ContractAmendment::select('number')
                ->whereColumn('contract_id', 'contracts.id')->orderByDesc('event_date')->orderByDesc('id')->limit(1)])
            ->orderBy('Contrato')->orderBy('id')->paginate(25)->appends(['status' => $status])
            ->through(fn (Contract $contract) => [
                'number' => $contract->numero ?: 'Não informado',
                'type' => $contract->tipo ?: 'Não informado',
                'supplier' => $contract->fornecedor_nome_snapshot ?: 'Não informado',
                'fund' => $contract->fundo_nome_snapshot ?: 'Não informado',
                'department' => $municipal ? $contract->secretaria_nome_snapshot : null,
                'object' => $contract->objeto ?: 'Não informado',
                'amendment' => trim((string) ($contract->latest_amendment_number ?? $contract->n_aditivo)),
                'ends' => $contract->vigencia_fim_atual?->format('d/m/Y') ?? 'Não informado',
            ]);
    }
}
