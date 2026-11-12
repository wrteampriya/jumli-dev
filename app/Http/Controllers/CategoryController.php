<?php

namespace App\Http\Controllers;

use App\Support\DbCompat;
use App\Models\Category;
use App\Models\CustomField;
use App\Models\CustomFieldCategory;
use App\Models\Module;
use App\Services\BootstrapTableService;
use App\Models\Item;
use App\Services\CachingService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\ResponseService;
use DB;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;
use function compact;
use function view;
use App\Services\Module\ModuleService;

class CategoryController extends Controller
{
    private string $uploadFolder;

    // module id => ModuleService::categoryToggles(), memoized across bulk rows.
    private array $toggleCache = [];

    public function __construct()
    {
        $this->uploadFolder = "category";
    }

    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect(['category-list', 'category-create', 'category-update', 'category-delete']);
        $modules = Module::active()->orderBy('sequence')->get();

        // Module tabs (2+ modules): "All Modules" overview by default, or one module's table.
        // A single module always shows its table.
        $requested = $modules->firstWhere('id', request()->integer('module_id'));
        $selectedModule = $requested ?? ($modules->count() > 1 ? null : $modules->first());
        $stats = $modules->mapWithKeys(fn ($module) => [$module->id => $this->moduleCategoryStats($module->id)]);
        $overview = $selectedModule ? collect() : $modules->map(fn ($module) => [
            'module' => $module,
            'stats' => $stats[$module->id],
            // First three top-level categories, with their field and (approved, live) ad counts.
            'categories' => Category::without('translations')->forModule($module->id)->whereNull('parent_category_id')
                ->withCount('custom_fields')->orderBy('sequence')->limit(3)->get(),
        ]);

