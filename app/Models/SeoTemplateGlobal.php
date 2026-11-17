<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoTemplateGlobal extends Model
{
    protected $table = 'seo_template_global';

    protected $fillable = [
        'page_type',
        'locale',
        'variant',
        'meta_title',
        'meta_description',
        'h1',
        'intro_text',
    ];

    /**
     * Scope for a specific page type, locale, and variant
     */
    public function scopeForPage($query, string $pageType, string $locale = 'fr', string $variant = 'default')
    {
        return $query->where('page_type', $pageType)
            ->where('locale', $locale)
            ->where('variant', $variant);
    }
}
