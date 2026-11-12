<?php

namespace App\Models;

use App\Models\Concerns\BelongsToModule;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HomeScreenSection extends Model
{
    use BelongsToModule, HasFactory;

    protected $fillable = [
        'module_id',
        'section_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // module_id hidden from serialization — see Category.php's $hidden for why.
    protected $hidden = ['module_id'];

    // The app's home screen sections and their fixed position (all_ads always last) —
    // every module gets one row per type (unique on module_id + section_type).
    public const DEFAULTS = [
        'all_categories' => 1,
        'slider' => 2,
        'popular_categories' => 3,
        'featured_section' => 4,
        'all_ads' => 5,
    ];

    // Creates the module's missing section rows, active;
    // existing rows (and their admin-set visibility) are left untouched.
    public static function ensureForModule(int $moduleId): void
    {
        $existing = static::where('module_id', $moduleId)->pluck('section_type')->all();

        foreach (array_keys(array_diff_key(static::DEFAULTS, array_flip($existing))) as $type) {
            static::create([
                'module_id' => $moduleId,
                'section_type' => $type,
                'is_active' => true,
            ]);
        }
    }

    // Home screen order: the fixed DEFAULTS positions (not admin-reorderable).
    public function scopeOrdered($query)
    {
        $cases = collect(static::DEFAULTS)->map(fn (int $position) => "WHEN ? THEN {$position}")->implode(' ');

        return $query->orderByRaw("CASE section_type {$cases} ELSE " . (count(static::DEFAULTS) + 1) . ' END', array_keys(static::DEFAULTS));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
