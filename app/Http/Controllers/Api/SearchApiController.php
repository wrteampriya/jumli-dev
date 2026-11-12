<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ItemApiResource;
use App\Models\Category;
use App\Models\Item;
use App\Models\Module;
use App\Models\User;
use App\Services\CachingService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;
use App\Services\Module\ModuleService;

/** @tags Search */
class SearchApiController extends BaseApiController
{
    /**
     * Global Search
     *
     * Searches ads, sellers and categories in one call. Ads and categories are scoped
     * to the X-Module header's module when sent, otherwise to every active module;
     * sellers are general and always cover every active module. Each section
     * returns its first `limit` matches plus the total, for a "view all" link.
     */
    public function globalSearch(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search' => 'required|string|min:2|max:100',
            /** Results per section, 1–20 (default 5) */
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $search = trim($request->search);
            $limit = (int) ($request->limit ?? 5);
            $categoryIds = $this->searchableCategoryIds($this->currentModuleId());

            ResponseService::successResponse(__('Data Fetched Successfully'), [
                'search' => $search,
                'ads' => $this->searchAds($search, $categoryIds, $limit),
                'sellers' => $this->searchSellers($search, $this->searchableCategoryIds(null), $limit),
                'categories' => $this->searchCategories($search, $categoryIds, $limit),
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> globalSearch');
            ResponseService::errorResponse();
        }
    }

    /**
     * Category ids the search may touch: $moduleId's, or every active module's
     * when null — an inactive module's categories, ads and sellers stay out of a
     * cross-module search.
     */
    private function searchableCategoryIds(?int $moduleId): array
    {
        $moduleIds = $moduleId ? [$moduleId] : Module::active()->pluck('id')->all();

        return collect($moduleIds)
            ->flatMap(fn ($moduleId) => ModuleService::categoryIds($moduleId))
            ->unique()
            ->values()
            ->all();
    }

    /** Live ads — same visibility rules as get-item-list. */
    private function liveItems(array $categoryIds)
    {
        return Item::query()
            ->where('status', 'approved')
            ->getNonExpiredItems()
            ->whereIn('category_id', $categoryIds);
    }

    private function searchAds(string $search, array $categoryIds, int $limit): array
    {
        $query = $this->liveItems($categoryIds)->search($search);

        $total = (clone $query)->count();
        $ads = $query
            ->with('category:id,is_job_category,module_id', 'category.module:id,name,slug', 'translations', 'featured_items', 'currency', 'gallery_images:id,image,item_id,is_default')
            ->orderByRaw('COALESCE(items.published_at, items.created_at) DESC')
            ->limit($limit)
            ->get();

        // List rows carry no module; a cross-module search needs it per ad
        // (an item's module comes from its category).
        $rows = (new ItemApiResource($ads))->toArray(request());
        foreach ($ads->values() as $i => $ad) {
            $rows[$i]['module'] = $ad->category?->module;
        }

        return [
            'total' => $total,
            'data' => $rows,
        ];
    }

    /**
     * Sellers by name only (never email/mobile — this is a public endpoint),
     * and only those with at least one live ad in any active module. Not narrowed
     * by X-Module: a seller is shown the same in every module.
     */
    private function searchSellers(string $search, array $categoryIds, int $limit): array
    {
        $liveAds = fn ($q) => $q->where('status', 'approved')->getNonExpiredItems()->whereIn('category_id', $categoryIds);

        $query = User::query()
            ->whereLike('name', '%'.$search.'%')
            ->whereIn('id', $this->liveItems($categoryIds)->select('user_id'));

        $total = (clone $query)->count();
        $sellers = $query
            ->select('id', 'name', 'profile', 'is_verified', 'created_at')
            ->withCount(['items as ads_count' => $liveAds])
            ->withAvg('sellerReview as average_rating', 'ratings')
            ->orderByDesc('ads_count')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (User $seller) => [
                'id' => $seller->id,
                'name' => $seller->name,
                'profile' => $seller->profile,
                'is_verified' => (bool) $seller->is_verified,
                'ads_count' => $seller->ads_count,
                'average_rating' => $seller->average_rating !== null ? round((float) $seller->average_rating, 1) : null,
                'joined_at' => $seller->created_at,
            ]);

        return ['total' => $total, 'data' => $sellers];
    }

    private function searchCategories(string $search, array $categoryIds, int $limit): array
    {
        $query = Category::query()
            ->where('status', 1)
            ->whereIn('id', $categoryIds)
            ->search($search);

        $total = (clone $query)->count();
        $categories = $query
            ->with('translations', 'module:id,name,slug')
            ->orderBy('sequence')
            ->limit($limit)
            ->get()
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'translated_name' => $category->translated_name,
                'slug' => $category->slug,
                'image' => $category->image,
                'parent_category_id' => $category->parent_category_id,
                'is_job_category' => (bool) $category->is_job_category,
                'module' => $category->module,
            ]);

        return ['total' => $total, 'data' => $categories];
    }
}
