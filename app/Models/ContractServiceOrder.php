<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractServiceOrder extends Model
{
    use SoftDeletes;

    protected $table = 'tb_OS';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'starts_on' => 'date', 'ends_on' => 'date'];
    }
}
