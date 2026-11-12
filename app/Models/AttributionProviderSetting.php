<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttributionProviderSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'key',
        'value',
    ];

    public function provider()
    {
        return $this->belongsTo(AttributionProvider::class, 'provider_id');
    }
}
