<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportRun extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
