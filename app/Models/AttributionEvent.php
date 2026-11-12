<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttributionEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'enable',
        'log',
        'is_configurable',
    ];

    protected $casts = [
        'enable' => 'boolean',
        'log' => 'boolean',
        'is_configurable' => 'boolean',
    ];

    public function providerEvents()
    {
        return $this->hasMany(AttributionProviderEvent::class, 'event_id');
    }

    public function providers()
    {
        return $this->belongsToMany(AttributionProvider::class, 'attribution_provider_events', 'event_id', 'provider_id')
            ->withPivot('enable', 'log')
            ->withTimestamps();
    }
}
