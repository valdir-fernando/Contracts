<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyRecord extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['raw_payload' => 'array'];
    }
}
