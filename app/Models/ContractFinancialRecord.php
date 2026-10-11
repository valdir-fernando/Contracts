<?php

namespace App\Models;

use App\Services\Legacy\Normalizer;
use App\Support\FinancialFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractFinancialRecord extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public const STATUSES = ['rascunho' => 'Rascunho', 'registrado' => 'Registrado após conferência'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'event_date' => 'date', 'amount' => 'decimal:4', 'year' => 'integer', 'version' => 'integer'];
    }

    public function display(string $field, User $user): string
    {
        $value = $this->payload[$field] ?? null;
        if ($value === null || $value === '') {
            return 'Não informado';
        }
        $type = FinancialFields::MAP[$this->kind][$field][1];
        if ($type === 'money') {
            return Contract::moneyLabel($value);
        }
        if ($type === 'readonly-document' && ! $user->isAdmin() && strlen(Normalizer::document($value)) !== 14) {
            return 'Documento pessoal protegido';
        }

        return (string) $value;
    }
}
