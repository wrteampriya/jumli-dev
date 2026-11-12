<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\Lookup\LookupCategoriesRequest;
use App\Http\Requests\Admin\Lookup\LookupItemsRequest;
use App\Models\Category;
use App\Models\Item;
use App\Models\Module;
use App\Models\PopularCategory;
use App\Services\CachingService;
use App\Services\CategoryTree;
use Illuminate\Http\JsonResponse;
use App\Services\Module\ModuleService;

/**
 * Shared data sources for the admin panel's category / item dropdowns, so each form
 * doesn't grow its own "items by category" or "categories by module" route.
 * Callers and the permissions that gate them are listed on each FormRequest.
 *
 * Responses are {data: [...]}; has_more (and current_page) appear only when a
 * limit / per_page was sent.
 */
class AdminLookupController extends Controller
{
    /**
     * Approved, non-expired items, sorted by name.
     * Used by: Slider create, Banner Ad create/edit, Feature Section create (item selection).
     */
    public function items(LookupItemsRequest $request): JsonResponse
    {
        $moduleId = $request->integer('module_id') ?: null;
        $limit = $request->integer('limit') ?: null;

        $items = Item::where('status', 'approved')
            ->getNonExpiredItems()
            ->when($request->filled('category_ids'), fn ($q) => $q->whereIn('category_id', $request->input('category_ids')))
            // Items have no module_id — filter through the module's cached category ids.
            ->when($moduleId, fn ($q) => $q->whereIn('category_id', ModuleService::categoryIds($moduleId)))
            ->when($request->filled('search'), fn ($q) => $q->whereLike('name', '%'.$request->input('search').'%'))
            ->orderBy('name')
            ->when($limit, fn ($q) => $q->limit($limit + 1))
            ->get(['id', 'name']);

        if (! $limit) {
            return response()->json(['data' => $items]);
        }

        return response()->json(['data' => $items->take($limit)->values(), 'has_more' => $items->count() > $limit]);
    }

    /**
     * Active categories as a flat, depth-first list with `level` for indenting.
     * Used by: Slider create, Banner Ad create/edit, Feature Section create/edit,
     * Home Screen Sections (popular categories picker).
     */
    public function categories(LookupCategoriesRequest $request): JsonResponse
    {
        $moduleId = $request->integer('module_id') ?: null;
        $parentId = $request->has('parent_id') ? $request->integer('parent_id') : null;

        $categories = Category::where('status', 1)
            ->when($moduleId, fn ($q) => $q->forModule($moduleId))
            ->when($parentId === 0, fn ($q) => $q->whereNull('parent_category_id'))
            ->when($parentId, fn ($q) => $q->where('parent_category_id', $parentId))
            ->when($request->boolean('exclude_popular'), fn ($q) => $q->whereNotIn('id', PopularCategory::select('category_id')))
            ->when($request->filled('search'), fn ($q) => $q->whereLike('name', '%'.$request->input('search').'%'))
            ->orderBy('sequence')
            ->get(['id', 'name', 'parent_category_id', 'module_id']);

        // A single level needs no tree walk; everything is level 0.
        $list = $parentId === null
            ? CategoryTree::flatten($categories)
            : $categories->each(fn ($category) => $category->setAttribute('level', 0));

        $data = $list->map(fn ($category) => [
            'id' => $category->id,
            'name' => $category->name,
            'level' => $category->level,
            'parent_category_id' => $category->parent_category_id,
            'module_id' => $category->module_id,
        ])->values();

        if (! $request->filled('per_page')) {
            return response()->json(['data' => $data]);
        }

        $page = $request->integer('page') ?: 1;
        $perPage = $request->integer('per_page');

        return response()->json([
            'data' => $data->forPage($page, $perPage)->values(),
            'has_more' => $data->count() > $page * $perPage,
            'current_page' => $page,
        ]);
    }
}
