<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessScope extends Model
{
    public $timestamps = false;

    protected $fillable = ['scope_key', 'fund', 'department', 'fund_id', 'department_id'];

    public function getLabelAttribute(): string
    {
        return $this->fund.($this->department ? ' / '.$this->department : '');
    }
}
