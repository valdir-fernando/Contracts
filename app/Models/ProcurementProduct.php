<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcurementProduct extends Model
{
    protected $table = 'tb_Produtos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'remaining' => 'decimal:4', 'unit_price' => 'decimal:4'];
    }
}