        return view('category.index', compact('modules', 'selectedModule', 'stats', 'overview'));
    }

    /** @return array{categories: int, subcategories: int, listings: int} */
    private function moduleCategoryStats(int $moduleId): array
    {
        return [
            'categories' => Category::forModule($moduleId)->whereNull('parent_category_id')->count(),
            'subcategories' => Category::forModule($moduleId)->whereNotNull('parent_category_id')->count(),
            'listings' => Item::where('status', 'approved')->getNonExpiredItems()
                ->whereIn('category_id', ModuleService::categoryIds($moduleId))->count(),
        ];
    }

    public function create(Request $request)
    {
        $languages = CachingService::getLanguages()->values();
        ResponseService::noPermissionThenRedirect('category-create');
        $categories = Category::with('subcategories')->orderByRaw('parent_category_id IS NULL DESC')->orderBy('sequence')->get();
        // Only rendered by the view once count() > 1 — see the plan's
        // admin-scoping decision (explicit per-row field, no switcher).
        $modules = Module::active()->orderBy('sequence')->get();
        // Preselects the parent when opened from a category's subcategory list.
        $parentId = $request->integer('id') ?: null;
        // Built here: the dropdown tree renderer empties $categories as it goes.
        $parentModules = $categories->pluck('module_id', 'id');
        $moduleCategoryToggles = $this->moduleCategoryToggles($modules);
        $defaultModuleId = ModuleService::defaultModuleId() ?? $modules->first()?->id;

        return view('category.create', compact('categories', 'languages', 'modules', 'parentId', 'parentModules', 'moduleCategoryToggles', 'defaultModuleId'));
    }

    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('category-create');

        $languages = CachingService::getLanguages();
        $defaultLangId = 1;
        $otherLanguages = $languages->where('id', '!=', $defaultLangId);

        $rules = [
            "name.$defaultLangId" => 'required|string|max:100',
            // Optional: a category without an image shows the placeholder_image in the admin.
            'image'              => 'nullable|mimes:jpg,jpeg,png,svg|max:7168',
            'parent_category_id' => 'nullable|integer',
            'slug' => [
                'nullable',
                'regex:/^[a-zA-Z0-9\-_]+$/'
            ],
            'status'             => 'required|boolean',
            'module_id'          => 'nullable|exists:modules,id',
        ];

        foreach ($otherLanguages as $lang) {
            $langId = $lang->id;
            $rules["name.$langId"] = 'nullable|string|max:100';
        }

        $request->validate($rules, [
            'slug.regex' => 'Slug must be only English letters, numbers, hyphens (-), or underscores (_).'
        ]);
        HelperService::validateSeoSchemas($request, $languages->pluck('id')->all());
        $this->validateCategoryToggles($request);

        try {
            $data = [
                'name' => $request->input("name.$defaultLangId"),
                // Absent (single-module today, or a subcategory whose
                // module is about to be overridden by Category::booted()'s
                // parent-inheritance hook anyway) -> BelongsToModule's
                // default-fill hook resolves it to the default module.
                'module_id' => $request->input('module_id'),
                'parent_category_id' => $request->parent_category_id,
                'status' => $request->status,
                'is_job_category' => $request->is_job_category ?? 0,
                // 'is_featured' => $request->is_featured ?? 0,
            ];
            $slug = trim($request->input('slug') ?? '');
            $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($slug));
            $slug = trim($slug, '-');
            if (empty($slug)) {
                // $slug = HelperService::generateRandomSlug();
                $slug = trim($request->input("name.$defaultLangId") ?? '');
                $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($slug));
                $slug = trim($slug, '-');
            }
            $data['slug'] = HelperService::generateUniqueSlug(new Category, $slug);

            if ($request->hasFile('image')) {
                $data['image'] = FileService::compressAndUpload($request->file('image'), $this->uploadFolder);
            }

            $category = Category::create($data);

            $translationData = [];
            foreach ($otherLanguages as $lang) {
                $langId = $lang->id;
                $translatedName = $request->input("name.$langId");

                if (!empty($translatedName)) {
                    $translationData[] = [
                        'translatable_id'   => $category->id,
                        'translatable_type' => Category::class,
                        'key'               => 'name',
                        'value'             => $translatedName,
                        'language_id'       => $langId,
                    ];
                }
            }
            if (!empty($translationData)) {
                HelperService::storeTranslations($translationData);
            }

            // Store SEO details
            HelperService::storeSeoDetails($category, $request, $languages->pluck('id')->toArray());

            ResponseService::successRedirectResponse("Category Added Successfully");
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th);
            ResponseService::errorRedirectResponse();
        }
    }


    public function show(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson('category-list');
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'sequence');
        $order = $request->input('order', 'ASC');
        $sql = Category::without('translations')->withCount('subcategories')->withCount('custom_fields')->with('subcategories', 'parent', 'module:id,name')
            ->forModule($request->input('module_id'));
        if (!empty($request->search)) {
            // Search across all levels so subcategories also match; skip parent filter
            $sql = $sql->search($request->search);
        } else {
            if ($id == "0") {
                $sql->whereNull('parent_category_id');
            } else {
                $sql->where('parent_category_id', $id);
            }
        }
        if ($sort !== 'advertisements_count') {
            $sql->orderBy($sort, $order);
        }
        $result = $sql->get();

        $listingCounts = $this->listingCounts();

        if ($sort === 'advertisements_count') {
            // $result = $result->sortBy(function ($category) {
            //     return $category->all_items_count;
            // }, SORT_REGULAR, strtolower($order) === 'desc')->values();
            $result = $result->sortBy(fn ($category) => $listingCounts[$category->id] ?? 0, SORT_REGULAR, strtolower($order) === 'desc')->values();

            $result = $result->slice($offset, $limit)->values();
        } else {
            $result = $result->slice($offset, $limit);
        }
        $total = $sql->count();
        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;

        foreach ($result as $key => $row) {
            $operate = '';
            if (Auth::user()->can('category-update')) {
                $operate .= BootstrapTableService::editButton(route('category.edit', $row->id));
            }

            if (Auth::user()->can('category-delete')) {
                $operate .= BootstrapTableService::deleteButton(route('category.destroy', $row->id));
            }

            if ($row->subcategories_count > 1) {
                $operate .= BootstrapTableService::button('ph ph-list-bullets', route('sub.category.order.change', $row->id), [], ['title' => trans("Change Categories Order")]);
            }
            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            if (!empty($request->search) && $row->parent) {
                $tempRow['name'] = $row->parent->name . ' / ' . $row->name;
            }
            // Plain numbers; the page formats them (categoryCountFormatter / categoryFieldsFormatter).
            $tempRow['subcategories_count'] = (int) $row->subcategories_count;
            $tempRow['custom_fields_count'] = (int) $row->custom_fields_count;
            $tempRow['operate'] = $operate;
            $tempRow['advertisements_count'] = $row->all_items_count;
            // Column only rendered once count() > 1 (see category/index.blade.php)
            // but always populated so it isn't a broken column the day it starts showing.
            $tempRow['module_name'] = $row->module->name ?? '';
            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function edit($id)
    {
        ResponseService::noPermissionThenRedirect('category-update');
        $category_data = Category::findOrFail($id);

        // Initialize translations array with English (default) data
        $translations = [];
        $translations[1] = [
            'name' => $category_data->name,
        ];

        // Add other language translations
        $grouped = $category_data->translations->groupBy('language_id');
        foreach ($grouped as $langId => $items) {
            $translations[$langId] = [];
            foreach ($items as $item) {
                $translations[$langId][$item->key] = $item->value;
            }
        }

        $seoTranslations = HelperService::prepareSeoTranslationsForEdit($category_data);

        $parent_category_data = Category::find($category_data->parent_category_id);
        $parent_category = $parent_category_data->name ?? '';
        $categories = Category::with('subcategories')->orderByRaw('parent_category_id IS NULL DESC')->orderBy('sequence')->get();
        // Fetch all languages including English
        $languages = CachingService::getLanguages()->values();
        $modules = Module::active()->orderBy('sequence')->get();
        // Built here: the dropdown tree renderer empties $categories as it goes.
        $parentModules = $categories->pluck('module_id', 'id');
        $moduleCategoryToggles = $this->moduleCategoryToggles($modules);
        $defaultModuleId = $category_data->module_id;
        $parentId = $category_data->parent_category_id;
        // The form leaves itself and its own subcategories out of the Parent list
        // (update() rejects them too).
        $excludedParentIds = $category_data->descendants()->pluck('id')->push($category_data->id)->map(fn ($id) => (int) $id)->values();
        return view('category.edit', compact('category_data', 'parent_category_data', 'parent_category', 'translations', 'seoTranslations', 'languages', 'categories', 'modules', 'parentModules', 'moduleCategoryToggles', 'defaultModuleId', 'parentId', 'excludedParentIds'));
    }

    // module id => which category toggles its pricing modes allow; the forms
    // hide the toggles the category's module doesn't support.
    private function moduleCategoryToggles($modules): array
    {
        return $modules->mapWithKeys(fn (Module $module) => [
            $module->id => ModuleService::categoryToggles($module->id),
        ])->all();
    }

    // Job Category may only be switched on when the category's module
    // supports the matching pricing mode (see
    // ModuleService::categoryToggles()). A subcategory belongs to its
    // parent's module (Category::booted()), so that one decides.
    private function validateCategoryToggles(Request $request, ?Category $category = null): void
    {
        $moduleId = $request->filled('parent_category_id')
            ? Category::whereKey($request->input('parent_category_id'))->value('module_id')
            : ($request->input('module_id') ?: ($category ? $category->module_id : ModuleService::defaultModuleId()));

        $errors = $this->categoryToggleErrors($moduleId, $request->only(['is_job_category']), $category);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    // Shared by the forms and the bulk upload/update rows: toggle => message
    // for each toggle switched on that the module doesn't allow. A toggle an
    // existing category already has on is kept while it stays in the same
    // module — it predates the rule and saving must not wipe it.
    private function categoryToggleErrors(?int $moduleId, array $values, ?Category $category = null): array
    {
        $moduleId = $moduleId ?: ModuleService::defaultModuleId();
        $toggles = $this->toggleCache[$moduleId] ??= ModuleService::categoryToggles($moduleId);

        $messages = [
            'is_job_category' => 'Job Category is only available when the module supports Range pricing.',
        ];

        $errors = [];
        foreach ($messages as $key => $message) {
            $keptFromBefore = $category && (int) $category->module_id === (int) $moduleId && $category->{$key};
            if (!empty($values[$key]) && !$toggles[$key] && !$keptFromBefore) {
                $errors[$key] = $message;
            }
        }

        return $errors;
    }

    public function update(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson('category-update');
        // Before the try: its catch-all would swallow the validation error.
        HelperService::validateSeoSchemas($request, CachingService::getLanguages()->pluck('id')->all());
        $this->validateCategoryToggles($request, Category::find($id));
        try {
            $languages = CachingService::getLanguages();
            $defaultLangId = 1;
            $otherLanguages = $languages->where('id', '!=', $defaultLangId);

            $rules = [
                "name.$defaultLangId" => 'required|string|max:100',
                'image'           => 'nullable|mimes:jpg,jpeg,png|max:7168',
                'parent_category_id' => 'nullable|integer',
                'slug' => [
                    'nullable',
                    'regex:/^[a-zA-Z0-9\-_]+$/'
                ],
                'status'          => 'required|boolean',
                'module_id'       => 'nullable|exists:modules,id',
            ];

            foreach ($otherLanguages as $lang) {
                $langId = $lang->id;
                $rules["name.$langId"] = 'nullable|string|max:100';
            }

            $request->validate($rules, [
                'slug.regex' => 'Slug must be only English letters, numbers, hyphens (-), or underscores (_).'
            ]);

            $category = Category::find($id);
            if ($request->parent_category_id == $category->id) {
                return back()->withErrors(['parent_category' => 'A category cannot be set as its own parent.']);
            }

            if (!empty($request->parent_category_id)) {
                $descendantIds = $category->descendants()->pluck('id')->map(fn ($descendantId) => (int) $descendantId)->all();
                if (in_array((int) $request->parent_category_id, $descendantIds, true)) {
                    return back()->withErrors(['parent_category' => 'A category cannot be added as a sub category of its own sub category.']);
                }

                // A category always belongs to its parent's module (see
                // Category::booted()'s inheritance hook) — reject the
                // reparent outright rather than silently moving the
                // category (and everything under it) into a different
                // module. Inert today: only one module exists, so every
                // category's module_id is already identical.
                $newParent = Category::find($request->parent_category_id);
                if ($newParent && $newParent->module_id !== $category->module_id) {
                    return back()->withErrors(['parent_category' => 'A category cannot be reparented into a different module.']);
                }
            }

            $data = [
                'name' => $request->input("name.$defaultLangId"),
                // The "Module" field only renders once count() > 1, so an
                // older/cached form submits no module_id at all — preserve
                // the existing value rather than resolving to core.
                'module_id' => $request->input('module_id', $category->module_id),
                'parent_category_id' => $request->parent_category_id,
                'status' => $request->status,
                'is_job_category' => $request->is_job_category ?? 0,
                // 'is_featured' => $request->is_featured ?? 0,
            ];

            if ($request->hasFile('image')) {
                $data['image'] = FileService::compressAndReplace($request->file('image'), $this->uploadFolder, $category->getRawOriginal('image'));
            }
            $slug = trim($request->input('slug') ?? '');
            $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($slug));
            $slug = trim($slug, '-');
            if (empty($slug)) {
                $slug = HelperService::generateRandomSlug();
            }
            $data['slug'] = HelperService::generateUniqueSlug(new Category(), $slug, $category->id);
            $category->update($data);

            if ($request->has('is_job_category')) {
                $category->subcategories()->update([
                    'is_job_category' => $request->is_job_category ? 1 : 0,
                ]);
            }

            $translationData = [];
            foreach ($otherLanguages as $lang) {
                $langId = $lang->id;
                $translatedName = $request->input("name.$langId");

                $translationData[] = [
                    'translatable_id'   => $category->id,
                    'translatable_type' => Category::class,
                    'key'               => 'name',
                    'value'             => $translatedName ?? '',
                    'language_id'       => $langId,
                ];
            }
            if (!empty($translationData)) {
                HelperService::storeTranslations($translationData, true);
            }

            // Store SEO details
            HelperService::storeSeoDetails($category, $request, $languages->pluck('id')->toArray());

            ResponseService::successRedirectResponse("Category Updated Successfully", route('category.index'));
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th);
            ResponseService::errorRedirectResponse('Something Went Wrong');
        }
    }

    // public function destroy($id) {
    //     ResponseService::noPermissionThenSendJson('category-delete');
    //     try {
    //        $category = Category::withCount(['subcategories', 'custom_fields'])
    //         ->with('subcategories')
    //         ->findOrFail($id);
    //         if ($category->all_items_count > 0) {
    //             ResponseService::errorResponse('Cannot delete category. It has associated advertisements.');
    //         }
    //        if ($category->other_items_count > 0) {
    //             ResponseService::errorResponse(
    //                 'Cannot delete category. Delete non-active items first.'
    //             );
    //         }
    //         if ($category->subcategories_count > 0 || $category->custom_fields_count > 0) {
    //             ResponseService::errorResponse('Failed to delete category', 'Cannot delete category. Remove associated subcategories and custom fields first.');
    //         }
    //         if ($category->delete()) {
    //             ResponseService::successResponse('Category delete successfully');
    //         }
    //     } catch (QueryException $th) {
    //         ResponseService::logErrorResponse($th, 'Failed to delete category', 'Cannot delete category. Remove associated subcategories and custom fields first.');
    //         ResponseService::errorResponse('Something Went Wrong');
    //     } catch (Throwable $th) {
    //         ResponseService::logErrorResponse($th, "CategoryController -> delete");
    //         ResponseService::errorResponse('Something Went Wrong');
    //     }
    // }

    public function destroy($id)
    {
        ResponseService::noPermissionThenSendJson('category-delete');

        try {
            $category = Category::withCount([
                'subcategories',
                'custom_fields',
                'items as all_items_count',
                'items as other_items_count' => function ($q) {
                    $q->where('status', '!=', 'active');
                }
            ])->findOrFail($id);

            if ($category->all_items_count > 0) {
                return ResponseService::errorResponse(
                    'Cannot delete category. It has associated advertisements.'
                );
            }

            if ($category->other_items_count > 0) {
                return ResponseService::errorResponse(
                    'Cannot delete category. Delete non-active items first.'
                );
            }

            if ($category->subcategories_count > 0 || $category->custom_fields_count > 0) {
                return ResponseService::errorResponse(
                    'Cannot delete category. Remove associated subcategories and custom fields first.'
                );
            }

            $category->delete();

            return ResponseService::successResponse('Category deleted successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'CategoryController -> destroy');
            return ResponseService::errorResponse('Something went wrong');
        }
    }


    public function getSubCategories($id)
    {
        ResponseService::noPermissionThenRedirect('category-list');
        $subcategories = Category::where('parent_category_id', $id)
            ->with('subcategories', 'module:id,name')
            ->withCount('custom_fields')
            ->withCount('subcategories')
            ->withCount('items')
            ->orderBy('sequence')
            ->get()
            ->map(function ($subcategory) {
                $operate = '';
                if (Auth::user()->can('category-update')) {
                    $operate .= BootstrapTableService::editButton(route('category.edit', $subcategory->id));
                }
                if (Auth::user()->can('category-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('category.destroy', $subcategory->id));
                }
                if ($subcategory->subcategories_count > 1) {
                    $operate .= BootstrapTableService::button('ph ph-list-bullets', route('sub.category.order.change', $subcategory->id));
                }
                $subcategory->operate = $operate;
                $subcategory->advertisements_count = $subcategory->items_count;
                // Inherited from the parent (Category::booted()), same column as root rows.
                $subcategory->module_name = $subcategory->module->name ?? '';
                return $subcategory;
            });

        return response()->json($subcategories);
    }


    /**
     * Listings column: approved, non-expired items per category, each category's total including
     * all of its subcategories (what Category::all_items_count gives, in two queries instead of
     * one per descendant).
     *
     * @return array<int, int> category id => count
     */
    private function listingCounts(): array
    {
        $direct = Item::where('status', 'approved')->getNonExpiredItems()
            ->groupBy('category_id')
            ->selectRaw('category_id, COUNT(*) as total')
            ->pluck('total', 'category_id');

        $children = Category::without('translations')->whereNotNull('parent_category_id')
            ->get(['id', 'parent_category_id'])
            ->groupBy('parent_category_id');

        $totals = [];
        $sum = function (int $id, array $path = []) use (&$sum, &$totals, $direct, $children): int {
            if (isset($totals[$id])) {
                return $totals[$id];
            }
            if (isset($path[$id])) {
                return 0; // parent loop in bad data
            }
            $path[$id] = true;
            $total = (int) ($direct[$id] ?? 0);
            foreach ($children->get($id, []) as $child) {
                $total += $sum($child->id, $path);
            }
            return $totals[$id] = $total;
        };

        foreach (Category::without('translations')->pluck('id') as $id) {
            $sum($id);
        }

        return $totals;
    }

    public function customFields($id)
    {
        ResponseService::noPermissionThenRedirect('custom-field-list');
        $category = Category::find($id);
        $p_id = $category->parent_category_id;
        $cat_id = $category->id;
        $category_name = $category->name;

        return view('category.custom-fields', compact('cat_id', 'category_name', 'p_id'));
    }

    public function getCategoryCustomFields(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson('custom-field-list');
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'ASC');

        $sql = CustomField::whereHas('categories', static function ($q) use ($id) {
            $q->where('category_id', $id);
        })->orderBy($sort, $order);

        if (isset($request->search)) {
            $sql->search($request->search);
        }

        $sql->take($limit);
        $total = $sql->count();
        $res = $sql->skip($offset)->take($limit)->get();
        $bulkData = array();
        $rows = array();
        $tempRow['type'] = '';


        foreach ($res as $row) {
            $tempRow = $row->toArray();
            //            $operate = BootstrapTableService::editButton(route('custom-fields.edit', $row->id));
            $operate = BootstrapTableService::deleteButton(route('category.custom-fields.destroy', [$id, $row->id]));
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        $bulkData['total'] = $total;
        return response()->json($bulkData);
    }

    public function destroyCategoryCustomField($categoryID, $customFieldID)
    {
        try {
            ResponseService::noPermissionThenRedirect('custom-field-delete');
            CustomFieldCategory::where(['category_id' => $categoryID, 'custom_field_id' => $customFieldID])->delete();
            ResponseService::successResponse("Custom Field Deleted Successfully");
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "CategoryController -> destroyCategoryCustomField");
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    public function categoriesReOrder(Request $request)
    {
        $categories = Category::whereNull('parent_category_id')
            ->forModule($request->input('module_id'))
            ->orderBy('sequence')
            ->get();
        return view('category.categories-order', compact('categories'));
    }

    public function subCategoriesReOrder(Request $request, $id)
    {
        $categories = Category::with('subcategories')->where('parent_category_id', $id)->orderBy('sequence')->get();
        return view('category.sub-categories-order', compact('categories'));
    }

    public function updateOrder(Request $request)
    {
        $request->validate([
            'order' => 'required'
        ]);
        try {
            $order = json_decode($request->input('order'), true);
            $data = [];
            foreach ($order as $index => $id) {
                $data[$id] = $index + 1;
            }
            DbCompat::updateIntColumnById((new Category())->getTable(), 'sequence', $data, touch: true);
            ResponseService::successResponse("Order Updated Successfully");
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th);
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    public function bulkUpload()
    {
        ResponseService::noPermissionThenRedirect('category-create');

        $modules = Module::active()->orderBy('sequence')->get();
        $defaultModuleId = ModuleService::defaultModuleId() ?? $modules->first()?->id;

        return view('category.bulk-upload', compact('modules', 'defaultModuleId'));
    }

    public function bulkUpdate()
    {
        ResponseService::noPermissionThenRedirect('category-update');

        return view('category.bulk-update');
    }

    public function downloadExample()
    {
        ResponseService::noPermissionThenRedirect('category-create');

        try {
            $languages = CachingService::getLanguages()->values();
            $defaultLangId = 1;
            $otherLanguages = $languages->where('id', '!=', $defaultLangId);

            $filename = 'categories-bulk-upload-example.csv';

            if (ob_get_level()) {
                ob_end_clean();
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment;filename="'.$filename.'"');
            header('Cache-Control: max-age=0');
            header('Pragma: public');

            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

            // $headers = ['Name', 'Image', 'Parent Category ID', 'Status', 'Job Category', 'Featured', 'Slug'];
            $headers = ['Name', 'Image', 'Parent Category ID', 'Status', 'Job Category', 'Slug', 'Meta Title', 'Meta Description', 'Meta Keywords'];

            foreach ($otherLanguages as $lang) {
                $code = $lang->code ?? ('lang_'.$lang->id);
                $headers[] = 'Name_'.$code;
            }

            fputcsv($output, $headers);

            $examples = [
                ['Electronics', 'category/example.jpg', '', '1', '0', 'electronics', 'Buy Electronics Online', 'Shop the latest electronics at great prices.', 'electronics,gadgets,devices'],
                ['Mobile Phones', 'category/example.jpg', '1', '1', '0', 'mobile-phones', 'Mobile Phones', 'Latest smartphones and mobile phones.', 'mobile,phones,smartphone'],
                ['Laptops', 'category/example.jpg', '1', '1', '0', 'laptops', '', '', ''],
            ];

            foreach ($examples as $example) {
                $row = $example;
                foreach ($otherLanguages as $lang) {
                    $row[] = '';
                }
                fputcsv($output, $row);
            }

            fclose($output);
            exit;

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'CategoryController -> downloadExample');

            return ResponseService::errorResponse('Error generating CSV file: '.$th->getMessage());
        }
    }

    public function downloadCurrentCategories()
    {
        ResponseService::noPermissionThenRedirect('category-update');

        try {
            $languages = CachingService::getLanguages()->values();
            $defaultLangId = 1;
            $otherLanguages = $languages->where('id', '!=', $defaultLangId);

            $filename = 'categories-export-'.date('Y-m-d-His').'.csv';

            if (ob_get_level()) {
                ob_end_clean();
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment;filename="'.$filename.'"');
            header('Cache-Control: max-age=0');
            header('Pragma: public');

            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

            // $headers = ['ID', 'Name', 'Image', 'Parent Category ID', 'Status', 'Job Category', 'Featured', 'Slug'];
            $headers = ['ID', 'Name', 'Image', 'Parent Category ID', 'Status', 'Job Category', 'Slug', 'Meta Title', 'Meta Description', 'Meta Keywords', 'Module ID'];

            foreach ($otherLanguages as $lang) {
                $code = $lang->code ?? ('lang_'.$lang->id);
                $headers[] = 'Name_'.$code;
            }
            fputcsv($output, $headers);

            $chunkSize = 100;
            Category::with(['translations', 'seoDetail'])
                ->chunk($chunkSize, function ($categories) use ($output, $otherLanguages) {
                    foreach ($categories as $category) {
                        $imagePath = $category->getRawOriginal('image') ?? '';

                        $row = [
                            $category->id,
                            $category->name,
                            $imagePath,
                            $category->parent_category_id ?? '',
                            $category->status,
                            $category->is_job_category ?? 0,
                            // $category->is_featured ?? 0,
                            $category->slug ?? '',
                            $category->seoDetail->meta_title ?? '',
                            $category->seoDetail->meta_description ?? '',
                            $category->seoDetail->meta_keywords ?? '',
                            $category->module_id,
                        ];

                        foreach ($otherLanguages as $lang) {
                            $langId = $lang->id;
                            $langTranslations = $category->translations->where('language_id', $langId);

                            $tName = $langTranslations->where('key', 'name')->first()?->value ?? '';

                            $row[] = $tName;
                        }

                        fputcsv($output, $row);
                    }
                });

            fclose($output);
            exit;

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'CategoryController -> downloadCurrentCategories');

            return ResponseService::errorResponse('Error generating CSV file: '.$th->getMessage());
        }
    }

    public function uploadGalleryImage(Request $request)
    {
        ResponseService::noPermissionThenSendJson('category-create');

        $validator = Validator::make($request->all(), [
            'images.*' => 'required|file|mimes:jpg,jpeg,png|max:5120',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $uploadedImages = [];
            $files = $request->file('images');

            foreach ($files as $file) {
                $path = FileService::compressAndUpload($file, $this->uploadFolder);
                $uploadedImages[] = [
                    'path' => $path,
                    'url' => url(Storage::url($path)),
                ];
            }

            return response()->json([
                'status' => 'success',
                'message' => count($uploadedImages).' image(s) uploaded successfully',
                'images' => $uploadedImages,
            ]);

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'CategoryController -> uploadGalleryImage');

            return ResponseService::errorResponse('Error uploading images: '.$th->getMessage());
        }
    }

    public function getGalleryImages()
    {
        ResponseService::noPermissionThenSendJson('category-list');

        try {
            $files = Storage::disk(config('filesystems.default'))->files($this->uploadFolder);
            $images = [];

            foreach ($files as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                    $images[] = [
                        'path' => $file,
                        'url' => url(Storage::url($file)),
                    ];
                }
            }

            usort($images, function ($a, $b) {
                $timeA = Storage::disk(config('filesystems.default'))->lastModified($a['path']);
                $timeB = Storage::disk(config('filesystems.default'))->lastModified($b['path']);

                return $timeB <=> $timeA;
            });

            return response()->json([
                'status' => 'success',
                'images' => $images,
            ]);

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'CategoryController -> getGalleryImages');

            return ResponseService::errorResponse('Error loading images: '.$th->getMessage());
        }
    }

    public function processBulkUpload(Request $request)
    {
        ResponseService::noPermissionThenSendJson('category-create');

        $validator = Validator::make($request->all(), [
            'excel_file' => [
                'required',
                'file',
                'max:10240',
                function ($attribute, $value, $fail) {
                    $extension = strtolower($value->getClientOriginalExtension());
                    $mimeType = $value->getMimeType();

                    $allowedExtensions = ['xlsx', 'xls', 'csv'];
                    $allowedMimeTypes = [
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                        'text/csv',
                        'text/plain',
                        'application/csv',
                    ];

                    if (! in_array($extension, $allowedExtensions) && ! in_array($mimeType, $allowedMimeTypes)) {
                        $fail('The '.$attribute.' must be a CSV or Excel file (CSV, XLSX or XLS format).');
                    }
                },
            ],
            'module_id' => 'nullable|integer|exists:modules,id',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            if (! class_exists(Spreadsheet::class)) {
                return ResponseService::errorResponse('PhpSpreadsheet library is not installed. Please run: composer require phpoffice/phpspreadsheet');
            }

            $file = $request->file('excel_file');
            $extension = strtolower($file->getClientOriginalExtension());
            $filePath = $file->getRealPath();

            if (! file_exists($filePath) || ! is_readable($filePath)) {
                return ResponseService::errorResponse('File is not readable. Please try uploading again.');
            }

            $rows = [];
            try {
                if ($extension === 'csv') {
                    $reader = new Csv;
                    $reader->setInputEncoding('UTF-8');
                    $reader->setDelimiter(',');
                    $reader->setEnclosure('"');
                    $reader->setSheetIndex(0);
                } elseif ($extension === 'xlsx') {
                    $reader = new Xlsx;
                } elseif ($extension === 'xls') {
                    $reader = new Xls;
                } else {
                    return ResponseService::errorResponse('Unsupported file format. Only CSV, XLSX and XLS files are supported.');
                }

                $reader->setReadDataOnly(false);
                $reader->setReadEmptyCells(true);

                $spreadsheet = $reader->load($filePath);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray(null, true, true, true);
                $rows = array_values($rows);

                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);

            } catch (ReaderException $ex) {
                return ResponseService::errorResponse('Error reading file: '.$ex->getMessage().'. Please ensure the file is a valid CSV or Excel file.');
            } catch (\Throwable $ex) {
                return ResponseService::errorResponse('Error processing file: '.$ex->getMessage());
            }

            $headerRemoved = false;
            $languageColumnMap = [];

            if (count($rows) > 0) {
                $firstRow = $rows[0];

                if (isset($firstRow['A'])) {
                    $convertedHeader = [];
                    $columnLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];
                    for ($i = 0; $i < 100; $i++) {
                        if ($i < 26) {
                            $colLetter = $columnLetters[$i];
                        } else {
                            $firstIndex = floor(($i - 26) / 26);
                            $secondIndex = ($i - 26) % 26;
                            $colLetter = $columnLetters[$firstIndex] . $columnLetters[$secondIndex];
                        }
                        if (isset($firstRow[$colLetter])) {
                            $convertedHeader[] = $firstRow[$colLetter];
                        } else {
                            break;
                        }
                    }
                    $firstRow = $convertedHeader;
                }

                $firstCell = isset($firstRow[0]) ? strtolower(trim((string) $firstRow[0])) : '';

                if ($firstCell === 'name' || $firstCell === 'id') {
                    $languages = CachingService::getLanguages()->values();
                    foreach ($languages as $lang) {
                        $langCode = strtoupper($lang->code ?? '');
                        if (empty($langCode)) {
                            continue;
                        }

                        foreach ($firstRow as $colIndex => $header) {
                            $headerStr = strtoupper(trim((string) $header));
                            if ($headerStr === 'NAME_'.$langCode) {
                                $languageColumnMap[$langCode]['name_index'] = $colIndex;
                            }
                        }
                    }

                    array_shift($rows);
                    $headerRemoved = true;
                }
            }

            if (count($rows) < 1) {
                return ResponseService::errorResponse('File must contain at least one data row (excluding header)');
            }

            // Root rows land in the selected module (the default one when the
            // form sends none); child rows inherit their parent's, which must
            // match it.
            $moduleId = $request->filled('module_id') ? (int) $request->input('module_id') : ModuleService::defaultModuleId();
            if (! $moduleId || ! Module::active()->whereKey($moduleId)->exists()) {
                return ResponseService::validationError(__('The selected module is invalid.'));
            }

            $languages = CachingService::getLanguages()->values();
            $defaultLangId = 1;
            $otherLanguages = $languages->where('id', '!=', $defaultLangId);
            $successCount = 0;
            $errorCount = 0;
            $errors = [];

            DB::beginTransaction();
            foreach ($rows as $rowIndex => $row) {
                $rowNumber = $rowIndex + 1 + ($headerRemoved ? 1 : 0);

                try {
                    $isAssociative = isset($row['A']) || (is_array($row) && !empty($row) && !array_key_exists(0, $row) && array_key_exists('A', $row));

                    if ($isAssociative) {
                        $convertedRow = [];
                        $columnLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];
                        $maxColumns = 10 + $otherLanguages->count();

                        for ($i = 0; $i < $maxColumns; $i++) {
                            if ($i < 26) {
                                $colLetter = $columnLetters[$i];
                            } else {
                                $firstIndex = floor(($i - 26) / 26);
                                $secondIndex = ($i - 26) % 26;
                                $colLetter = $columnLetters[$firstIndex] . $columnLetters[$secondIndex];
                            }
                            $convertedRow[] = isset($row[$colLetter]) ? $row[$colLetter] : '';
                        }

                        $row = $convertedRow;
                    } else {
                        $maxColumns = 10 + $otherLanguages->count();
                        while (count($row) < $maxColumns) {
                            $row[] = '';
                        }
                    }

                    if (! is_array($row)) {
                        $row = [];
                    }
                    $minColumns = 9 + $otherLanguages->count();
                    while (count($row) < $minColumns) {
                        $row[] = '';
                    }

                    if (empty(array_filter($row, function ($val) {
                        return $val !== null && trim((string) $val) !== '';
                    }))) {
                        continue;
                    }

                    $name = trim((string) ($row[0] ?? ''));
                    $image = trim((string) ($row[1] ?? ''));
                    $parentCategoryId = trim((string) ($row[2] ?? ''));
                    $status = trim((string) ($row[3] ?? '1'));
                    $isJobCategory = trim((string) ($row[4] ?? '0'));
                    $slug = trim((string) ($row[5] ?? ''));
                    $metaTitle = trim((string) ($row[6] ?? ''));
                    $metaDescription = trim((string) ($row[7] ?? ''));
                    $metaKeywords = trim((string) ($row[8] ?? ''));

                    $translationData = [];

                    if (!empty($languageColumnMap)) {
                        foreach ($otherLanguages as $lang) {
                            $langCode = strtoupper($lang->code ?? '');
                            if (empty($langCode) || !isset($languageColumnMap[$langCode])) {
                                continue;
                            }

                            $nameIndex = $languageColumnMap[$langCode]['name_index'] ?? null;

                            if ($nameIndex === null) {
                                continue;
                            }

                            $tNameRaw = ($nameIndex !== null && isset($row[$nameIndex])) ? (string) $row[$nameIndex] : '';

                            $tName = trim($tNameRaw);

                            $translationData[$lang->id] = [
                                'name' => $tName,
                            ];
                        }
                    } else {
                        $baseTranslationIndex = 9;
                        foreach ($otherLanguages as $index => $lang) {
                            $nameIndex = $baseTranslationIndex + $index;

                            $tName = isset($row[$nameIndex]) ? trim((string) $row[$nameIndex]) : '';

                            $translationData[$lang->id] = [
                                'name' => $tName,
                            ];
                        }
                    }

                    $firstCellLower = strtolower($name);
                    if ($firstCellLower === 'name' || $firstCellLower === 'id') {
                        continue;
                    }

                    $missingFields = [];
                    if (empty($name)) {
                        $missingFields[] = 'Name';
                    }

                    if (! empty($missingFields)) {
                        $errors[] = "Row $rowNumber: Missing required field(s): ".implode(', ', $missingFields);
                        $errorCount++;
                        continue;
                    }

                    if (! in_array($status, ['0', '1'])) {
                        $errors[] = "Row $rowNumber: Invalid status value '$status'. Must be 0 (Inactive) or 1 (Active)";
                        $errorCount++;
                        continue;
                    }

                    if (! in_array($isJobCategory, ['0', '1'])) {
                        $errors[] = "Row $rowNumber: Invalid job category value '$isJobCategory'. Must be 0 or 1";
                        $errorCount++;
                        continue;
                    }

                    if (! empty($parentCategoryId)) {
                        $parent = is_numeric($parentCategoryId)
                            ? Category::without('translations')->find($parentCategoryId, ['id', 'module_id'])
                            : null;

                        if (! $parent) {
                            $errors[] = "Row $rowNumber: Invalid parent category ID: $parentCategoryId";
                            $errorCount++;
                            continue;
                        }

                        if ((int) $parent->module_id !== (int) $moduleId) {
                            $errors[] = "Row $rowNumber: Parent category ID $parentCategoryId belongs to a different module";
                            $errorCount++;
                            continue;
                        }
                    }

                    $toggleErrors = $this->categoryToggleErrors($moduleId, ['is_job_category' => (int) $isJobCategory]);
                    if ($toggleErrors) {
                        $errors[] = "Row $rowNumber: ".reset($toggleErrors);
                        $errorCount++;
                        continue;
                    }

                    // Image is optional; when given, the path must exist
                    if ($image !== '' && ! Storage::disk(config('filesystems.default'))->exists($image)) {
                        $errors[] = "Row $rowNumber: Image path does not exist: $image";
                        $errorCount++;
                        continue;
                    }

                    if (strlen($name) > 30) {
                        $errors[] = "Row $rowNumber: Name cannot exceed 30 characters";
                        $errorCount++;
                        continue;
                    }

                    $categoryData = [
                        'name' => $name,
                        'module_id' => $moduleId,
                        'image' => $image !== '' ? $image : null,
                        'parent_category_id' => ! empty($parentCategoryId) ? (int)$parentCategoryId : null,
                        'status' => (int)$status,
                        'is_job_category' => (int)$isJobCategory,
                    ];

                    if (empty($slug)) {
                        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($name));
                        $slug = trim($slug, '-');
                    } else {
                        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($slug));
                        $slug = trim($slug, '-');
                    }

                    $categoryData['slug'] = HelperService::generateUniqueSlug(new Category, $slug);

                    $category = Category::create($categoryData);

                    foreach ($otherLanguages as $lang) {
                        $langId = $lang->id;
                        $tName = $translationData[$langId]['name'] ?? '';

                        if (! empty($tName)) {
                            HelperService::storeTranslations([
                                ['translatable_id' => $category->id, 'translatable_type' => \App\Models\Category::class, 'key' => 'name', 'value' => $tName, 'language_id' => $langId],
                            ]);
                        }
                    }

                    if ($metaTitle !== '' || $metaDescription !== '' || $metaKeywords !== '') {
                        $category->seoDetail()->updateOrCreate(
                            ['seoable_id' => $category->id, 'seoable_type' => Category::class],
                            [
                                'meta_title'       => $metaTitle !== '' ? $metaTitle : null,
                                'meta_description' => $metaDescription !== '' ? $metaDescription : null,
                                'meta_keywords'    => $metaKeywords !== '' ? $metaKeywords : null,
                            ]
                        );
                    }

                    $successCount++;
                } catch (\Throwable $th) {
                    $errors[] = "Row $rowNumber: ".$th->getMessage();
                    $errorCount++;
                    ResponseService::logErrorResponse($th, "CategoryController -> processBulkUpload Row $rowNumber");
                }
            }
            if ($errorCount > 0 && $successCount === 0) {
                DB::rollBack();
                $errorMessage = 'All rows failed to process. ';
                if (count($errors) <= 10) {
                    $errorMessage .= 'Errors: '.implode('; ', $errors);
                } else {
                    $errorMessage .= 'First 10 errors: '.implode('; ', array_slice($errors, 0, 10)).' (and '.(count($errors) - 10).' more)';
                }

                return ResponseService::errorResponse($errorMessage);
            }

            DB::commit();

            if ($successCount > 0 && $errorCount > 0) {
                $message = 'Bulk upload partially completed. ';
                $message .= "$successCount row(s) processed successfully. ";
                $message .= "$errorCount row(s) failed. ";
                if (count($errors) <= 5) {
                    $message .= 'Errors: '.implode('; ', $errors);
                } else {
                    $message .= 'First 5 errors: '.implode('; ', array_slice($errors, 0, 5)).' (and '.(count($errors) - 5).' more)';
                }

                return ResponseService::successResponse($message);
            } elseif ($successCount > 0) {
                $message = "Bulk upload completed successfully. $successCount category(ies) created.";

                return ResponseService::successResponse($message);
            } else {
                return ResponseService::errorResponse('No rows were processed. Please check your file format.');
            }

        } catch (\Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th, 'CategoryController -> processBulkUpload');

            return ResponseService::errorResponse('Error processing file: '.$th->getMessage());
        }
    }

    public function processBulkUpdate(Request $request)
    {
        ResponseService::noPermissionThenSendJson('category-update');

        $validator = Validator::make($request->all(), [
            'excel_file' => [
                'required',
                'file',
                'max:10240',
                function ($attribute, $value, $fail) {
                    $extension = strtolower($value->getClientOriginalExtension());
                    $mimeType = $value->getMimeType();

                    $allowedExtensions = ['xlsx', 'xls', 'csv'];
                    $allowedMimeTypes = [
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                        'text/csv',
                        'text/plain',
                        'application/csv',
                    ];

                    if (! in_array($extension, $allowedExtensions) && ! in_array($mimeType, $allowedMimeTypes)) {
                        $fail('The '.$attribute.' must be a CSV or Excel file (CSV, XLSX or XLS format).');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            if (! class_exists(Spreadsheet::class)) {
                return ResponseService::errorResponse('PhpSpreadsheet library is not installed. Please run: composer require phpoffice/phpspreadsheet');
            }

            $file = $request->file('excel_file');
            $extension = strtolower($file->getClientOriginalExtension());
            $filePath = $file->getRealPath();

            if (! file_exists($filePath) || ! is_readable($filePath)) {
                return ResponseService::errorResponse('File is not readable. Please try uploading again.');
            }

            $rows = [];
            try {
                if ($extension === 'csv') {
                    $reader = new Csv;
                    $reader->setInputEncoding('UTF-8');
                    $reader->setDelimiter(',');
                    $reader->setEnclosure('"');
                    $reader->setSheetIndex(0);
                } elseif ($extension === 'xlsx') {
                    $reader = new Xlsx;
                } elseif ($extension === 'xls') {
                    $reader = new Xls;
                } else {
                    return ResponseService::errorResponse('Unsupported file format. Only CSV, XLSX and XLS files are supported.');
                }

                $reader->setReadDataOnly(false);
                $reader->setReadEmptyCells(true);

                $spreadsheet = $reader->load($filePath);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray(null, true, true, true);
                $rows = array_values($rows);

                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);

            } catch (ReaderException $ex) {
                return ResponseService::errorResponse('Error reading file: '.$ex->getMessage().'. Please ensure the file is a valid CSV or Excel file.');
            } catch (\Throwable $ex) {
                return ResponseService::errorResponse('Error processing file: '.$ex->getMessage());
            }

            $headerRemoved = false;
            $languageColumnMap = [];
            $moduleColumnIndex = null;
            if (count($rows) > 0) {
                $firstRow = $rows[0];

                if (isset($firstRow['A'])) {
                    $convertedHeader = [];
                    $columnLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];
                    for ($i = 0; $i < 100; $i++) {
                        if ($i < 26) {
                            $colLetter = $columnLetters[$i];
                        } else {
                            $firstIndex = floor(($i - 26) / 26);
                            $secondIndex = ($i - 26) % 26;
                            $colLetter = $columnLetters[$firstIndex] . $columnLetters[$secondIndex];
                        }
                        if (isset($firstRow[$colLetter])) {
                            $convertedHeader[] = $firstRow[$colLetter];
                        } else {
                            break;
                        }
                    }
                    $firstRow = $convertedHeader;
                }

                $firstCell = isset($firstRow[0]) ? strtolower(trim((string) $firstRow[0])) : '';

                if ($firstCell === 'id') {
                    // Read by header: files exported before the column existed
                    // still work and leave every category's module unchanged.
                    foreach ($firstRow as $colIndex => $header) {
                        if (strtolower(trim((string) $header)) === 'module id') {
                            $moduleColumnIndex = $colIndex;
                        }
                    }

                    $languages = CachingService::getLanguages()->values();
                    foreach ($languages as $lang) {
                        $langCode = strtoupper($lang->code ?? '');
                        if (empty($langCode)) {
                            continue;
                        }

                        foreach ($firstRow as $colIndex => $header) {
                            $headerStr = strtoupper(trim((string) $header));
                            if ($headerStr === 'NAME_'.$langCode) {
                                $languageColumnMap[$langCode]['name_index'] = $colIndex;
                            }
                        }
                    }

                    array_shift($rows);
                    $headerRemoved = true;
                }
            }

            if (count($rows) < 1) {
                return ResponseService::errorResponse('File must contain at least one data row (excluding header)');
            }

            $languages = CachingService::getLanguages()->values();
            $defaultLangId = 1;
            $otherLanguages = $languages->where('id', '!=', $defaultLangId);

            $successCount = 0;
            $errorCount = 0;
            $errors = [];
            $maxErrorsToReturn = 100;
            $batchSize = 50;

            $batches = array_chunk($rows, $batchSize, true);

            foreach ($batches as $batchIndex => $batch) {
                DB::beginTransaction();

                try {
                    foreach ($batch as $rowIndex => $row) {
                        $rowNumber = $rowIndex + 1 + ($headerRemoved ? 1 : 0);

                        try {
                            if (isset($row['A'])) {
                                $convertedRow = [];
                                $columnLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];
                                $maxColumns = 12 + $otherLanguages->count();

                                for ($i = 0; $i < $maxColumns; $i++) {
                                    if ($i < 26) {
                                        $colLetter = $columnLetters[$i];
                                    } else {
                                        $firstIndex = floor(($i - 26) / 26);
                                        $secondIndex = ($i - 26) % 26;
                                        $colLetter = $columnLetters[$firstIndex] . $columnLetters[$secondIndex];
                                    }
                                    $convertedRow[] = $row[$colLetter] ?? '';
                                }

                                $row = $convertedRow;
                            }

                            if (! is_array($row)) {
                                $row = [];
                            }
                            $minColumns = 10 + $otherLanguages->count();
                            while (count($row) < $minColumns) {
                                $row[] = '';
                            }

                            if (empty(array_filter($row, function ($val) {
                                return $val !== null && trim((string) $val) !== '';
                            }))) {
                                continue;
                            }

                            $id = trim((string) ($row[0] ?? ''));
                            $name = trim((string) ($row[1] ?? ''));
                            $image = trim((string) ($row[2] ?? ''));
                            $parentCategoryId = trim((string) ($row[3] ?? ''));
                            $status = trim((string) ($row[4] ?? '1'));
                            $isJobCategory = trim((string) ($row[5] ?? '0'));
                            $slug = trim((string) ($row[6] ?? ''));
                            $metaTitle = trim((string) ($row[7] ?? ''));
                            $metaDescription = trim((string) ($row[8] ?? ''));
                            $metaKeywords = trim((string) ($row[9] ?? ''));
                            $moduleCell = $moduleColumnIndex !== null ? trim((string) ($row[$moduleColumnIndex] ?? '')) : '';

                            $translationData = [];

                            if (!empty($languageColumnMap)) {
                                foreach ($otherLanguages as $lang) {
                                    $langCode = strtoupper($lang->code ?? '');
                                    if (empty($langCode) || !isset($languageColumnMap[$langCode])) {
                                        continue;
                                    }

                                    $nameIndex = $languageColumnMap[$langCode]['name_index'] ?? null;

                                    if ($nameIndex === null) {
                                        continue;
                                    }

                                    $tNameRaw = ($nameIndex !== null && isset($row[$nameIndex])) ? (string) $row[$nameIndex] : '';

                                    $tName = trim($tNameRaw);

                                    $translationData[$lang->id] = [
                                        'name' => $tName,
                                    ];
                                }
                            } else {
                                $baseTranslationIndex = 10;
                                foreach ($otherLanguages as $index => $lang) {
                                    $nameIndex = $baseTranslationIndex + $index;

                                    $tName = isset($row[$nameIndex]) ? trim((string) $row[$nameIndex]) : '';

                                    $translationData[$lang->id] = [
                                        'name' => $tName,
                                    ];
                                }
                            }

                            $firstCellLower = strtolower($id);
                            if ($firstCellLower === 'id') {
                                continue;
                            }

                            if (empty($id)) {
                                if (count($errors) < $maxErrorsToReturn) {
                                    $errors[] = ['row' => $rowNumber, 'message' => 'ID is required for updates'];
                                }
                                $errorCount++;
                                continue;
                            }

                            if (! is_numeric($id)) {
                                if (count($errors) < $maxErrorsToReturn) {
                                    $errors[] = ['row' => $rowNumber, 'message' => 'Invalid ID format'];
                                }
                                $errorCount++;
                                continue;
                            }

                            $category = Category::with('translations')->find($id);
                            if (! $category) {
                                if (count($errors) < $maxErrorsToReturn) {
                                    $errors[] = ['row' => $rowNumber, 'message' => "Category with ID $id not found"];
                                }
                                $errorCount++;
                                continue;
                            }

                            $missingFields = [];
                            if (empty($name)) {
                                $missingFields[] = 'Name';
                            }

                            if (! empty($missingFields)) {
                                if (count($errors) < $maxErrorsToReturn) {
                                    $errors[] = ['row' => $rowNumber, 'message' => 'Missing required field(s): '.implode(', ', $missingFields)];
                                }
                                $errorCount++;
                                continue;
                            }

                            if (! in_array($status, ['0', '1'])) {
                                if (count($errors) < $maxErrorsToReturn) {
                                    $errors[] = ['row' => $rowNumber, 'message' => "Invalid status value '$status'. Must be 0 (Inactive) or 1 (Active)"];
                                }
                                $errorCount++;
                                continue;
                            }

                            if (! in_array($isJobCategory, ['0', '1'])) {
                                if (count($errors) < $maxErrorsToReturn) {
                                    $errors[] = ['row' => $rowNumber, 'message' => "Invalid job category value '$isJobCategory'. Must be 0 or 1"];
                                }
                                $errorCount++;
                                continue;
                            }

                            if (! empty($parentCategoryId) && (! is_numeric($parentCategoryId) || ! Category::where('id', $parentCategoryId)->exists())) {
                                if (count($errors) < $maxErrorsToReturn) {
                                    $errors[] = ['row' => $rowNumber, 'message' => "Invalid parent category ID: $parentCategoryId"];
                                }
                                $errorCount++;
                                continue;
                            }

                            if ($category->id == $parentCategoryId) {
                                if (count($errors) < $maxErrorsToReturn) {
                                    $errors[] = ['row' => $rowNumber, 'message' => 'A category cannot be set as its own parent'];
                                }
                                $errorCount++;
                                continue;
                            }

                            // A category always follows its parent's module
                            // (Category::booted()): a blank Module ID keeps the
                            // current one, a root category may move (its
                            // subcategories follow), and a subcategory must
                            // match its parent — reparenting across modules
                            // is rejected, same rule as update().
                            $currentModuleId = (int) $category->module_id;
                            $targetModuleId = $currentModuleId;

                            if ($moduleCell !== '') {
                                $activeModuleIds ??= Module::active()->pluck('id')->map(fn ($id) => (int) $id)->all();

                                if (! ctype_digit($moduleCell) || ! in_array((int) $moduleCell, $activeModuleIds, true)) {
                                    if (count($errors) < $maxErrorsToReturn) {
                                        $errors[] = ['row' => $rowNumber, 'message' => "Invalid module ID: $moduleCell"];
                                    }
                                    $errorCount++;
                                    continue;
                                }
                                $targetModuleId = (int) $moduleCell;
                            }

                            $effectiveParentId = ! empty($parentCategoryId) ? (int) $parentCategoryId : $category->parent_category_id;
                            if ($effectiveParentId) {
                                $parentModuleId = (int) Category::whereKey($effectiveParentId)->value('module_id');

                                if (! empty($parentCategoryId) && $parentModuleId !== $currentModuleId) {
                                    if (count($errors) < $maxErrorsToReturn) {
                                        $errors[] = ['row' => $rowNumber, 'message' => 'A category cannot be reparented into a different module'];
                                    }
                                    $errorCount++;
                                    continue;
                                }

                                if ($targetModuleId !== $parentModuleId) {
                                    if (count($errors) < $maxErrorsToReturn) {
                                        $errors[] = ['row' => $rowNumber, 'message' => 'A subcategory always belongs to its parent category\'s module'];
                                    }
                                    $errorCount++;
                                    continue;
                                }
                            }

                            // An empty parent column keeps the current parent (see below).
                            $toggleErrors = $this->categoryToggleErrors(
                                $targetModuleId,
                                ['is_job_category' => (int) $isJobCategory],
                                $category
                            );
                            if ($toggleErrors) {
                                if (count($errors) < $maxErrorsToReturn) {
                                    $errors[] = ['row' => $rowNumber, 'message' => reset($toggleErrors)];
                                }
                                $errorCount++;
                                continue;
                            }

                            if (! empty($image) && ! Storage::disk(config('filesystems.default'))->exists($image)) {
                                if (count($errors) < $maxErrorsToReturn) {
                                    $errors[] = ['row' => $rowNumber, 'message' => "Image path does not exist: $image"];
                                }
                                $errorCount++;
                                continue;
                            }

                            if (strlen($name) > 100) {
                                if (count($errors) < $maxErrorsToReturn) {
                                    $errors[] = ['row' => $rowNumber, 'message' => 'Name cannot exceed 100 characters'];
                                }
                                $errorCount++;
                                continue;
                            }

                            $updateData = [
                                'name' => $name,
                                'status' => (int)$status,
                                'is_job_category' => (int)$isJobCategory,
                            ];

                            if (! empty($parentCategoryId)) {
                                $updateData['parent_category_id'] = (int)$parentCategoryId;
                            }

                            if ($targetModuleId !== $currentModuleId) {
                                $updateData['module_id'] = $targetModuleId;
                            }

                            if (! empty($image)) {
                                $updateData['image'] = $image;
                            }

                            if (! empty($slug)) {
                                $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($slug));
                                $slug = trim($slug, '-');
                                $updateData['slug'] = HelperService::generateUniqueSlug(new Category(), $slug, $category->id);
                            }

                            $category->update($updateData);

                            foreach ($otherLanguages as $lang) {
                                $langId = $lang->id;
                                $tName = $translationData[$langId]['name'] ?? '';

                                if (empty($tName)) {
                                    continue;
                                }

                                HelperService::storeTranslations([
                                    ['translatable_id' => $category->id, 'translatable_type' => \App\Models\Category::class, 'key' => 'name', 'value' => $tName, 'language_id' => $langId],
                                ]);
                            }

                            $seoUpdate = [];
                            if ($metaTitle !== '') {
                                $seoUpdate['meta_title'] = $metaTitle;
                            }
                            if ($metaDescription !== '') {
                                $seoUpdate['meta_description'] = $metaDescription;
                            }
                            if ($metaKeywords !== '') {
                                $seoUpdate['meta_keywords'] = $metaKeywords;
                            }
                            if (! empty($seoUpdate)) {
                                $category->seoDetail()->updateOrCreate(
                                    ['seoable_id' => $category->id, 'seoable_type' => Category::class],
                                    $seoUpdate
                                );
                            }

                            $successCount++;
                        } catch (\Throwable $th) {
                            if (count($errors) < $maxErrorsToReturn) {
                                $errors[] = [
                                    'row' => $rowNumber,
                                    'message' => $th->getMessage(),
                                ];
                            }
                            $errorCount++;
                            ResponseService::logErrorResponse($th, "CategoryController -> processBulkUpdate Row $rowNumber");
                        }
                    }

                    DB::commit();
                } catch (\Throwable $batchException) {
                    DB::rollBack();
                    ResponseService::logErrorResponse($batchException, "CategoryController -> processBulkUpdate Batch $batchIndex");
                }
            }

            $responseData = [
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'total_processed' => $successCount + $errorCount,
                'errors' => $errors,
                'has_more_errors' => $errorCount > count($errors),
            ];

            if ($errorCount > 0 && $successCount === 0) {
                $message = "All rows failed to process. $errorCount error(s) found.";

                return ResponseService::errorResponse($message, $responseData);
            }

            if ($successCount > 0 && $errorCount > 0) {
                $message = "Bulk update partially completed. $successCount row(s) updated successfully. $errorCount row(s) failed.";

                return ResponseService::warningResponse($message, $responseData);
            } elseif ($successCount > 0) {
                $message = "Bulk update completed successfully. $successCount category(ies) updated.";

                return ResponseService::successResponse($message, $responseData);
            } else {
                return ResponseService::errorResponse('No rows were processed. Please check your file format.', $responseData);
            }

        } catch (\Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th, 'CategoryController -> processBulkUpdate');

            return ResponseService::errorResponse('Error processing file: '.$th->getMessage());
        }
    }
}
