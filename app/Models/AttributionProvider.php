<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttributionProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'status',
    ];

    public function settings()
    {
        return $this->hasMany(AttributionProviderSetting::class, 'provider_id');
    }

    public function providerEvents()
    {
        return $this->hasMany(AttributionProviderEvent::class, 'provider_id');
    }

    public function events()
    {
        return $this->belongsToMany(AttributionEvent::class, 'attribution_provider_events', 'provider_id', 'event_id')
            ->withPivot('enable', 'log')
            ->withTimestamps();
    }
}
