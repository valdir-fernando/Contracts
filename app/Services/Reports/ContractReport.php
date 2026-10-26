<?php

namespace App\Services\Reports;

use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractHistoryRecord;
use App\Models\User;
use App\Services\ContractSearch;
use App\Services\Legacy\Normalizer;
use App\Support\BrazilianLocale;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ContractReport
{
    public const TYPES = ['general' => 'Relação geral', 'expiring' => 'A vencer', 'expired' => 'Vencidos', 'fund' => 'Por fundo',
        'inspector' => 'Por fiscal', 'department' => 'Por secretaria', 'supplier' => 'Por fornecedor', 'type' => 'Por tipo', 'modality' => 'Por modalidade',
        'changes' => 'Vigências e alterações', 'quality' => 'Qualidade dos dados'];

    public const HEADERS = ['Contrato', 'Processo', 'Fornecedor', 'Documento', 'Fundo', 'Secretaria', 'Tipo', 'Modalidade',
        'Valor atualizado (decimal)', 'Assinatura', 'Início', 'Término atualizado', 'Situação administrativa', 'Vigência', 'Pendências', 'Dias até o término', 'Origem do término', 'Fiscal'];

    public const COLUMNS = ['number', 'process', 'supplier', 'document', 'fund', 'department', 'type', 'modality',
        'initial_amount', 'signed_at', 'starts_at', 'ends_at', 'administrative_status', 'validity', 'issues', 'remaining_days', 'end_source', 'inspector'];

    public function headers(array $filters): array
    {
        return array_map(fn ($key) => self::HEADERS[array_search($key, self::COLUMNS, true)], $filters['columns'] ?? self::COLUMNS);
    }

    public function filters(array $input): array
    {
        return Validator::make($input, [
            'report' => ['nullable', Rule::in(array_keys(self::TYPES))], 'q' => 'nullable|string|max:255',
            'inspector' => 'nullable|string|max:255',
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
            'columns' => 'sometimes|array|min:1|max:18', 'columns.*' => ['required', 'string', 'distinct', Rule::in(self::COLUMNS)],
        ] + ContractSearch::rules($input))->validate();
    }

    public function query(User $actor, array $filters)
    {
        Gate::forUser($actor)->authorize('viewAny', Contract::class);
        abort_if(! empty($filters['include_personal']) && ! $actor->isAdmin(), 403);
        $query = Contract::visibleTo($actor);
        if (($filters['inspector'] ?? '') !== '') {
            $query->where('nome_fiscal', $filters['inspector']);
        }
        ContractSearch::apply($query, $filters);
        ContractSearch::applyIdentityFilters($query, $filters);
        $reference = $filters['reference'] ?? today()->toDateString();
        $report = $filters['report'] ?? 'general';
        if (in_array($report, ['expiring', 'expired'])) {
            if ($report === 'expired') {
                $query->withReportValidity('vencido', CarbonImmutable::parse($reference));
            } else {
                $from = CarbonImmutable::parse($reference);
                $query->reportEndingBetween($from, $from->addDays((int) ($filters['days'] ?? 30)));
            }
        }
        if ($report === 'quality') {
            $query->where(fn ($q) => $q->where('ownership_resolved', false)->orWhereJsonLength('quality_issues', '>', 0));
        }
        if ($report === 'changes') {
            $query->where(fn ($q) => $q->whereNotNull('vigencia_fim_atual')->orWhereIn('id', ContractHistoryRecord::where('kind', 'amendment')->select('contract_id'))
                ->orWhereIn('id', ContractAmendment::where('status', 'registrado')->select('contract_id')));
        }
        if ($validity = $filters['validity'] ?? null) {
            $query->withReportValidity($validity, CarbonImmutable::parse($reference));
        }

        return $query->orderBy(match ($filters['sort'] ?? 'numero') {
            'numero' => 'Contrato', 'valor_total' => 'valor_acumulado', default => $filters['sort']
        }, $filters['direction'] ?? 'asc')->orderBy('id');
    }

    public function summary(User $actor, array $filters): array
    {
        return $this->summarize($this->reportQuery($actor, $filters)->cursor(), $actor, $filters);
    }

    private function reportQuery(User $actor, array $filters)
    {
        // No personal fields, object text or source JSON outside the report projection.
        return $this->query($actor, $filters)->select(['id', 'Contrato', 'processo', 'fornecedor_nome_snapshot',
            'fornecedor_documento_snapshot', 'supplier_document_key', 'fund_id', 'department_id', 'fundo_nome_snapshot',
            'secretaria_nome_snapshot', 'tipo', 'modalidade', 'valor_acumulado', 'data_contrato', 'vigencia_inicio',
            'vigencia_fim_original', 'vigencia_fim_atual', 'administrative_status', 'quality_issues',
            'ownership_resolved', 'vigencia_em_revisao', 'nome_fiscal']);
    }

    public function snapshot(User $actor, array $filters, ReportRows $rows, bool $pdf): array
    {
        $reference = CarbonImmutable::parse($filters['reference'] ?? today()->toDateString());
        $contracts = (function () use ($actor, $filters, $rows, $pdf, $reference) {
            foreach ($this->reportQuery($actor, $filters)->cursor() as $contract) {
                $rows->append(BrazilianLocale::reportRow($this->row($contract, $actor, $filters, $reference), $filters, $pdf));
                yield $contract;
            }
        })();

        return $this->summarize($contracts, $actor, $filters);
    }

    private function summarize(iterable $contracts, User $actor, array $filters): array
    {
        $total = BigDecimal::of('0.0000');
        $groups = [];
        $count = 0;
        $missing = 0;
        $report = $filters['report'] ?? 'general';
        foreach ($contracts as $contract) {
            $count++;
            $amount = BigDecimal::of($contract->valor_acumulado ?? '0')->toScale(4);
            $total = $total->plus($amount);
            $missing += $contract->valor_acumulado === null ? 1 : 0;
            $key = match ($report) {
                'inspector' => 'inspector:'.($contract->nome_fiscal ?? ''),
                'fund' => 'fund:'.($contract->fund_id ?? 'unknown:'.$contract->fundo_nome_snapshot),
                'department' => 'department:'.($contract->department_id ?? 'unknown:'.$contract->secretaria_nome_snapshot),
                'supplier' => 'supplier:'.(Normalizer::validDocument($contract->supplier_document_key ?? '') ? $contract->supplier_document_key : 'unknown:'.$contract->id),
                'type' => 'type:'.Normalizer::name($contract->tipo),
                'modality' => 'modality:'.Normalizer::name($contract->modalidade),
                default => '',
            };
            if ($key !== '') {
                $label = match ($report) {
                    'inspector' => $contract->nome_fiscal,
                    'fund' => $contract->fundo_nome_snapshot, 'department' => $contract->secretaria_nome_snapshot,
                    'supplier' => $contract->fornecedor_nome_snapshot.' · '.$this->document($contract, $actor, $filters),
                    'type' => Normalizer::name($contract->tipo), 'modality' => Normalizer::name($contract->modalidade),
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

    public function row(Contract $contract, User $actor, array $filters, ?CarbonImmutable $reference = null): array
    {
        $reference ??= CarbonImmutable::parse($filters['reference'] ?? today()->toDateString());
        $start = $contract->vigencia_inicio;
        $end = $contract->vigencia_fim_atual;
        $validity = $contract->reportValidityOn($reference);

        $values = [$contract->numero, $contract->processo, $contract->fornecedor_nome_snapshot,
            $this->document($contract, $actor, $filters), $contract->fundo_nome_snapshot, $contract->secretaria_nome_snapshot,
            $contract->tipo, $contract->modalidade, $contract->valor_acumulado, $contract->data_contrato?->toDateString(),
            $start?->toDateString(), $end?->toDateString(),
            Contract::STATUSES[$contract->administrative_status] ?? 'Não classificada', Contract::VALIDITY[$validity],
            count($contract->quality_issues ?? []) + (int) ! $contract->ownership_resolved,
            $end ? (string) (int) $reference->diffInDays($end, false) : 'Não informado',
            $end ? 'Término atualizado' : 'Não informado', $contract->nome_fiscal];

        return array_map(fn ($key) => $values[array_search($key, self::COLUMNS, true)], $filters['columns'] ?? self::COLUMNS);
    }
}
