<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Turns a set of categories into a depth-first flat list (parent, then its children),
 * each tagged with a `level` attribute for indenting in a dropdown.
 *
 * Input order is kept within each level, so pass the categories already sorted (e.g. by sequence).
 * A category whose parent isn't in the set is treated as a root — that keeps filtered sets
 * (one module, popular categories excluded, a search) rendering instead of silently dropping rows.
 */
class CategoryTree
{
    public static function flatten(Collection $categories): Collection
    {
        $ids = $categories->pluck('id')->flip();
        $byParent = $categories->groupBy(fn ($category) => isset($ids[$category->parent_category_id]) ? $category->parent_category_id : 0);

        $flat = collect();
        $walk = function ($parentId, int $level) use (&$walk, $byParent, $flat) {
            foreach ($byParent->get($parentId, []) as $category) {
                $category->setAttribute('level', $level);
                $flat->push($category);
                $walk($category->id, $level + 1);
            }
        };
        $walk(0, 0);

        return $flat;
    }
}
