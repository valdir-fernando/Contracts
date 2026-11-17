<?php

namespace App\Infrastructure\Atas;

use Illuminate\Support\Facades\Validator;

final class AtaValidityInput
{
    public function validate(array $input): array
    {
        $data = Validator::make($input, ['one_year_inpc' => 'sometimes|boolean',
            'start_date' => 'sometimes|nullable|date_format:Y-m-d',
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', ...(! empty($input['start_date']) && empty($input['one_year_inpc']) ? ['after_or_equal:start_date'] : [])],
            'validity_period' => 'sometimes|nullable|string|max:5000',
        ], [], ['start_date' => 'Data_Inicial', 'end_date' => 'Data_Final', 'validity_period' => 'Período da Vigência'])->validate();
        $columns = [];
        foreach (['one_year_inpc' => 'VIGENCIA_ANO_INPC', 'start_date' => 'INICIO_ATA', 'end_date' => 'FINAL_ATA', 'validity_period' => 'PERIODO_ATA'] as $field => $column) {
            if (array_key_exists($field, $data)) {
                $columns[$column] = $field === 'one_year_inpc' ? (bool) $data[$field] : $data[$field];
            }
        }
        if (($columns['VIGENCIA_ANO_INPC'] ?? false) === true) {
            $columns['PERIODO_ATA'] = '1 Ano INPC';
        }

        return $columns;
    }
}
