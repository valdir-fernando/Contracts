<?php

namespace App\Models;

use App\Services\Legacy\Normalizer;
use App\Support\AmendmentFields;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractAmendment extends Model
{
    use SoftDeletes;

    public const STATUSES = ['rascunho' => 'Rascunho', 'registrado' => 'Registrado após conferência', 'importado' => 'Importado do legado'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'event_date' => 'date', 'amount' => 'decimal:4', 'version' => 'integer'];
    }

    public function display(string $field, User $user): string
    {
        $value = $this->payload[$field] ?? null;
        if ($value === null || $value === '') {
            return 'Não informado';
        }
        if (! $user->isAdmin() && (in_array($field, AmendmentFields::PERSONAL)
            || $field === 'CNPJ_Fornec' && strlen(Normalizer::document($value)) !== 14)) {
            return Normalizer::mask($value);
        }
        $type = AmendmentFields::MAP[$field]['type'];

        return match ($type) {
            'money' => Contract::moneyLabel($value),
            'date' => Carbon::parse($value)->format('d/m/Y'),
            default => (string) $value,
        };
    }
}
