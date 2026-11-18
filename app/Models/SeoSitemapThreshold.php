<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSitemapThreshold extends Model
{
    protected $table = 'seo_sitemap_thresholds';

    protected $fillable = [
        'depth',
        'name',
        'min_listings',
        'is_enabled',
    ];

    protected $casts = [
        'depth' => 'integer',
        'min_listings' => 'integer',
        'is_enabled' => 'boolean',
    ];

    /**
     * Default thresholds specified in WP5
     */
    public static function defaultThresholds(): array
    {
        return [
            ['depth' => 0, 'name' => 'Country Page', 'min_listings' => 1, 'is_enabled' => true],
            ['depth' => 1, 'name' => 'City or Root Category Page', 'min_listings' => 3, 'is_enabled' => true],
            ['depth' => 2, 'name' => 'Combination or Subcategory Page', 'min_listings' => 5, 'is_enabled' => true],
            ['depth' => 3, 'name' => 'Deep Sub-subcategory Combination', 'min_listings' => 10, 'is_enabled' => true],
        ];
    }

    /**
     * Seed or ensure default thresholds exist
     */
    public static function seedDefaults(): void
    {
        foreach (self::defaultThresholds() as $item) {
            self::firstOrCreate(['depth' => $item['depth']], $item);
        }
    }

    /**
     * Get minimum listings required for a specific depth
     */
    public static function getThresholdForDepth(int $depth): int
    {
        $threshold = self::where('depth', $depth)->where('is_enabled', true)->value('min_listings');
        if ($threshold !== null) {
            return (int)$threshold;
        }

        // Safe fallbacks if not configured
        return match ($depth) {
            0 => 1,
            1 => 3,
            2 => 5,
            default => 10,
        };
    }
}
