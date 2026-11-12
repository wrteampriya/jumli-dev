<?php

namespace App\Http\Controllers\Api;

use App\Models\Category;
use App\Models\HomeScreenSection;
use App\Models\PopularCategory;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Throwable;

/** @tags Home Screen */
class HomeScreenApiController extends BaseApiController
{
    /** Get Home Screen Configuration */
    public function getHomeScreen(Request $request)
    {
        try {
            $sections = HomeScreenSection::active()
                ->forModule($this->currentModuleId())
                ->ordered()
                ->get(['id', 'section_type']);

            ResponseService::successResponse('Data Fetched Successfully', [
                'sections' => $sections,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getHomeScreen');
            ResponseService::errorResponse();
        }
    }

    /** Get Popular Categories */
    public function getPopularCategories(Request $request)
    {
        try {
            // PopularCategory derives its module via category, not a column
            // of its own — a small curated list (unlike items), so a
            // whereHas here is fine, unlike the whereIn(category_id, …)
            // pattern items need for volume reasons. The scope delegates to
            // Category::forModule.
            $popularCategories = PopularCategory::with(['category' => function($q){
                $q->where('status',1)->with('translations');
            }])
                ->forModule($this->currentModuleId())
                ->where('status', true)
                ->orderBy('sequence')
                ->get()
                ->pluck('category')
                ->filter();

            ResponseService::successResponse('Data Fetched Successfully', $popularCategories->values());
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getPopularCategories');
            ResponseService::errorResponse();
        }
    }
}
