<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractHistoryRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['candidate_ids' => 'array', 'quality_issues' => 'array', 'event_date' => 'date', 'end_date' => 'date', 'amount' => 'decimal:4'];
    }

    public function source()
    {
        return $this->belongsTo(LegacyRecord::class, 'legacy_record_id');
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class)->withTrashed();
    }
}
