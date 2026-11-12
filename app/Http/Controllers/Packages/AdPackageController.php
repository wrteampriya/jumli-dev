<?php

namespace App\Http\Controllers\Packages;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Packages\AdPackageRequest;
use App\Models\Category;
use App\Models\Module;
use App\Models\Package;
use App\Models\PackageCategory;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\Packages\PackageModules;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sidebar → Package Management → "Ad Packages" (type item_listing): listing limits,
 * video ads and an optional category scope. Routes: ad-packages.*
 *
 * Every query is scoped to type item_listing, so a Featured package can't be listed, edited
 * or discontinued through these URLs (404). Subscriptions, payment transactions and bank
 * transfers stay on App\Http\Controllers\PackageController.
 */
class AdPackageController extends Controller
{
    private const TYPE = 'item_listing';

    private const DEFAULT_LANGUAGE_ID = 1;

    private const UPLOAD_FOLDER = 'packages';

    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect(['advertisement-listing-package-list', 'advertisement-listing-package-create', 'advertisement-listing-package-update', 'advertisement-listing-package-delete']);

        return view('packages.ad.index', [
            'modules' => Module::active()->orderBy('sequence')->get(),
            // No module has Ad packages enabled → the Create button is disabled with a hint.
            'canCreateForAnyModule' => PackageModules::allowedFor(self::TYPE)->isNotEmpty(),
        ] + $this->viewMeta());
    }

    /** bootstrap-table data for the Ad packages list (route ad-packages.show, any id). */
    public function show(Request $request)
    {
        ResponseService::noPermissionThenSendJson('advertisement-listing-package-list');

        $offset = $request->offset ?? 0;
        $limit = $request->limit ?? 10;
        $sort = in_array($request->sort, ['id', 'name', 'price', 'discount_in_percentage', 'final_price', 'duration', 'status', 'is_reel_allowed', 'total_revenue', 'total_subscribers', 'active_subscribers', 'discontinued_at'], true) ? $request->sort : 'id';
        $order = strtoupper((string) $request->order) === 'ASC' ? 'ASC' : 'DESC';

        $today = date('Y-m-d');
        $discontinuedParam = $request->input('is_discontinued'); // null = all, 0 = active, 1 = discontinued
        $isDiscontinued = $discontinuedParam === '1' || $discontinuedParam === 1;
        $activeSubscriptions = fn ($query) => $query->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today));

        $sql = Package::with(['translations', 'categories', 'module:id,name'])
            ->where('type', self::TYPE)
            ->forModule($request->input('module_id'))
            ->when($discontinuedParam !== null && $discontinuedParam !== '', fn ($q) => $q->where('is_discontinued', $isDiscontinued ? 1 : 0))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->withSum(['payment_transactions as total_revenue' => function ($query) {
                $query->whereIn('payment_status', ['succeed', 'succeeded', 'success', 'approved', 'completed']);
            }], 'amount')
            ->withCount([
                'user_purchased_packages as total_subscribers' => $activeSubscriptions,
                'user_purchased_packages as active_subscribers' => $activeSubscriptions,
            ]);

        $total = $sql->count();
        $result = $sql->orderBy($sort, $order)->skip($offset)->take($limit)->get();

        $canUpdate = Auth::user()->can('advertisement-listing-package-update');
        $canSeeSubscribers = Auth::user()->can('user-package-list');

        $rows = $result->map(function (Package $row) use ($isDiscontinued, $canUpdate, $canSeeSubscribers) {
            $tempRow = $row->toArray();
            $tempRow['category_names'] = $row->is_global == 1 ? 'Global' : 'Category Based';
            $tempRow['discontinued_at'] = $row->discontinued_at ? Carbon::parse($row->discontinued_at)->format('d-m-Y H:i') : null;
            $tempRow['total_revenue'] = round($row->total_revenue ?? 0, 2);
            $tempRow['module_name'] = $row->module->name ?? '';

            $operate = '';
            if ($canSeeSubscribers) {
                $operate .= BootstrapTableService::button('ph ph-users', '#', [], [
                    'title' => __('View Subscribers'),
                    'data-bs-toggle' => 'modal',
                    'data-bs-target' => '#subscribersModal',
                    'data-package-id' => $row->id,
                    'data-package-name' => htmlspecialchars($row->name, ENT_QUOTES),
                    'onclick' => 'loadSubscribers(this)',
                ]);
            }
            if ($canUpdate && ! $isDiscontinued && $row->is_discontinued == 0) {
                $operate .= BootstrapTableService::editButton(route('ad-packages.edit', $row->id));
                $operate .= BootstrapTableService::button('ph ph-prohibit', '#', ['btn-outline-danger', 'discontinue-btn'], [
                    'title' => __('Discontinue Package'),
                    'data-id' => $row->id,
                    'data-name' => htmlspecialchars($row->name, ENT_QUOTES),
                    'data-url' => route('ad-packages.discontinue', $row->id),
                    'data-subscribers' => $row->total_subscribers,
                ]);
            }
            if ($operate !== '') {
                $tempRow['operate'] = $operate;
            }

            return $tempRow;
        });

        return response()->json(['total' => $total, 'rows' => $rows]);
    }

    public function create()
    {
        ResponseService::noPermissionThenRedirect('advertisement-listing-package-create');

        // First render: the default module's categories only; the form loads other
        // modules' categories from lookup.categories when the Module dropdown changes.
        $categoryModuleId = PackageModules::defaultFor(self::TYPE)?->id;

        return view('packages.ad.create', [
            'modules' => PackageModules::allowedFor(self::TYPE),
            'categoryModuleId' => $categoryModuleId,
            'categories' => $this->categoryTree($categoryModuleId),
            'selected_categories' => [],
            'selected_all_categories' => [],
        ] + $this->formData());
    }

    public function store(AdPackageRequest $request)
    {
        ResponseService::noPermissionThenSendJson('advertisement-listing-package-create');

        try {
            DB::beginTransaction();

            $duration = $request->package_duration_type === 'limited' ? (int) $request->duration : null;
            $isGlobal = (int) ($request->is_global == 1);

            $data = [
                'name' => $request->input('name.'.self::DEFAULT_LANGUAGE_ID),
                // is_global means "all categories of THIS module" — module_id still applies.
                'module_id' => $request->input('module_id'),
                'type' => self::TYPE,
                'price' => $request->price,
                'discount_in_percentage' => $request->discount_in_percentage,
                'final_price' => $this->finalPrice((float) $request->price, (float) $request->discount_in_percentage),
                'ios_product_id' => $request->ios_product_id,
                'duration' => $duration,
                'is_global' => $isGlobal,
                'item_limit' => $request->item_limit_type === 'limited' ? $request->item_limit : 'unlimited',
                'listing_duration_type' => $request->listing_duration_type,
                'listing_duration_days' => match ($request->listing_duration_type) {
                    'standard' => 30,
                    'package' => $duration, // follows the package duration (null when unlimited)
                    'custom' => (int) $request->listing_duration_days,
                },
                'key_points' => $this->encodeKeyPoints($request->input('key_points.'.self::DEFAULT_LANGUAGE_ID, [])),
                // Refer & Earn per-package overrides are hidden in the UI: always use the global settings.
                'refer_max_points_usage_percentage' => null,
                'refer_min_points_to_use' => null,
                'refer_max_points_to_use' => null,
                'icon' => FileService::compressAndUpload($request->file('icon'), self::UPLOAD_FOLDER),
                'is_reel_allowed' => $request->boolean('is_reel_allowed') ? 1 : 0,
            ];

            $package = Package::create($data);

            if (! $isGlobal) {
                $categoryIds = $this->withoutDescendantsOfSelected((array) $request->input('selected_categories', []));
                PackageCategory::upsert(
                    array_map(fn ($categoryId) => ['category_id' => $categoryId, 'package_id' => $package->id], $categoryIds),
                    ['package_id', 'category_id']
                );
            }

            $this->saveTranslations($request, $package, false);

            DB::commit();
            ResponseService::successResponse('Package Successfully Added', $data);
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th, 'AdPackageController -> store');
            ResponseService::errorResponse();
        }
    }

    public function edit($id)
    {
        ResponseService::noPermissionThenRedirect('advertisement-listing-package-update');

        $package = $this->findPackage($id, ['package_categories', 'translations']);

        $translations = [self::DEFAULT_LANGUAGE_ID => ['name' => $package->name]];
        foreach ($package->translations->groupBy('language_id') as $langId => $items) {
            foreach ($items as $item) {
                $translations[$langId][$item->key] = $item->value;
            }
        }

        $categoryModuleId = $package->module_id;
        [$selected, $selectedAll] = $this->expandSelectedCategories($package->package_categories->pluck('category_id')->all());

        return view('packages.ad.edit', [
            'package' => $package,
            'translations' => $translations,
            // Module is fixed once created: only the saved module is shown.
            'modules' => Module::whereKey($categoryModuleId)->get(),
            'categoryModuleId' => $categoryModuleId,
            'categories' => $this->categoryTree($categoryModuleId, $selected),
            'selected_categories' => $selected,
            'selected_all_categories' => $selectedAll,
        ] + $this->formData());
    }

    /** Only the editable fields: name, iOS product ID, icon, key points (+ their translations). */
    public function update(AdPackageRequest $request, $id)
    {
        ResponseService::noPermissionThenSendJson('advertisement-listing-package-update');

        $package = $this->findPackage($id);

        try {
            DB::beginTransaction();

            $data = [
                'name' => $request->input('name.'.self::DEFAULT_LANGUAGE_ID),
                'ios_product_id' => $request->ios_product_id,
                'key_points' => $this->encodeKeyPoints($request->input('key_points.'.self::DEFAULT_LANGUAGE_ID, [])),
            ];
            if ($request->hasFile('icon')) {
                $data['icon'] = FileService::compressAndReplace($request->file('icon'), self::UPLOAD_FOLDER, $package->getRawOriginal('icon'));
            }
            $package->update($data);

            $this->saveTranslations($request, $package, true);

            DB::commit();
            ResponseService::successResponse('Package Successfully Updated');
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th, 'AdPackageController -> update');
            ResponseService::errorResponse();
        }
    }

    public function discontinue($id)
    {
        ResponseService::noPermissionThenSendJson('advertisement-listing-package-update');

        $package = $this->findPackage($id);
        if ($package->is_discontinued) {
            ResponseService::errorResponse('Package is already discontinued');
        }

        try {
            $package->update(['is_discontinued' => 1, 'discontinued_at' => Carbon::now()]);
            ResponseService::successResponse('Package has been discontinued. It will be removed from listings once all active subscriptions expire.');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'AdPackageController -> discontinue');
            ResponseService::errorResponse();
        }
    }

    // ---------------------------------------------------------------------------------

    /** An Ad package, or 404. */
    private function findPackage($id, array $with = []): Package
    {
        return Package::with($with)->where('type', self::TYPE)->findOrFail($id);
    }

    /** Route / permission names the shared package views need. */
    private function viewMeta(): array
    {
        return [
            'routePrefix' => 'ad-packages',
            'permissionPrefix' => 'advertisement-listing-package',
            'supportsCategoryScope' => true,
        ];
    }

    private function formData(): array
    {
        return [
            'languages' => CachingService::getLanguages()->values(),
            'currency_symbol' => CachingService::getSystemSettings('currency_symbol'),
            // Same condition PackageRequest::moduleRule() uses to require module_id.
            'showModuleSelect' => Module::active()->count() > 1,
        ] + $this->viewMeta();
    }

    /** Active categories of one module as the nested object category-scope expects (+ $keepIds even if inactive). */
    private function categoryTree(?int $moduleId, array $keepIds = [])
    {
        $categories = Category::without('translations')
            ->forModule($moduleId)
            ->where(fn ($q) => $q->where('status', 1)->orWhereIn('id', $keepIds))
            ->orderBy('sequence')
            ->get()
            ->each->setAppends([]);

        return HelperService::buildNestedChildSubcategoryObject($categories);
    }

    /**
     * Saved picks → [checked ids (picks + all their descendants), ids to expand (+ ancestors)],
     * the shape category-scope uses to pre-check and open the tree on edit.
     */
    private function expandSelectedCategories(array $saved): array
    {
        $parentOf = Category::without('translations')->pluck('parent_category_id', 'id');
        $childrenOf = $parentOf->filter()->groupBy(fn ($parentId) => $parentId, true)->map->keys();

        $checked = $saved;
        $queue = $saved;
        while ($queue) {
            foreach ($childrenOf->get(array_shift($queue), []) as $childId) {
                if (! in_array($childId, $checked)) {
                    $checked[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        $expanded = $checked;
        foreach ($saved as $id) {
            for ($parent = $parentOf[$id] ?? null; $parent; $parent = $parentOf[$parent] ?? null) {
                $expanded[] = $parent;
            }
        }

        return [array_values(array_unique($checked)), array_values(array_unique($expanded))];
    }

    /** Store only the top-most picks: a category whose ancestor is also picked is implied. */
    private function withoutDescendantsOfSelected(array $selectedIds): array
    {
        $selectedIds = array_values(array_unique(array_map('intval', $selectedIds)));
        $selectedSet = array_flip($selectedIds);
        $parentOf = Category::without('translations')->pluck('parent_category_id', 'id')->all();

        return array_values(array_filter($selectedIds, function ($id) use ($parentOf, $selectedSet) {
            for ($parent = $parentOf[$id] ?? null; $parent !== null; $parent = $parentOf[$parent] ?? null) {
                if (isset($selectedSet[$parent])) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function finalPrice(float $price, float $discount): float
    {
        return round($price - ($price * $discount / 100), 2);
    }

    private function encodeKeyPoints(?array $points): ?string
    {
        $points = array_values(array_filter((array) $points, fn ($point) => trim((string) $point) !== ''));

        return $points ? json_encode($points, JSON_UNESCAPED_UNICODE) : null;
    }

    /** Name + key point translations for every non-default language. */
    private function saveTranslations(AdPackageRequest $request, Package $package, bool $isUpdate): void
    {
        $rows = [];
        foreach (CachingService::getLanguages()->where('id', '!=', self::DEFAULT_LANGUAGE_ID) as $language) {
            $name = $request->input("name.{$language->id}");
            $keyPoints = $this->encodeKeyPoints($request->input("key_points.{$language->id}", []));

            // On update, blank values are written too so a cleared translation is removed.
            if ($isUpdate || ! empty($name)) {
                $rows[] = ['key' => 'name', 'value' => $name ?? '', 'language_id' => $language->id];
            }
            if ($isUpdate || $keyPoints) {
                $rows[] = ['key' => 'key_points', 'value' => $keyPoints ?? '', 'language_id' => $language->id];
            }
        }

        if ($rows) {
            HelperService::storeTranslations(array_map(fn ($row) => $row + [
                'translatable_id' => $package->id,
                'translatable_type' => Package::class,
            ], $rows), $isUpdate);
        }
    }
}
