<?php

namespace App\Services;

use App\Models\Contract;
use App\Services\Legacy\Normalizer;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContractSearch
{
    public static function rules(array $input): array
    {
        $money = ['nullable', 'string', 'max:40', function ($attribute, $value, $fail) {
            if (Normalizer::money($value) === null) {
                $fail('Informe um valor válido, com até quatro casas decimais.');
            }
        }];

        return [
            'object' => 'nullable|string|max:255', 'commitment' => 'nullable|string|max:255', 'budget' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:255', 'modality' => 'nullable|string|max:255',
            'status' => ['nullable', Rule::in(array_keys(Contract::STATUSES))],
            'year' => 'nullable|integer|min:1|max:9999',
            'date_field' => ['nullable', Rule::in(['data_contrato', 'vigencia_inicio', 'end'])],
            'from' => 'nullable|date_format:Y-m-d|after_or_equal:1000-01-01',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:1000-01-01'.(! empty($input['from']) ? '|after_or_equal:from' : ''),
            'min_value' => $money, 'max_value' => $money,
        ];
    }

    public static function apply(Builder $query, array $filters): void
    {
        foreach (['object' => 'objeto', 'commitment' => 'empenho', 'budget' => 'ficha'] as $key => $column) {
            if (trim($filters[$key] ?? '') !== '') {
                $query->whereLike($column, '%'.trim($filters[$key]).'%');
            }
        }
        foreach (['type' => 'tipo', 'modality' => 'modalidade', 'status' => 'administrative_status', 'year' => 'exercicio'] as $key => $column) {
            if ($filters[$key] ?? null) {
                $query->where($column, $filters[$key]);
            }
        }
        $column = ($filters['date_field'] ?? 'data_contrato') === 'end'
            ? DB::raw('COALESCE(vigencia_fim_atual, vigencia_fim_original)') : ($filters['date_field'] ?? 'data_contrato');
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if ($filters[$key] ?? null) {
                $query->whereDate($column, $operator, $filters[$key]);
            }
        }
        $min = Normalizer::money($filters['min_value'] ?? null);
        $max = Normalizer::money($filters['max_value'] ?? null);
        if ($min !== null && $max !== null && BigDecimal::of($min)->isGreaterThan(BigDecimal::of($max))) {
            throw ValidationException::withMessages(['max_value' => 'O valor máximo deve ser maior ou igual ao mínimo.']);
        }
        foreach ([$min, $max] as $index => $amount) {
            if ($amount !== null) {
                $query->where('valor_total', $index === 0 ? '>=' : '<=', $amount);
            }
        }
    }
}
