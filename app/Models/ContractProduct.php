<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractProduct extends Model
{
    use SoftDeletes;

    protected $table = 'tb_Produtos_Contratos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'total' => 'decimal:4', 'process_catalog' => 'boolean'];
    }
}
