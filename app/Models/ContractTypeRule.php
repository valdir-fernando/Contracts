<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractTypeRule extends Model
{
    public const FIELDS = ['end_imovel' => 'Endereço do imóvel', 'dados_veiculo' => 'Dados do veículo',
        'tipo_veiculo' => 'Tipo do veículo', 'cargo_credenc' => 'Cargo de credenciamento',
        'local_servico' => 'Local de serviço', 'tipo_obra' => 'Tipo de obra', 'prazo_obra' => 'Prazo da obra'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['required_fields' => 'array'];
    }
}
