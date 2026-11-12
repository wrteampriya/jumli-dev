<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttributionEventLogParam extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'log_id',
        'key',
        'value',
    ];

    public function log()
    {
        return $this->belongsTo(AttributionEventLog::class, 'log_id');
    }
}
