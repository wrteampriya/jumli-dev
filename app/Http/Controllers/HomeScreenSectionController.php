<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\HomeScreenSection;
use App\Models\Module;
use App\Models\PopularCategory;
use App\Services\BootstrapTableService;
use App\Services\CategoryTree;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;
use App\Services\Module\ModuleService;

class HomeScreenSectionController extends Controller
{
    public function index(Request $request)
    {
        ResponseService::noAnyPermissionThenRedirect(['home-screen-section-list', 'home-screen-section-update']);

        $modules = Module::active()->orderBy('sequence')->get();

        // Sections are per module: show the chosen module's rows (the default module first),
        // creating any it's missing so all can be shown / hidden.
        $sectionModuleId = $modules->firstWhere('id', $request->integer('module_id'))?->id ?? $this->defaultModuleId($modules);
        $sections = collect();
        if ($sectionModuleId) {
            HomeScreenSection::ensureForModule($sectionModuleId);
            $sections = HomeScreenSection::where('module_id', $sectionModuleId)->ordered()->get();
        }

        return view('home_screen_section.index', compact('sections', 'modules', 'sectionModuleId'));
    }

    public function popularCategoriesIndex()
    {
        ResponseService::noAnyPermissionThenRedirect(['home-screen-section-list', 'home-screen-section-update']);

        $modules = Module::active()->orderBy('sequence')->get();
        $popularCategoriesCount = PopularCategory::count();

        return view('home_screen_section.popular-categories', compact('modules', 'popularCategoriesCount'));
    }

    /**
     * "Add Popular Categories" drawer: each module's active category tree with checkboxes.
     * Categories already popular are shown checked and disabled.
     */
    public function popularCategoriesCreate()
    {
        ResponseService::noPermissionThenRedirect('home-screen-section-update');

        // Only the accordion heads render here; each module's tree loads from
        // lookup.categories the first time its accordion opens.
        $activeModules = Module::active()->orderBy('sequence')->get(['id', 'name', 'icon']);

        // Active categories per module.
        $counts = Category::where('status', 1)->toBase()
            ->selectRaw('module_id, COUNT(*) as total')->groupBy('module_id')
            ->pluck('total', 'module_id');

        $modules = $activeModules
            ->each(fn ($module) => $module->setAttribute('category_count',
                (int) ($counts[$module->id] ?? 0)))
            ->filter(fn ($module) => $module->category_count > 0)
            ->values();
        $showModuleHeads = $activeModules->count() > 1;
        $popularIds = PopularCategory::pluck('category_id')->all();

        return view('home_screen_section.popular-categories-create', compact('modules', 'showModuleHeads', 'popularIds'));
    }

    /**
     * @deprecated Route commented out in routes/web.php — the add form now reloads from
     * lookup.categories?exclude_popular=1 (AdminLookupController::categories). Delete once confirmed.
     */
    public function popularCategoryOptionsList(Request $request)
    {
        ResponseService::noPermissionThenSendJson('home-screen-section-update');

        $request->validate([
            'module_id' => 'nullable|integer|exists:modules,id',
        ]);

        ResponseService::successResponse('Data Fetched Successfully', $this->popularCategoryOptions($request->integer('module_id') ?: null));
    }

    public function popularCategoriesList(Request $request)
    {
        ResponseService::noAnyPermissionThenSendJson(['home-screen-section-list', 'home-screen-section-update']);

        $offset = (int) $request->input('offset', 0);
        $limit = (int) $request->input('limit', 10);
        $sort = in_array($request->input('sort'), ['id', 'sequence', 'status']) ? $request->input('sort') : 'sequence';
        $order = strtolower((string) $request->input('order')) === 'desc' ? 'desc' : 'asc';

        $sql = PopularCategory::with('category.module', 'category.parent')
            ->when($request->filled('module_id'), fn ($q) => $q->forModule((int) $request->module_id))
            ->when(in_array($request->input('status'), ['0', '1'], true), fn ($q) => $q->where('status', (bool) $request->status))
            ->when($request->filled('search'), fn ($q) => $q->whereHas('category', fn ($c) => $c->whereLike('name', '%'.$request->search.'%')))
            ->orderBy($sort, $order);

        $total = $sql->count();
        $multiModule = Module::active()->count() > 1;
        $canUpdate = Auth::user()->can('home-screen-section-update');

        $rows = $sql->skip($offset)->take($limit)->get()->map(function ($pc) use ($multiModule, $canUpdate) {
            $category = $pc->category;
            $module = $category?->module?->name ?? ModuleService::defaultModule()?->name ?? '';
            return [
                'id' => $pc->id,
                'sequence' => $pc->sequence,
                'module_name' => $module,
                // A subcategory shows under its parent; a top-level category has no subcategory.
                'category_name' => $category ? ($category->parent->name ?? $category->name) : trans('Deleted Category'),
                'subcategory_name' => $category && $category->parent_category_id ? $category->name : '',
                'used_on' => $multiModule ? trans(':module Homepage', ['module' => $module]) : trans('Homepage'),
                'status' => $pc->status,
                'operate' => $canUpdate ? BootstrapTableService::deleteButton(route('home-screen-section.popular-categories.delete', $pc->id)) : '',
            ];
        });

        return response()->json(['total' => $total, 'rows' => $rows]);
    }

