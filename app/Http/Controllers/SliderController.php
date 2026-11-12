<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Country;
use App\Models\Item;
use App\Models\Module;
use App\Models\Slider;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\FileService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;
use App\Services\Module\ModuleService;

class SliderController extends Controller
{
    private string $uploadFolder;

    public function __construct()
    {
        $this->uploadFolder = 'sliders';
    }

    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect(['slider-list', 'slider-create', 'slider-update', 'slider-delete']);
        $modules = Module::active()->orderBy('sequence')->get();

        return view('slider.index', compact('modules'));
    }

    public function create()
    {
        ResponseService::noPermissionThenRedirect('slider-create');
        $modules = Module::active()->orderBy('sequence')->get();
        // First render: the default module's categories only. Other modules' categories and
        // all items are requested on demand (lookup.categories / lookup.items) by the form script.
        $categoryModuleId = ModuleService::defaultModuleId();
        $categories = Category::where('status', 1)->forModule($categoryModuleId)
            ->orderByRaw('parent_category_id IS NULL DESC')->orderBy('sequence')->get();
        $countries = Country::select(['id', 'name'])->get();

        return view('slider.create', compact('categories', 'countries', 'modules', 'categoryModuleId'));
    }

    /** @deprecated Route commented out in routes/web.php — the form now uses lookup.items + client-side category filtering. Delete (with moduleOptions()) once confirmed. */
    public function itemsAndCategories(Request $request)
    {
        ResponseService::noPermissionThenSendJson('slider-create');
        $request->validate(['module_id' => 'nullable|integer|exists:modules,id']);

        [$items, $categories] = $this->moduleOptions($request->integer('module_id') ?: null);

        return response()->json([
            'items' => $items->map(fn ($item) => ['id' => $item->id, 'name' => $item->name])->values(),
            'categories_html' => view('category.dropdowntree', ['categories' => $categories])->render(),
        ]);
    }

    /**
     * @deprecated Only used by itemsAndCategories() — delete together.
     *
     * Approved items and active categories, scoped to a module when one is given.
     * Items have no module_id, so they're filtered through their category.
     */
    private function moduleOptions(?int $moduleId): array
    {
        $items = Item::where('status', 'approved')
            ->when($moduleId, fn ($q) => $q->whereIn('category_id', ModuleService::categoryIds($moduleId)))
            ->get(['id', 'name']);
        $categories = Category::where('status', 1)
            ->when($moduleId, fn ($q) => $q->forModule($moduleId))
            ->orderByRaw('parent_category_id IS NULL DESC')->orderBy('sequence')->get();

        return [$items, $categories];
    }

    public function store(Request $request)
    {

        if (! $request->filled('category_id') && ! $request->filled('item') && ! $request->filled('link')) {
            ResponseService::validationError('At least one of the fields (Category, Advertisement, or Third Party Link) is required.');
        }

        ResponseService::noPermissionThenRedirect('slider-create');
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'image' => 'required|image|mimes:jpg,png,jpeg,webp|max:7168',
            'link' => 'nullable|url|max:255',
            'show_on_web' => 'required|boolean',
            'show_on_app' => 'required|boolean',
            'status' => 'required|boolean',
        ], [
            'show_on_web.required' => __('Select at least one platform.'),
        ]);
        // A slider has to show somewhere: web, app or both.
        $validator->after(function ($validator) use ($request) {
            if (! $request->boolean('show_on_web') && ! $request->boolean('show_on_app')) {
                $validator->errors()->add('show_on_web', __('Select at least one platform.'));
            }
        });
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            if ($error = ModuleService::ownershipError($request->integer('module_id') ?: null, [$request->category_id], [$request->item])) {
                ResponseService::validationError($error);
            }

            $lastSequence = Slider::max('sequence');
            $nextSequence = $lastSequence + 1;
            $slider = Slider::create([
                'image' => $request->hasFile('image') ? FileService::compressAndUpload($request->file('image'), $this->uploadFolder) : '',
                'name' => $request->input('name'),
                'module_id' => $request->input('module_id'),
                'third_party_link' => $request->link ?? '',
                'show_on_web' => $request->boolean('show_on_web'),
                'show_on_app' => $request->boolean('show_on_app'),
                'status' => $request->boolean('status'),
                'sequence' => $nextSequence,
                'country_id' => $request->country_id,
                'state_id' => $request->state_id,
                'city_id' => $request->city_id,
            ]);

            if ($request->filled('category_id')) {
                $category = Category::find($request->category_id);
                $slider->model()->associate($category)->save();
            }
            if ($request->filled('item')) {
                $item = Item::find($request->item);
                $slider->model()->associate($item)->save();
            }
            ResponseService::successResponse('Slider created successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'Slider Controller -> store');
            ResponseService::errorResponse();
        }
    }

    public function destroy($id)
    {
        ResponseService::noPermissionThenRedirect('slider-delete');
        try {
            $slider = Slider::find($id);
            if ($slider) {
                $url = $slider->image;
                $relativePath = parse_url($url, PHP_URL_PATH);
                if (Storage::disk(config('filesystems.default'))->exists($relativePath)) {
                    Storage::disk(config('filesystems.default'))->delete($relativePath);
                }
                $slider->delete();
                ResponseService::successResponse('slider delete successfully');
            }

        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'Slider Controller -> destroy');
            ResponseService::errorResponse('something is wrong !!!');
        }
    }

    public function show(Request $request)
    {
        ResponseService::noPermissionThenRedirect('slider-list');
        $offset = $request->offset ?? 0;
        $limit = $request->limit ?? 10;
        $sort = $request->sort ?? 'id';
        $order = $request->order ?? 'DESC';
        $sql = Slider::with('model', 'country:id,name', 'state:id,name', 'city:id,name', 'module:id,name')->forModule($request->input('module_id'))
            // Platform filter: web / app = sliders shown there (empty = all).
            ->when($request->input('platform') === 'web', fn ($q) => $q->where('show_on_web', true))
            ->when($request->input('platform') === 'app', fn ($q) => $q->where('show_on_app', true))
            ->when(in_array($request->input('status'), ['0', '1'], true), fn ($q) => $q->where('status', (bool) $request->status));
        if (! empty($request->search)) {
            $sql = $sql->search($request->search);
        }
        $total = $sql->count();
        $sql->sort($sort, $order)->skip($offset)->take($limit);
        $result = $sql->get();
        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        foreach ($result as $key => $row) {
            $tempRow = $row->toArray();
            $tempRow['module_name'] = $row->module->name ?? '';
            [$tempRow['destination_type'], $tempRow['destination_label']] = $this->sliderDestination($row);
            $tempRow['location_label'] = $this->sliderLocation($row);
            $operate = '';
            if (Auth::user()->can('slider-delete')) {
                $operate .= BootstrapTableService::deleteButton(route('slider.destroy', $row->id));
            }
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;

        return response()->json($bulkData);
    }

    /**
     * List "Destination": [type, label] — category (with its parent: "Cars > SUVs"),
     * listing (item name) or external link (URL).
     * @return array{0: string, 1: string}
     */
    private function sliderDestination(Slider $slider): array
    {
        if ($slider->model instanceof Category) {
            $parent = $slider->model->parent_category_id ? Category::whereKey($slider->model->parent_category_id)->value('name') : null;
            return ['category', ($parent ? $parent.' > ' : '').$slider->model->name];
        }
        if ($slider->model instanceof Item) {
            return ['listing', (string) $slider->model->name];
        }
        if (filled($slider->third_party_link)) {
            return ['external', $slider->third_party_link];
        }

        return ['none', ''];
    }

    // List "Location": Worldwide, a country, or "Country (City / State)".
    private function sliderLocation(Slider $slider): string
    {
        $place = $slider->city->name ?? $slider->state->name ?? null;
        $country = $slider->country->name ?? null;

        return match (true) {
            $country && $place => "{$country} ({$place})",
            (bool) ($country ?? $place) => $country ?? $place,
            default => trans('Worldwide'),
        };
    }
}
