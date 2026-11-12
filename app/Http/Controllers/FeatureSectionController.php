<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\FeatureSection;
use App\Models\Item;
use App\Models\Module;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\HelperService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Throwable;
use App\Services\Module\ModuleService;

class FeatureSectionController extends Controller {

    public function index() {
        ResponseService::noAnyPermissionThenRedirect(['feature-section-list', 'feature-section-create', 'feature-section-update', 'feature-section-delete']);
        $modules = Module::active()->orderBy('sequence')->get();
        // Category filter: the default module's active categories; other modules' load on module change.
        $categoryModuleId = ModuleService::defaultModuleId();
        $categories = Category::forModule($categoryModuleId)->where('status', 1)
            ->orderByRaw('parent_category_id IS NULL DESC')->orderBy('sequence')->get();
        return view('feature_section.index', compact('modules', 'categories', 'categoryModuleId'));
    }

    public function create() {
        ResponseService::noPermissionThenRedirect('feature-section-create');
        return view('feature_section.create', $this->formData(ModuleService::defaultModuleId()));
    }

    public function edit($id) {
        ResponseService::noPermissionThenRedirect('feature-section-update');
        $featureSection = FeatureSection::with('translations')->findOrFail($id);

        $translations = [1 => ['name' => $featureSection->title]];
        foreach ($featureSection->translations as $translation) {
            $translations[$translation->language_id][$translation->key] = $translation->value;
        }

        $valueIds = array_filter(explode(',', (string) $featureSection->value));
        $selectedItems = $featureSection->filter === 'item_selection' && $valueIds
            ? Item::whereIn('id', $valueIds)->select('id', 'name')->orderBy('name')->get()
            : collect();

        // Locked category selection: keep the saved categories listed even if they've since been deactivated.
        $keepCategoryIds = $featureSection->filter === 'category_criteria' ? $valueIds : [];
        $formData = $this->formData($featureSection->module_id, $keepCategoryIds);

        return view('feature_section.edit', array_merge($formData, compact('featureSection', 'translations', 'valueIds', 'selectedItems')));
    }

    /**
     * Options shared by the create and edit forms. Categories: only $categoryModuleId's active ones
     * (plus $keepCategoryIds) on first render; other modules' are requested from lookup.categories
     * when the Module dropdown changes.
     */
    private function formData(?int $categoryModuleId, array $keepCategoryIds = []): array {
        return [
            'categoryModuleId'   => $categoryModuleId,
            'categories'         => Category::forModule($categoryModuleId)
                ->where(fn ($q) => $q->where('status', 1)->orWhereIn('id', $keepCategoryIds))
                ->orderByRaw('parent_category_id IS NULL DESC')->orderBy('sequence')->get(),
            'languages'          => CachingService::getLanguages()->values(),
            'categoryItemCounts' => Item::where('status', 'approved')
                ->getNonExpiredItems()
                ->selectRaw('category_id, count(*) as total')
                ->groupBy('category_id')
                ->pluck('total', 'category_id'),
            'modules'            => Module::active()->orderBy('sequence')->get(),
        ];
    }

