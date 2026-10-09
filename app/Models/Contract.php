<?php

namespace App\Models;

use App\Services\Legacy\Normalizer;
use App\Support\ContractFields;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['legacy_record_id'];

    protected function casts(): array
    {
        $casts = ['quality_issues' => 'array', 'ownership_resolved' => 'boolean', 'vigencia_em_revisao' => 'boolean'];
        foreach (ContractFields::MAP as $definition) {
            if ($definition['type'] === 'date') {
                $casts[$definition['column']] = 'date';
            }
            if ($definition['type'] === 'money') {
                $casts[$definition['column']] = 'decimal:4';
            }
        }

        return $casts;
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->is_active || $user->trashed()) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->isAdmin()) {
            return $query;
        }
        $scopes = $user->scopes()->whereNotNull('fund_id')->get();

        return $query->where('ownership_resolved', true)->where(function (Builder $query) use ($scopes) {
            $query->whereRaw('1 = 0');
            foreach ($scopes as $scope) {
                // An unknown, non-empty department must never become fund-wide access.
                if ($scope->department && ! $scope->department_id) {
                    continue;
                }
                $query->orWhere(function (Builder $query) use ($scope) {
                    $query->where('fund_id', $scope->fund_id);
                    if ($scope->department_id) {
                        $query->where('department_id', $scope->department_id);
                    }
                });
            }
        });
    }

    public function getEndDateAttribute()
    {
        return $this->vigencia_fim_atual ?? $this->vigencia_fim_original;
    }

    public function getValidityAttribute(): string
    {
        if ($this->vigencia_em_revisao) {
            return 'em_revisao';
        }
        if (! $this->end_date || ! $this->vigencia_inicio) {
            return 'indeterminado';
        }
        if ($this->end_date->lt(today())) {
            return 'vencido';
        }

        return $this->vigencia_inicio->gt(today()) ? 'a_iniciar' : 'vigente';
    }

    public const VALIDITY = ['a_iniciar' => 'A iniciar', 'vigente' => 'Vigente', 'vencido' => 'Vencido', 'indeterminado' => 'Indeterminado', 'em_revisao' => 'Em revisão'];

    public function scopeWithValidity(Builder $query, string $status): Builder
    {
        if ($status === 'em_revisao') {
            return $query->where('vigencia_em_revisao', true);
        }
        $query->where('vigencia_em_revisao', false);
        if ($status === 'indeterminado') {
            return $query->where(fn ($q) => $q->whereNull('vigencia_inicio')->orWhere(fn ($q) => $q->whereNull('vigencia_fim_atual')->whereNull('vigencia_fim_original')));
        }
        $query->whereNotNull('vigencia_inicio');
        $end = 'COALESCE(vigencia_fim_atual, vigencia_fim_original)';
        if ($status === 'vencido') {
            return $query->whereRaw($end.' < ?', [today()->toDateString()]);
        }
        $query->whereRaw($end.' >= ?', [today()->toDateString()]);

        return $query->whereDate('vigencia_inicio', $status === 'a_iniciar' ? '>' : '<=', today());
    }

    public static function moneyLabel(?string $value): string
    {
        if ($value === null) {
            return 'Não informado';
        }
        $rounded = (string) BigDecimal::of($value)->toScale(2, RoundingMode::HALF_UP);
        [$integer, $decimal] = explode('.', $rounded);
        $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer);

        return 'R$ '.$integer.','.$decimal;
    }

    public function displayField(string $source, User $user): string
    {
        $definition = ContractFields::MAP[$source];
        $value = $this->{$definition['column']};
        if ($value === null || $value === '') {
            return 'Não informado';
        }
        if ($definition['type'] === 'date') {
            return $value->format('d/m/Y');
        }
        if ($definition['type'] === 'money') {
            return self::moneyLabel($value);
        }
        $sensitive = in_array($source, ['RG_Fornec', 'PIS_PASEP', 'CPF_Socio', 'RG_Socio', 'CPF_Gestor', 'RG_Gestor', 'CPF_Fiscal', 'RG_Fiscal']);
        if (! $user->isAdmin() && ($sensitive || ($source === 'CPF_CNPJ' && strlen(Normalizer::document($value)) !== 14))) {
            return Normalizer::mask($value);
        }

        return (string) $value;
    }
}
