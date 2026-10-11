<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractAttachment extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['path'];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }
}
