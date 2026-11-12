<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttributionProviderEvent extends Model
{
    use HasFactory;

    protected $table = 'attribution_provider_events';

    protected $fillable = [
        'provider_id',
        'event_id',
        'enable',
        'log',
    ];

    protected $casts = [
        'enable' => 'boolean',
        'log' => 'boolean',
    ];

    public function provider()
    {
        return $this->belongsTo(AttributionProvider::class, 'provider_id');
    }

    public function event()
    {
        return $this->belongsTo(AttributionEvent::class, 'event_id');
    }
}