    public function store(Request $request) {
        ResponseService::noPermissionThenSendJson('feature-section-create');
        
        $languages = CachingService::getLanguages();
        $defaultLangId = 1;
        $otherLanguages = $languages->where('id', '!=', $defaultLangId);

        $rules = [
            "title.$defaultLangId"  => 'required|string',
            'slug'                  => 'required',
            'filter'                => 'required|in:most_liked,most_viewed,price_criteria,category_criteria,featured_ads,item_selection',
            'style'                 => 'required|in:style_1,style_2,style_3,style_4',
            'min_price'             => 'required_if:filter,price_criteria',
            'max_price'             => 'required_if:filter,price_criteria',
            'category_id'           => 'required_if:filter,category_criteria',
            'item_id'               => 'required_if:filter,item_selection|array',
            'items_limit'           => 'nullable|integer|min:1|max:50',
            'status'                => 'nullable|boolean',
            'promotion_tier'        => 'nullable|in:featured,standard',
        ];

        foreach ($otherLanguages as $lang) {
            $langId = $lang->id;
            $rules["title.$langId"] = 'nullable|string';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        if ($error = $this->featureSectionModuleMismatch($request, $request->integer('module_id') ?: null)) {
            ResponseService::validationError($error);
        }

        try {
            $data = [
                'title' => $request->input("title.$defaultLangId"),
                'slug' => $request->slug,
                'filter' => $request->filter,
                'style' => $request->style,
                'sequence' => FeatureSection::max('sequence') + 1,
                'module_id' => $request->input('module_id'),
                'value' => $this->featureSectionValue($request),
                'promotion_tier' => $this->featureSectionPromotionTier($request),
                'items_limit' => $request->integer('items_limit') ?: 12,
                'status' => $request->boolean('status', true),
            ];

            $data['slug'] = HelperService::generateUniqueSlug(new FeatureSection(), $request->slug);

            if ($request->filter == "price_criteria") {
                $data['min_price'] = $request->min_price;
                $data['max_price'] = $request->max_price;
            }

            $featureSection = FeatureSection::create($data);
            
            $translationData = [];
            foreach ($otherLanguages as $lang) {
                $langId = $lang->id;
                $translatedTitle = $request->input("title.$langId");

                if (!empty($translatedTitle)) {
                    $translationData[] = [
                        'translatable_id'   => $featureSection->id,
                        'translatable_type' => FeatureSection::class,
                        'key'               => 'name',
                        'value'             => $translatedTitle ?? '',
                        'language_id'       => $langId,
                    ];
                }
            }
            if (!empty($translationData)) {
                HelperService::storeTranslations($translationData);
            }
            ResponseService::successResponse('Feature Section Added Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "FeaturedSection Controller -> store");
            ResponseService::errorResponse();
        }

    }

    public function show(Request $request) {
        ResponseService::noPermissionThenSendJson('feature-section-list');
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'sequence');
        $order = $request->input('order', 'ASC');
        $sql = FeatureSection::with('translations', 'module:id,name')->forModule($request->input('module_id'))
            ->when(in_array($request->input('status'), ['0', '1'], true), fn ($q) => $q->where('status', (bool) $request->status))
            // Category: in `value` (comma-separated ids) of category_criteria or the other rules' scope — not item_selection's item ids.
            ->when($request->integer('category_id'), function ($q, $id) {
                $q->where('filter', '!=', 'item_selection')->where(fn ($v) => $v->where('value', (string) $id)
                    ->orWhereLike('value', "{$id},%")->orWhereLike('value', "%,{$id}")->orWhereLike('value', "%,{$id},%"));
            });
        if (!empty($request->search)) {
            $sql = $sql->search($request->search);
        }

        $total = $sql->count();
        $multiModule = Module::active()->count() > 1;
        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $result = $sql->get();
        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        foreach ($result as $row) {
            $tempRow = $row->toArray();
            unset($tempRow['description']);
            $tempRow['module_name'] = $row->module->name ?? '';
            // Feature sections show on their module's homepage.
            $tempRow['used_on'] = $multiModule ? trans(':module Homepage', ['module' => $tempRow['module_name']]) : trans('Homepage');
            $operate = '';
            if (Auth::user()->can('feature-section-update')) {
                $operate .= BootstrapTableService::editButton(route('feature-section.edit', $row->id));
            }

            if (Auth::user()->can('feature-section-delete')) {
                $operate .= BootstrapTableService::deleteButton(route('feature-section.destroy', $row->id));
            }
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    /**
     * `value`: the chosen items for item_selection; otherwise the category ids — required for
     * category_criteria, an optional "Category Filter Scope" for the other rules (null = all).
     */
    private function featureSectionValue(Request $request): ?string {
        $ids = array_filter((array) ($request->filter === 'item_selection' ? $request->item_id : $request->category_id));
        return $ids ? implode(',', $ids) : null;
    }

    // Promotion Package Tier: only Most Viewed / Most Liked use it (featured | standard; empty = all).
    private function featureSectionPromotionTier(Request $request): ?string {
        return in_array($request->filter, ['most_viewed', 'most_liked']) ? ($request->input('promotion_tier') ?: null) : null;
    }

    // Only the active filter's ids are saved, so only those are checked against the module.
    private function featureSectionModuleMismatch(Request $request, ?int $moduleId): ?string {
        return ModuleService::ownershipError(
            $moduleId,
            $request->filter !== 'item_selection' ? (array) $request->category_id : [],
            $request->filter === 'item_selection' ? (array) $request->item_id : [],
        );
    }

    public function update(Request $request, $id) {
        ResponseService::noPermissionThenSendJson('feature-section-update');
        
        $languages = CachingService::getLanguages();
        $defaultLangId = 1;
        $otherLanguages = $languages->where('id', '!=', $defaultLangId);

        $rules = [
            "title.$defaultLangId"  => 'required|string',
            'slug'                  => 'required',
            'filter'                => 'required|in:most_liked,most_viewed,price_criteria,category_criteria,featured_ads,item_selection',
            'style'                 => 'required|in:style_1,style_2,style_3,style_4',
            'min_price'             => 'required_if:filter,price_criteria',
            'max_price'             => 'required_if:filter,price_criteria',
            'category_id'           => 'required_if:filter,category_criteria',
            'item_id'               => 'required_if:filter,item_selection|array',
            'items_limit'           => 'nullable|integer|min:1|max:50',
            'status'                => 'nullable|boolean',
            'promotion_tier'        => 'nullable|in:featured,standard',
        ];

        foreach ($otherLanguages as $lang) {
            $langId = $lang->id;
            $rules["title.$langId"] = 'nullable|string';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        if ($error = $this->featureSectionModuleMismatch($request, $request->integer('module_id') ?: FeatureSection::whereKey($id)->value('module_id'))) {
            ResponseService::validationError($error);
        }
        try {
            $feature_section = FeatureSection::findOrFail($id);

            $data = [
                'title' => $request->input("title.$defaultLangId"),
                // Preserve when absent — no "Module" field on this form yet.
                'module_id' => $request->input('module_id', $feature_section->module_id),
                'slug' => $request->slug,
                'filter' => $request->filter,
                'style' => $request->style,
                'value' => $this->featureSectionValue($request),
                'promotion_tier' => $this->featureSectionPromotionTier($request),
                'items_limit' => $request->integer('items_limit') ?: $feature_section->items_limit,
                'status' => $request->boolean('status', $feature_section->status),
            ];

            if ($request->filter == "price_criteria") {
                $data['min_price'] = $request->min_price;
                $data['max_price'] = $request->max_price;
            } else {
                $data['min_price'] = null;
                $data['max_price'] = null;
            }

            $data['slug'] = HelperService::generateUniqueSlug(new FeatureSection(), $request->slug, $feature_section->id);
            $feature_section->update($data);
            
            $translationData = [];
            foreach ($otherLanguages as $lang) {
                $langId = $lang->id;
                $translatedTitle = $request->input("title.$langId");

                // Title cleared: drop this language's translation
                if (empty($translatedTitle)) {
                    $feature_section->translations()->where('language_id', $langId)->where('key', 'name')->delete();
                    continue;
                }

                $translationData[] = [
                    'translatable_id'   => $feature_section->id,
                    'translatable_type' => FeatureSection::class,
                    'key'               => 'name',
                    'value'             => $translatedTitle,
                    'language_id'       => $langId,
                ];
            }
            if (!empty($translationData)) {
                HelperService::storeTranslations($translationData);
            }
            ResponseService::successResponse('Feature Section Updated Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "FeaturedSection Controller -> update");
            ResponseService::errorResponse();
        }
    }

    /** @deprecated Route commented out in routes/web.php — replaced by AdminLookupController::items. Delete once confirmed. */
    public function itemsByIds(Request $request) {
        ResponseService::noAnyPermissionThenSendJson(['feature-section-create', 'feature-section-update']);
        $request->validate(['item_ids' => 'required|string']);

        $ids = array_filter(explode(',', $request->item_ids));
        $items = Item::whereIn('id', $ids)->select('id', 'name')->where('status', 'approved')->getNonExpiredItems()->orderBy('name')->get();

        return response()->json(['data' => $items]);
    }

    /** @deprecated Route commented out in routes/web.php — replaced by AdminLookupController::items. Delete once confirmed. */
    public function itemsByCategory(Request $request) {
        ResponseService::noAnyPermissionThenSendJson(['feature-section-create', 'feature-section-update']);
        $request->validate(['category_ids' => 'required|string']);

        $ids = array_filter(explode(',', $request->category_ids));

        $items = Item::where('status', 'approved')
            ->getNonExpiredItems()
            ->whereIn('category_id', $ids)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function destroy($id) {
        try {
            ResponseService::noPermissionThenSendJson('feature-section-delete');
            FeatureSection::findOrFail($id)->delete();
            ResponseService::successResponse('Feature Section delete successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "FeaturedSection Controller -> destroy");
            ResponseService::errorResponse('Something Went Wrong');
        }
    }
}
