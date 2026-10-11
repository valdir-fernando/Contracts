<?php

namespace App\Services\Reports;

use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractHistoryRecord;
use App\Models\User;
use App\Services\ContractSearch;
use App\Services\Legacy\Normalizer;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ContractReport
{
    public const TYPES = ['general' => 'Relação geral', 'expiring' => 'A vencer', 'expired' => 'Vencidos', 'fund' => 'Por fundo',
        'department' => 'Por secretaria', 'supplier' => 'Por fornecedor', 'type' => 'Por tipo', 'modality' => 'Por modalidade',
        'changes' => 'Vigências e alterações', 'quality' => 'Qualidade dos dados'];

    public const HEADERS = ['Contrato', 'Processo', 'Fornecedor', 'Documento', 'Fundo', 'Secretaria', 'Tipo', 'Modalidade',
        'Valor inicial (decimal)', 'Assinatura', 'Início', 'Término informado', 'Situação administrativa', 'Vigência', 'Pendências', 'Dias até o término', 'Origem do término'];

    public function filters(array $input): array
    {
        return Validator::make($input, [
            'report' => ['nullable', Rule::in(array_keys(self::TYPES))], 'q' => 'nullable|string|max:255',
            'fund' => 'nullable|integer|min:1', 'department' => 'nullable|integer|min:1',
            'type' => 'nullable|string|max:255', 'modality' => 'nullable|string|max:255',
            'status' => ['nullable', Rule::in(array_keys(Contract::STATUSES))],
            'validity' => ['nullable', Rule::in(array_keys(Contract::VALIDITY))],
            'reference' => 'nullable|date_format:Y-m-d|after_or_equal:1000-01-01',
            'days' => ['nullable', Rule::in([30, 60, 90, 365])],
            'sort' => ['nullable', Rule::in(['numero', 'data_contrato', 'valor_total', 'exercicio'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'include_personal' => 'nullable|boolean',
            'reason' => 'required_if:include_personal,1|nullable|string|min:10|max:1000',
        ] + ContractSearch::rules($input))->validate();
    }

    public function query(User $actor, array $filters)
    {
        Gate::forUser($actor)->authorize('viewAny', Contract::class);
        abort_if(! empty($filters['include_personal']) && ! $actor->isAdmin(), 403);
        $query = Contract::visibleTo($actor);
        ContractSearch::apply($query, $filters);
        if ($search = trim($filters['q'] ?? '')) {
            $query->where(function ($q) use ($search) {
                foreach (['numero', 'processo', 'fornecedor_nome_snapshot', 'fornecedor_documento_snapshot'] as $column) {
                    $q->orWhereLike($column, '%'.$search.'%');
                }
                $document = Normalizer::document($search);
                if (preg_match('/^[\d\s.\/\-]+$/', $search) && strlen($document) >= 4) {
                    $q->orWhereLike('supplier_document_key', '%'.$document.'%');
                }
            });
        }
        foreach (['fund' => 'fund_id', 'department' => 'department_id'] as $key => $column) {
            if ($filters[$key] ?? null) {
                $query->where($column, $filters[$key]);
            }
        }
        $reference = $filters['reference'] ?? today()->toDateString();
        $end = 'COALESCE(vigencia_fim_atual, vigencia_fim_original)';
        $report = $filters['report'] ?? 'general';
        if (in_array($report, ['expiring', 'expired'])) {
            $query->where('vigencia_em_revisao', false)->whereNotNull('vigencia_inicio');
            if ($report === 'expired') {
                $query->whereRaw($end.' < ?', [$reference]);
            } else {
                $query->whereDate(DB::raw($end), '>=', $reference)->whereDate(DB::raw($end), '<=', CarbonImmutable::parse($reference)->addDays((int) ($filters['days'] ?? 30))->toDateString());
            }
        }
        if ($report === 'quality') {
            $query->where(fn ($q) => $q->where('ownership_resolved', false)->orWhere('vigencia_em_revisao', true)->orWhereJsonLength('quality_issues', '>', 0));
        }
        if ($report === 'changes') {
            $query->where(fn ($q) => $q->where('vigencia_em_revisao', true)->orWhereNotNull('vigencia_fim_atual')->orWhereIn('id', ContractHistoryRecord::where('kind', 'amendment')->select('contract_id'))
                ->orWhereIn('id', ContractAmendment::where('status', 'registrado')->select('contract_id')));
        }
        if ($validity = $filters['validity'] ?? null) {
            $query->where('vigencia_em_revisao', $validity === 'em_revisao');
            if ($validity === 'indeterminado') {
                $query->where(fn ($q) => $q->whereNull('vigencia_inicio')->orWhereRaw($end.' IS NULL'));
            } elseif ($validity !== 'em_revisao') {
                $query->whereNotNull('vigencia_inicio')->whereRaw($end.($validity === 'vencido' ? ' < ?' : ' >= ?'), [$reference]);
                if ($validity !== 'vencido') {
                    $query->whereDate('vigencia_inicio', $validity === 'a_iniciar' ? '>' : '<=', $reference);
                }
            }
        }

        return $query->orderBy($filters['sort'] ?? 'numero', $filters['direction'] ?? 'asc')->orderBy('id');
    }

    public function summary(User $actor, array $filters): array
    {
        $total = BigDecimal::of('0.0000');
        $groups = [];
        $count = 0;
        $missing = 0;
        $report = $filters['report'] ?? 'general';
        foreach ($this->query($actor, $filters)->cursor() as $contract) {
            $count++;
            $amount = BigDecimal::of($contract->valor_total ?? '0')->toScale(4);
            $total = $total->plus($amount);
            $missing += $contract->valor_total === null ? 1 : 0;
            $key = match ($report) {
                'fund' => 'fund:'.($contract->fund_id ?? 'unknown:'.$contract->fundo_nome_snapshot),
                'department' => 'department:'.($contract->department_id ?? 'unknown:'.$contract->secretaria_nome_snapshot),
                'supplier' => 'supplier:'.(Normalizer::validDocument($contract->supplier_document_key ?? '') ? $contract->supplier_document_key : 'unknown:'.$contract->id),
                'type' => Normalizer::name($contract->tipo),
                'modality' => Normalizer::name($contract->modalidade),
                default => '',
            };
            if ($key !== '') {
                $label = match ($report) {
                    'fund' => $contract->fundo_nome_snapshot, 'department' => $contract->secretaria_nome_snapshot,
                    'supplier' => $contract->fornecedor_nome_snapshot.' · '.$this->document($contract, $actor, $filters),
                    'type' => $key, 'modality' => $key,
                };
                $groups[$key] ??= ['label' => $label ?: 'Não informado', 'count' => 0, 'total' => '0.0000'];
                $groups[$key]['count']++;
                $groups[$key]['total'] = (string) BigDecimal::of($groups[$key]['total'])->plus($amount);
            }
        }

        return ['count' => $count, 'total' => (string) $total, 'missing' => $missing, 'groups' => array_values($groups)];
    }

    private function document(Contract $contract, User $actor, array $filters): string
    {
        $value = $contract->fornecedor_documento_snapshot;
        if (! $value) {
            return 'Não informado';
        }
        if (! empty($filters['include_personal']) || strlen(Normalizer::document($value)) === 14) {
            return $contract->displayField('CPF_CNPJ', $actor);
        }

        return Normalizer::mask($value);
    }

    public function row(Contract $contract, User $actor, array $filters): array
    {
        $reference = CarbonImmutable::parse($filters['reference'] ?? today()->toDateString());
        $validity = $contract->vigencia_em_revisao ? 'em_revisao' : (! $contract->end_date || ! $contract->vigencia_inicio ? 'indeterminado'
            : ($contract->end_date->lt($reference) ? 'vencido' : ($contract->vigencia_inicio->gt($reference) ? 'a_iniciar' : 'vigente')));

        return [$contract->numero, $contract->processo, $contract->fornecedor_nome_snapshot,
            $this->document($contract, $actor, $filters), $contract->fundo_nome_snapshot, $contract->secretaria_nome_snapshot,
            $contract->tipo, $contract->modalidade, $contract->valor_total, $contract->data_contrato?->toDateString(),
            $contract->vigencia_inicio?->toDateString(), $contract->end_date?->toDateString(),
            Contract::STATUSES[$contract->administrative_status] ?? 'Não classificada', Contract::VALIDITY[$validity],
            count($contract->quality_issues ?? []) + (int) ! $contract->ownership_resolved + (int) $contract->vigencia_em_revisao,
            ! $contract->vigencia_em_revisao && $contract->end_date ? (string) (int) $reference->diffInDays($contract->end_date, false) : 'Em revisão / indeterminado',
            $contract->vigencia_fim_atual ? 'Término atualizado informado; sujeito a conciliação' : ($contract->vigencia_fim_original ? 'Término original' : 'Não informado')];
    }
}
