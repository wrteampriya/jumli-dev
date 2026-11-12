<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PopularCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'sequence',
        'status',
    ];

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    // Popular categories have no module_id of their own — they belong to
    // their category's module. No module = no filter.
    public function scopeForModule($query, ?int $moduleId = null)
    {
        if (!$moduleId) {
            return $query;
        }

        return $query->whereHas('category', fn ($q) => $q->forModule($moduleId));
    }
}
