<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoMetaEntity extends Model
{
    protected $table = 'seo_meta_entity';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'locale',
        'variant',
        'meta_title',
        'meta_description',
        'h1',
        'intro_text',
    ];

    /**
     * Scope for a specific entity, locale, and variant
     */
    public function scopeForEntity($query, string $entityType, int $entityId, string $locale = 'fr', string $variant = 'default')
    {
        return $query->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('locale', $locale)
            ->where('variant', $variant);
    }
}
