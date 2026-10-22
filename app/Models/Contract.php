<?php

namespace App\Models;

use App\Domain\Contracts\ContractValidity;
use App\Infrastructure\Contracts\OriginalContractProducts;
use App\Services\Legacy\Normalizer;
use App\Support\ContractFields;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use SoftDeletes;

    public const STATUSES = ['rascunho' => 'Rascunho', 'ativo' => 'Ativo', 'suspenso' => 'Suspenso', 'rescindido' => 'Rescindido', 'arquivado' => 'Arquivado'];

    public const SIGNED_DOCUMENT_COLUMNS = ['Contrato_PDF', 'Aditivo_PDF', 'OS_PDF', 'Concordancia_PDF', 'Distrato_PDF', 'ATA_PDF'];

    protected $guarded = ['id', ...self::SIGNED_DOCUMENT_COLUMNS];

    protected $hidden = ['legacy_record_id', ...self::SIGNED_DOCUMENT_COLUMNS];

    /** Preserve the application's public field name while storing it in contracts.Contrato. */
    public function getNumeroAttribute($value): ?string
    {
        return $this->attributes['Contrato'] ?? $value;
    }

    public function setNumeroAttribute($value): void
    {
        $this->attributes['Contrato'] = $value;
    }

    protected function casts(): array
    {
        $casts = ['quality_issues' => 'array', 'ownership_resolved' => 'boolean', 'vigencia_em_revisao' => 'boolean',
            'number_year' => 'integer', 'number_sequence' => 'integer'];
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

    public function productFichas(): array
    {
        if (! $this->exists) {
            return [];
        }
        $fichas = (new OriginalContractProducts)->forContract($this)->pluck('Ficha')
            ->map(fn ($ficha) => trim((string) $ficha))->filter(fn ($ficha) => $ficha !== '')->uniqueStrict()->values()->all();
        sort($fichas, SORT_NATURAL);

        return $fichas;
    }

    public function getEndDateAttribute()
    {
        return $this->vigencia_fim_atual ?? $this->vigencia_fim_original;
    }

    public function getValidityAttribute(): string
    {
        return $this->validityOn(today());
    }

    public function validityOn(DateTimeInterface $reference): string
    {
        return ContractValidity::classify((bool) $this->vigencia_em_revisao, $this->vigencia_inicio, $this->end_date, $reference);
    }

    public const VALIDITY = ['a_iniciar' => 'A iniciar', 'vigente' => 'Vigente', 'vencido' => 'Vencido', 'indeterminado' => 'Indeterminado', 'em_revisao' => 'Em revisão'];

    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where(fn (Builder $pending) => $pending->where('vigencia_em_revisao', true)->orWhere('ownership_resolved', false));
    }

    /** Inclusive civil-date window, also used by reports and dashboard alerts. */
    public function scopeEndingBetween(Builder $query, DateTimeInterface $from, DateTimeInterface $to): Builder
    {
        return $query->where('vigencia_em_revisao', false)->whereNotNull('vigencia_inicio')
            ->whereRaw('COALESCE(vigencia_fim_atual, vigencia_fim_original) >= ? AND COALESCE(vigencia_fim_atual, vigencia_fim_original) < ?', [$from->format('Y-m-d'), DateTimeImmutable::createFromInterface($to)->modify('+1 day')->format('Y-m-d')]);
    }

    public function scopeWithValidity(Builder $query, string $status, ?DateTimeInterface $reference = null): Builder
    {
        $referenceDate = ($reference ?? today())->format('Y-m-d');
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
            return $query->whereRaw($end.' < ?', [$referenceDate]);
        }
        $query->whereRaw($end.' >= ?', [$referenceDate]);

        return $query->whereDate('vigencia_inicio', $status === 'a_iniciar' ? '>' : '<=', $referenceDate);
    }

    public static function moneyLabel(?string $value): string
    {
        if ($value === null) {
            return 'Não informado';
        }
        $rounded = (string) BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp);
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