    // "Add Popular Categories" drawer: several categories at once, each added last in its module's list.
    public function storePopularCategory(Request $request)
    {
        ResponseService::noPermissionThenSendJson('home-screen-section-update');

        $validator = Validator::make($request->all(), [
            'category_ids' => 'required|array|min:1',
            'category_ids.*' => ['required', 'distinct', Rule::exists('categories', 'id')->where('status', 1), 'unique:popular_categories,category_id'],
        ], [
            'category_ids.required' => trans('Select at least one category.'),
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::transaction(function () use ($request) {
                foreach (Category::whereIn('id', $request->input('category_ids'))->get(['id', 'module_id']) as $category) {
                    // Sequences are per module.
                    $maxSequence = PopularCategory::forModule($category->module_id)->max('sequence') ?? 0;
                    PopularCategory::create(['category_id' => $category->id, 'sequence' => $maxSequence + 1]);
                }
            });

            ResponseService::successResponse('Categories Added Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'HomeScreenSectionController -> storePopularCategory');
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    public function deletePopularCategory($id)
    {
        ResponseService::noPermissionThenSendJson('home-screen-section-update');

        try {
            PopularCategory::findOrFail($id)->delete();
            ResponseService::successResponse('Category Removed Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th);
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    public function popularCategoriesOrder(Request $request)
    {
        ResponseService::noAnyPermissionThenRedirect(['home-screen-section-list', 'home-screen-section-update']);

        $modules = Module::active()->orderBy('sequence')->get();
        $selectedModuleId = $modules->contains('id', $request->integer('module_id'))
            ? $request->integer('module_id')
            : $this->defaultModuleId($modules);

        $popularCategories = PopularCategory::with('category')
            ->forModule($selectedModuleId)
            ->orderBy('sequence')
            ->get();

        return view('home_screen_section.popular-categories-order', compact('popularCategories', 'modules', 'selectedModuleId'));
    }

    public function updatePopularCategoryOrder(Request $request)
    {
        ResponseService::noPermissionThenSendJson('home-screen-section-update');

        $request->validate([
            'order' => 'required',
        ]);

        try {
            // The order page lists one module's categories, so this renumbers
            // just that module's list (1..n); other modules keep theirs.
            $order = json_decode($request->input('order'), true);
            $data = [];
            foreach ($order as $index => $id) {
                $data[] = [
                    'id' => $id,
                    'sequence' => $index + 1,
                ];
            }
            PopularCategory::upsert($data, ['id'], ['sequence']);
            ResponseService::successResponse('Order Updated Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th);
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    private function defaultModuleId($modules): ?int
    {
        return $modules->first()?->id;
    }

    /**
     * Not-yet-popular active categories of the module, flattened in tree order with their
     * depth — the add form's first render. Module changes reload the list from
     * lookup.categories?exclude_popular=1, which applies the same rules.
     * A category whose parent is already popular (so excluded here) is listed at the
     * top level instead of being dropped (CategoryTree::flatten).
     */
    private function popularCategoryOptions(?int $moduleId): array
    {
        // Only active categories can be made popular.
        $categories = Category::forModule($moduleId)
            ->where('status', 1)
            ->whereNotIn('id', PopularCategory::select('category_id'))
            ->orderBy('sequence')
            ->get(['id', 'name', 'parent_category_id']);

        return CategoryTree::flatten($categories)
            ->map(fn ($category) => ['id' => $category->id, 'name' => $category->name, 'level' => $category->level])
            ->all();
    }
}
