<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttributionEventLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_key',
        'provider',
        'user_id',
        'status',
        'message',
    ];

    public function params()
    {
        return $this->hasMany(AttributionEventLogParam::class, 'log_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
