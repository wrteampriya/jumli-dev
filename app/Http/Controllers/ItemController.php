<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\Module;
use App\Models\Currency;
use App\Models\CustomField;
use App\Models\Package;
use App\Models\Reel;
use App\Models\CustomFieldCategory;
use App\Models\Item;
use App\Models\ItemCustomFieldValue;
use App\Models\ItemImages;
use App\Models\ItemVideo;
use App\Models\State;
use App\Models\User;
use App\Models\UserFcmToken;
use App\Models\UserPurchasedPackage;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\Module\ItemModuleValidator;
use App\Services\NotificationService;
use App\Services\ResponseService;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Str;
use Throwable;
use Validator;
use App\Rules\VideoLink;
use App\Services\Module\ModuleService;

class ItemController extends Controller
{
    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect(['advertisement-list', 'advertisement-update', 'advertisement-delete']);
        $countries = Country::all();
        $categories = Category::orderByRaw('parent_category_id IS NULL DESC')->orderBy('sequence')->get();
        $mapProvider = CachingService::getSystemSettings('map_provider') ?? 'free_api';
        $googleMapKey = CachingService::getSystemSettings('google_map_key');
        $modules = Module::active()->orderBy('sequence')->get();

        return view('items.index', compact('countries', 'categories', 'mapProvider', 'googleMapKey', 'modules'));
    }

    public function show($status, Request $request)
    {
        try {
            ResponseService::noPermissionThenSendJson('advertisement-list');
            $offset = $request->input('offset', 0);
            $limit = $request->input('limit', 10);
            $sort = $request->input('sort', 'updated_at');
            $order = $request->input('order', 'DESC');
            $sql = Item::with(['custom_fields', 'category:id,name,module_id', 'category.module:id,name', 'user' => function ($query) {
                $query->withTrashed()->select('id', 'name', 'email', 'profile', 'deleted_at');
            }, 'gallery_images', 'featured_items', 'currency:id,symbol', 'reel:id,item_id,video,thumbnail','itemVideo'])->withTrashed();
            // Items don't own module_id — never whereHas('category', ...)
            // here, that's a correlated EXISTS per row. Resolve the
            // module's category ids once (cached) and filter by those.
            if ($request->filled('module_id')) {
                $sql->whereIn('category_id', ModuleService::categoryIds((int) $request->input('module_id')));
            }
            if (! empty($request->search)) {
                $sql = $sql->search($request->search);
            }
            if (! empty($request->filter)) {
                $filters = json_decode($request->filter, false, 512, JSON_THROW_ON_ERROR);
                if (is_object($filters) && count((array) $filters) > 0) {
                    // Handle status_not separately if present
                    $hasStatusNot = isset($filters->status_not);
                    $statusNotValue = null;

                    if ($hasStatusNot) {
                        $statusNotValue = $filters->status_not;
                        $sql = $sql->where('status', '!=', $statusNotValue);
                    }

                    // Handle posted_by=admin → restrict to ads from users with Super Admin role
                    if (isset($filters->posted_by) && $filters->posted_by === 'admin') {
                        $adminUserIds = User::role('Super Admin')->pluck('id');
                        $sql = $sql->whereIn('user_id', $adminUserIds);
                    }

                    // Handle favorite → restrict to ads favourited by at least one user
                    if (isset($filters->favorite) && $filters->favorite) {
                        $sql = $sql->has('favourites');
                    }

                    // Build remaining filters object (excluding status_not, posted_by, favorite)
                    $remainingFilters = [];
                    foreach ($filters as $key => $value) {
                        if ($key !== 'status_not' && $key !== 'posted_by' && $key !== 'favorite') {
                            $remainingFilters[$key] = $value;
                        }
                    }

                    // Apply remaining filters (status, country, state, city, featured_status, etc.)
                    if (! empty($remainingFilters)) {
                        $sql = $sql->filter((object) $remainingFilters);
                    }
                }
            }

            $total = $sql->count();
            $sql = $sql->sort($sort, $order)->skip($offset)->take($limit);
            $result = $sql->get();
            $bulkData = [];
            $bulkData['total'] = $total;
            $rows = [];

            $itemCustomFieldValues = ItemCustomFieldValue::whereIn('item_id', $result->pluck('id'))->get();
            // Admin listings (posted by Super Admin) cannot be bulk approval-updated
            $adminUserIds = User::role('Super Admin')->pluck('id')->toArray();
            // Cache custom fields per category (includes parent/ancestor categories' fields)
            $categoryCustomFields = [];
            foreach ($result as $row) {
                /* Merged ItemCustomFieldValue's data to main data */
                $itemCustomFieldValue = $itemCustomFieldValues->filter(function ($data) use ($row) {
                    return $data->item_id == $row->id;
                });
                $featured_status = $row->featured_items->isNotEmpty() ? 'Featured' : 'Not-Featured';
                // Custom fields must include the item's category AND all ancestor categories
                if (! array_key_exists($row->category_id, $categoryCustomFields)) {
                    $categoryIds = $this->getParentCategoryIds($row->category_id);
                    $categoryCustomFields[$row->category_id] = CustomField::with('translations')
                        ->whereHas('custom_field_category', function ($q) use ($categoryIds) {
                            $q->whereIn('category_id', $categoryIds);
                        })
                        ->get();
                }
                $mappedCustomFields = collect($categoryCustomFields[$row->category_id])->map(function ($customField) use ($itemCustomFieldValue) {
                    $value = $itemCustomFieldValue->first(function ($data) use ($customField) {
                        return $data->custom_field_id == $customField->id;
                    });

                    $customField['is_stale_value'] = false;
                    if (in_array($customField->type, ['checkbox', 'radio', 'dropdown']) && $value) {
                        $validValues = $this->filterValidValues($customField, $value);
                        if (empty($validValues)) {
                            $customField['is_stale_value'] = true;
                            $value = null;
                        } else {
                            $value->setAttribute('value', json_encode($validValues, JSON_THROW_ON_ERROR));
                        }
                    }

                    $customField['value'] = $value;

                    if ($customField->type == 'fileinput' && ! empty($customField['value']->value)) {
                        $rawPath = $customField['value']->getRawOriginal('value');
                        $filePath = $this->extractFilePath($rawPath);
                        $customField['value'] = ! empty($filePath) ? url(Storage::url($filePath)) : '';
                    }

                    return $customField;
                })->reject(function ($customField) {
                    return $customField['is_stale_value'];
                })->values();
                // setRelation so toArray() emits these (assigning ->custom_fields is overridden by the eager-loaded relation)
                $row->setRelation('custom_fields', $mappedCustomFields);
                $tempRow = $row->toArray();
                if (!empty($tempRow['user'])) {
                    $tempRow['user'] = HelperService::maskDemoUserData($tempRow['user']);
                }
                $isUserDeleted = $row->user && $row->user->deleted_at !== null;
                $tempRow['is_user_deleted'] = $isUserDeleted;
                $operate = '';
                if (!$isUserDeleted && Auth::user()->can('advertisement-update')) {
                    // Navigates directly to the advertisement edit page
                    $operate .= BootstrapTableService::editButton(route('advertisement.edit', $row->id));
                }
                if (Auth::user()->can('advertisement-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('advertisement.destroy', $row->id));
                }
                if (Auth::user()->can('advertisement-list')) {
                    // View Custom Field — modal opened via JS delegation in index.blade.php
                    $operate .= BootstrapTableService::button('fa fa-eye', '#', ['editdata'], ['title' => __('View')]);
                }
                if (!$isUserDeleted && $row->status !== 'sold out' && $row->status !== "expired" && Auth::user()->can('advertisement-update') && !in_array($row->user_id, $adminUserIds)) {
                    // Opens the status approval modal — triggered via JS delegation in index.blade.php
                    $operate .= BootstrapTableService::button('fas fa-toggle-on', "" ,['edit-status'], ['title' => __('Update Status'), 'id' => $row->id]);
                }
                $tempRow['active_status'] = empty($row->deleted_at); // IF deleted_at is empty then status is true else false
                $tempRow['featured_status'] = $featured_status;
                $tempRow['has_reel'] = $row->reel !== null;
                $tempRow['module_name'] = $row->category->module->name ?? '';
                $tempRow['operate'] = $operate;
                $isAdminListing = in_array($row->user_id, $adminUserIds);
                $tempRow['is_admin_listing'] = $isAdminListing;
                $tempRow['selectable'] = !$isUserDeleted && !in_array($row->status, ['sold out', 'expired']) && !$isAdminListing;
                $tempRow['expiry_date'] = !empty($row->expiry_date) ? date('Y-m-d', strtotime($row->expiry_date)) : null;
                $tempRow['created_at'] = !empty($row->created_at) ? date('Y-m-d', strtotime($row->created_at)) : null;
                $tempRow['updated_at'] = !empty($row->updated_at) ? date('Y-m-d', strtotime($row->updated_at)) : null;
                // Full timestamp for the list's relative "Updated" column (updated_at above stays a date for exports).
                $tempRow['updated_at_iso'] = $row->updated_at?->toIso8601String();

                $rows[] = $tempRow;
            }
            $bulkData['rows'] = $rows;

            return response()->json($bulkData);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemController --> show');
            ResponseService::errorResponse();
        }
    }

    public function updateItemApproval(Request $request)
    {
        try {
            ResponseService::noPermissionThenSendJson('advertisement-update');
            $id = $request->id;

            $item = Item::with('user')->withTrashed()->findOrFail($id);

            $oldStatus = $item->status;

            $data = $request->except(['created_at']);

            // Handle rejected reason
            $data['rejected_reason'] =
                in_array($request->status, ['soft rejected', 'permanent rejected'])
                ? $request->rejected_reason
                : '';

            // ✅ Update published_at ONLY when approved
            if ($request->status === 'approved') {
                $data['published_at'] = Carbon::now();

                // Assign expiry_date only on first approval, never overwrite on re-approval
                if (is_null($item->expiry_date)) {
                    $package = $item->package_id ? Package::find($item->package_id) : null;
                    $userPackage = UserPurchasedPackage::where('user_id', $item->user_id)
                        ->where('package_id', $item->package_id)
                        ->whereDate('start_date', '<=', date('Y-m-d'))
                        ->where(function ($q) {
                            $q->whereDate('end_date', '>', date('Y-m-d'))->orWhereNull('end_date');
                        })
                        ->orderBy('end_date', 'asc')
                        ->first();
                    $data['expiry_date'] = HelperService::calculateItemExpiryDate($package, $userPackage);
                }
            }

            $item->update($data);

            if (!empty($item->user->id)) {
                // Dispatch chunked notification jobs using centralized service
                NotificationService::dispatchChunkedNotifications(
                    'About ' . $item->name,
                    'Your Advertisement is ' . ucfirst($request->status),
                    'item-update',
                    ['id' => $item->id, 'old_status' => $oldStatus, 'new_status' => $item->status, 'status' => $item->status],
                    false,
                    array($item->user->id)
                );
            }

            ResponseService::successResponse('Advertisement Status Updated Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemController ->updateItemApproval');
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    public function bulkUpdateItemApproval(Request $request)
    {
        try {
            ResponseService::noPermissionThenSendJson('advertisement-update');

            $validator = Validator::make($request->all(), [
                'ids'             => 'required|array|min:1',
                'ids.*'           => 'required|integer|exists:items,id',
                'status'          => 'required|string|in:approved,review,soft rejected,permanent rejected',
                'rejected_reason' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => $validator->errors()->first(),
                ], 422);
            }

            // Rejection reason is required when soft/permanent rejected
            if (in_array($request->status, ['soft rejected', 'permanent rejected']) && empty($request->rejected_reason)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Rejection reason is required for rejected status.',
                ], 422);
            }

            // Admin listings (ads posted by Super Admin) cannot have approval status changed in bulk
            $adminUserIds = User::role('Super Admin')->pluck('id');
            $hasAdminListing = Item::withTrashed()
                ->whereIn('id', $request->ids)
                ->whereIn('user_id', $adminUserIds)
                ->exists();

            if ($hasAdminListing) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Admin listings cannot be updated. Only active/deactive is allowed for admin listings.',
                ], 422);
            }

            $itemsQuery = Item::with('user')->withTrashed()->whereIn('id', $request->ids);
            $items = $itemsQuery->get();

            foreach ($items as $item) {
                $oldStatus = $item->status;

                $data = [
                    'status'          => $request->status,
                    'rejected_reason' => in_array($request->status, ['soft rejected', 'permanent rejected'])
                        ? $request->rejected_reason
                        : '',
                ];

                if ($request->status === 'approved') {
                    $data['published_at'] = Carbon::now();

                    // Assign expiry_date only on first approval, never overwrite on re-approval
                    if (is_null($item->expiry_date)) {
                        $package = $item->package_id ? Package::find($item->package_id) : null;
                        $userPackage = UserPurchasedPackage::where('user_id', $item->user_id)
                            ->where('package_id', $item->package_id)
                            ->whereDate('start_date', '<=', date('Y-m-d'))
                            ->where(function ($q) {
                                $q->whereDate('end_date', '>', date('Y-m-d'))->orWhereNull('end_date');
                            })
                            ->orderBy('end_date', 'asc')
                            ->first();
                        $data['expiry_date'] = HelperService::calculateItemExpiryDate($package, $userPackage);
                    }
                }

                $item->update($data);

                if (!empty($item->user->id)) {
                    NotificationService::dispatchChunkedNotifications(
                        'About ' . $item->name,
                        'Your Advertisement is ' . ucfirst($request->status),
                        'item-update',
                        ['id' => $item->id, 'old_status' => $oldStatus, 'new_status' => $item->status, 'status' => $item->status],
                        false,
                        [$item->user->id]
                    );
                }
            }

            ResponseService::successResponse('Advertisement status updated successfully.');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemController -> bulkUpdateItemApproval');
            return ResponseService::errorResponse();
        }
    }


    public function destroy($id)
    {
        ResponseService::noPermissionThenSendJson('advertisement-delete');

        try {

            $item = Item::with('gallery_images')->withTrashed()->findOrFail($id);
            foreach ($item->gallery_images as $gallery_image) {
                FileService::delete($gallery_image->getRawOriginal('image'));
            }
            FileService::delete($item->getRawOriginal('image'));

            $item->forceDelete();

            ResponseService::successResponse('Advertisement deleted successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse('Something went wrong');
        }
    }

    public function requestedItem()
    {
        ResponseService::noAnyPermissionThenRedirect(['advertisement-list', 'advertisement-update', 'advertisement-delete']);
        $countries = Country::all();
        $cities = City::all();

        return view('items.requested_item', compact('countries', 'cities'));
    }

    public function searchState(Request $request)
    {
        $countryName = trim($request->query('country_name'));
        if ($countryName == 'All') {
            return response()->json(['message' => 'Success', 'data' => []]);
        }
        $country = Country::where(['name' => $countryName, 'status' => true])->first();

        if (! $country) {
            return response()->json(['message' => 'Success', 'data' => []]);
        }
        $states = State::where(['country_id' => $country->id, 'status' => true])->get();

        return response()->json(['message' => 'Success', 'data' => $states]);
    }

    public function searchCities(Request $request)
    {
        $stateName = trim($request->query('state_name'));
        if ($stateName == 'All') {
            return response()->json(['message' => 'Success', 'data' => []]);
        }
        $state = State::where(['name' => $stateName, 'status' => true])->first();
        if (! $state) {
            return response()->json(['message' => 'Success', 'data' => []]);
        }
        $cities = City::where(['state_id' => $state->id, 'status' => true])->get();

        return response()->json(['message' => 'Success', 'data' => $cities]);
    }

    public function editForm($id)
    {
        $item = Item::with(
            'user:id,name,email,mobile,profile,country_code',
            'category.custom_fields', // get custom fields from category
            'gallery_images:id,image,item_id,is_default',
            'featured_items',
            'favourites',
            'item_custom_field_values.custom_field',
            'area',
            'currency:id,name',
            'reel',
            'itemVideo'
        )->withTrashed()->findOrFail($id);
        // Module / Category steps of the edit wizard: every active module (plus the
        // ad's own, even if inactive); categories load per module over AJAX
        // (advertisement.get-parent-categories / get-subcategories). Picking another
        // module's category moves the ad there; its rules apply on save (server).
        $itemModuleId = ModuleService::moduleIdForItem($item->id);
        $modules = Module::active()->orderBy('sequence')->get();
        if ($itemModuleId && !$modules->contains('id', $itemModuleId)) {
            $modules->push(Module::find($itemModuleId));
        }
        $modules = $modules->filter()->values();
        $currencies = Currency::all();

        // Ancestors of the ad's category (root first), so the Category step opens at
        // the selected leaf's level with the breadcrumb filled in.
        $categoryTrail = [];
        for ($category = $item->category; $category; $category = $category->parent_category_id ? Category::find($category->parent_category_id) : null) {
            array_unshift($categoryTrail, ['id' => $category->id, 'name' => $category->name]);
        }

        $countries = Country::all();
        $languages = CachingService::getLanguages()->values();
        $defaultLanguage = CachingService::getDefaultLanguage();
        
        // Load existing translations
        $translations = HelperService::transformTranslationsForEdit($item->translations);
        $seoTranslations = HelperService::prepareSeoTranslationsForEdit($item);

        $geminiEnabled = CachingService::getSystemSettings('gemini_ai_enabled') === '1';
        $geminiAutoTranslateEnabled = $geminiEnabled && CachingService::getSystemSettings('gemini_auto_translate_enabled') === '1';
        $mapProvider = CachingService::getSystemSettings('map_provider') ?? 'free_api';
        $googleMapKey = CachingService::getSystemSettings('google_map_key');
        $defaultLatitude = CachingService::getSystemSettings('default_latitude');
        $defaultLongitude = CachingService::getSystemSettings('default_longitude');

        // Applied by items/partials/module-rules on page load (pricing select,
        // image limit, video types/size) — same data /get-custom-fields sends.
        $moduleSettings = ModuleService::settings(ModuleService::moduleIdForItem($item->id));
        $itemIsJobCategory = (bool) $item->category?->is_job_category;

        return view('items.update', compact('item', 'countries', 'currencies', 'languages', 'defaultLanguage', 'translations', 'seoTranslations', 'geminiEnabled', 'geminiAutoTranslateEnabled', 'mapProvider', 'googleMapKey', 'defaultLatitude', 'defaultLongitude', 'moduleSettings', 'itemIsJobCategory', 'modules', 'itemModuleId', 'categoryTrail'));
    }

    public function update(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson('advertisement-update');

        // The edit form can change the category — its module decides the
        // rules; fall back to the item's current category.
        $moduleId = ModuleService::moduleIdForCategory(
            $request->input('category_id') ?: DB::table('items')->where('id', $id)->value('category_id')
        );
        $moduleSettings = ModuleService::settings($moduleId);

        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'slug' => 'nullable|regex:/^[a-z0-9-]+$/',
                'description' => 'nullable|string',
                'latitude' => 'nullable',
                'longitude' => 'nullable',
                'address' => 'nullable',
                'contact' => 'nullable',
                'show_mobile_number' => 'nullable|boolean',
                'custom_fields' => 'nullable',
                'custom_field_files' => 'nullable|array',
                'custom_field_files.*' => 'nullable|mimes:jpeg,png,jpg,pdf,doc|max:7168',
                'gallery_images' => 'nullable|array',
                'delete_item_image_id' => 'nullable|array',
                'currency_id'        => 'nullable|exists:currencies,id',
                'item_type'          => 'nullable|in:normal,reel',
                'reel_video'         => 'nullable|file|mimes:mp4|max:512000', // Only MP4 is allowed for reels – WebM support removed per request.
                'delete_reel'        => 'nullable|boolean',
                'video_type'         => 'nullable|in:youtube_link,vimeo_link,other_link,file',
                'video_link'         => [
                    'required_if:video_type,youtube_link,vimeo_link,other_link',
                    'nullable',
                    'url',
                    new VideoLink($request->input('video_type')),
                ],
                'item_video'         => 'nullable|file|mimes:mp4,webm,avi,mov|max:' . ((int) $moduleSettings['video_max_file_size_mb'] * 1024),
                'delete_item_video'  => 'nullable|boolean',
                // File custom fields whose stored file the admin removed (no replacement).
                'delete_custom_field_files'   => 'nullable|array',
                'delete_custom_field_files.*' => 'integer',
            ]);

            if ($validator->fails()) {
                $errorMessage = $validator->errors()->first();
                ResponseService::errorResponse($errorMessage);
                return; // Ensure execution stops
            }

            if ($schemaError = HelperService::seoSchemaError($request, CachingService::getLanguages()->pluck('id')->all())) {
                ResponseService::errorResponse($schemaError);
                return;
            }

            // Only ids that really belong to this item count (and get deleted).
            $deleteImageIds = ItemImages::where('item_id', $id)
                ->whereIn('id', (array) $request->input('delete_item_image_id', []))
                ->pluck('id');
            $remainingImages = ItemImages::where('item_id', $id)->count() - $deleteImageIds->count()
                + ($request->hasFile('gallery_images') ? count($request->file('gallery_images')) : 0);
            $imageError = ItemModuleValidator::galleryLimitError($moduleSettings, $remainingImages)
                ?? ($remainingImages < 1 ? __('At least one item image is required') : null);
            if ($imageError) {
                ResponseService::errorResponse($imageError);
                return;
            }

            DB::beginTransaction();
            $item = Item::findOrFail($id);

            if($item->user_id != auth()->user()->id){
                $validator = Validator::make($request->all(), [
                    'admin_edit_reason'  => 'required|string|max:1000',
                ]);

                if ($validator->fails()) {
                    $errorMessage = $validator->errors()->first();
                    ResponseService::errorResponse($errorMessage);
                    return; // Ensure execution stops
                }                
            }

            Category::findOrFail($request->category_id);
            // Module settings of the (possibly changed) category: product
            // video type and pricing. range reuses min_salary /
            // max_salary as the price range — see ItemModuleValidator.
            $moduleChanged = $moduleId !== ModuleService::moduleIdForCategory($item->category_id);
            $moduleError = ItemModuleValidator::videoTypeError($moduleSettings, ItemModuleValidator::submittedVideoType($request, 'item_video'));
            [$pricingError, $pricingAttributes] = $moduleError ? [null, []] : ItemModuleValidator::pricing($moduleSettings, $request, $item, $moduleChanged);

            if ($moduleError || $pricingError) {
                DB::rollBack();
                ResponseService::errorResponse($moduleError ?? $pricingError);
                return; // Ensure execution stops
            }

            $customFieldCategories = CustomFieldCategory::with('custom_fields')
                ->whereIn('category_id', $this->getParentCategoryIds($request->category_id))
                ->get();

            $customFieldErrors = [];
            foreach ($customFieldCategories as $relation) {
                $field = $relation->custom_fields;
                if (empty($field) || $field->status != 1) {
                    continue;
                }
                $fieldId = $field->id;
                $fieldLabel = $field->name;
                $val = $request->input("custom_fields.$fieldId");

                // Required check
                if ($field->required == 1) {
                    if (in_array($field->type, ['textbox', 'number', 'dropdown', 'radio'])) {
                        if ($val === null || $val === '' || (is_array($val) && empty($val))) {
                            $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field is required.";
                            continue;
                        }
                    }

                    if ($field->type === 'checkbox') {
                        if (! is_array($val) || empty($val)) {
                            $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field is required.";
                            continue;
                        }
                    }

                    if ($field->type === 'fileinput') {
                        $existing = ItemCustomFieldValue::where([
                            'item_id' => $id,
                            'custom_field_id' => $fieldId,
                        ])->first();

                        $removed = in_array($fieldId, array_map('intval', (array) $request->input('delete_custom_field_files', [])), true);
                        if (! $request->hasFile("custom_field_files.$fieldId") && (empty($existing?->value) || $removed)) {
                            $customFieldErrors["custom_field_files.$fieldId"] = "The $fieldLabel file is required.";
                            continue;
                        }
                    }
                }

                // Min/Max restrictions (run if value is present)
                if ($val !== null && $val !== '' && !(is_array($val) && empty($val))) {
                    if ($field->type === 'textbox') {
                        $valStr = (string)$val;
                        if (!is_null($field->min_length) && strlen($valStr) < $field->min_length) {
                            $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field must be at least {$field->min_length} characters.";
                        }
                        if (!is_null($field->max_length) && strlen($valStr) > $field->max_length) {
                            $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field must not exceed {$field->max_length} characters.";
                        }
                    } elseif ($field->type === 'number') {
                        if (!is_numeric($val)) {
                            $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field must be a number.";
                        } else {
                            $digitLen = strlen((string) preg_replace('/[^0-9]/', '', (string) $val));
                            if (!is_null($field->min_length) && $digitLen < $field->min_length) {
                                $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field must be at least {$field->min_length} digits.";
                            }
                            if (!is_null($field->max_length) && $digitLen > $field->max_length) {
                                $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field must not exceed {$field->max_length} digits.";
                            }
                        }
                    }
                }
            }
            
            if (! empty($customFieldErrors)) {
                DB::rollBack();
                $errorMessage = reset($customFieldErrors); // Get first error message
                ResponseService::errorResponse($errorMessage);
                return; // Ensure execution stops
            }

            $data = array_merge($request->all(), $pricingAttributes, [
                'is_edited_by_admin' => 1,
                'admin_edit_reason' => $request->admin_edit_reason,
            ]);
            // pricing() returned nothing to change: keep the stored price columns.
            if (empty($pricingAttributes)) {
                unset($data['price'], $data['min_salary'], $data['max_salary']);
            }

            // Address data from map selection
            $data['address'] = $request->input('address') ?? $request->input('address_input') ?? '';
            $data['country'] = $request->input('country_input') ?? '';
            $data['state'] = $request->input('state_input') ?? '';
            $data['city'] = $request->input('city_input') ?? '';
            $data['latitude'] = $request->input('latitude');
            $data['longitude'] = $request->input('longitude');
            $data['country_code'] = $request->input('country_code') ?? $item->country_code ?? null;
            $data['region_code'] = $request->input('region_code') ?? $item->region_code ?? null;

            $oldCategoryId = $item->category_id;
            $newCategoryId = $request->category_id;

            $isCategoryChanged = $oldCategoryId != $newCategoryId;

            // Stored custom-field files stay untouched unless replaced by an upload,
            // removed by the admin, or no longer used after a category change. Files
            // are deleted only after the commit, so a failed save never loses one.
            $filesToDelete = [];
            $fileFieldIds = CustomField::where('type', 'fileinput')->pluck('id')->all();
            $removedFileFieldIds = array_map('intval', (array) $request->input('delete_custom_field_files', []));
            $newCategoryFieldIds = $customFieldCategories->pluck('custom_field_id')->map(fn ($fieldId) => (int) $fieldId)->all();

            foreach (ItemCustomFieldValue::where('item_id', $item->id)->get() as $fieldValue) {
                $fieldId = (int) $fieldValue->custom_field_id;
                $isFile = in_array($fieldId, $fileFieldIds, true);
                $drop = $isFile
                    // File: removed by the admin, or the new category doesn't use the field.
                    ? in_array($fieldId, $removedFileFieldIds, true) || ($isCategoryChanged && ! in_array($fieldId, $newCategoryFieldIds, true))
                    // Other values are re-sent by the form; a category change starts them fresh.
                    : $isCategoryChanged;
                if (! $drop) {
                    continue;
                }
                if ($isFile && ($path = $this->extractFilePath($fieldValue->getRawOriginal('value')))) {
                    $filesToDelete[] = $path;
                }
                $fieldValue->delete();
            }

            $item->update($data);

            // Handle item video
            $this->syncItemVideo($request, $item);

            // Handle translations - only name and description are translatable
            if ($request->has('translations')) {
                $translationData = [];
                foreach ($request->input('translations', []) as $languageId => $transData) {
                    foreach (['name', 'description'] as $key) {
                        // Submitted-but-empty clears the translation; keys not sent are left alone
                        if (!array_key_exists($key, (array) $transData)) continue;
                        $translationData[] = [
                            'translatable_id'   => $item->id,
                            'translatable_type' => get_class($item),
                            'key'               => $key,
                            'value'             => $transData[$key] ?? '',
                            'language_id'       => $languageId,
                        ];
                    }
                }
                if (!empty($translationData)) {
                    HelperService::storeTranslations($translationData, true);
                }
            }

            if ($request->custom_fields) {
                $defaultLanguageId = HelperService::getDefaultLanguageId();
                foreach ($request->custom_fields as $key => $custom_field) {
                    $value = is_array($custom_field) ? $custom_field : [$custom_field];
                    ItemCustomFieldValue::updateOrCreate(
                        [
                            'item_id' => $item->id,
                            'custom_field_id' => $key,
                        ],
                        [
                            'value' => json_encode($value, JSON_THROW_ON_ERROR),
                            'language_id' => $defaultLanguageId,
                            'updated_at' => now(),
                        ]
                    );
                }
            }

            // Handle custom field translations
            if ($request->has('custom_field_translations')) {
                $customFieldTranslations = $request->input('custom_field_translations');
                if (is_array($customFieldTranslations)) {
                    foreach ($customFieldTranslations as $languageId => $fieldsByCustomField) {
                        foreach ($fieldsByCustomField as $customFieldId => $translatedValue) {
                            $value = is_array($translatedValue) ? $translatedValue : [$translatedValue];
                            ItemCustomFieldValue::updateOrCreate(
                                [
                                    'item_id' => $item->id,
                                    'custom_field_id' => $customFieldId,
                                    'language_id' => $languageId,
                                ],
                                [
                                    'value' => json_encode($value, JSON_THROW_ON_ERROR),
                                    'updated_at' => now(),
                                ]
                            );
                        }
                    }
                }
            }
            
            if ($request->hasFile('custom_field_files')) {
                $itemCustomFieldValues = [];
                foreach ($request->file('custom_field_files') as $key => $file) {
                    $value = ItemCustomFieldValue::where(['item_id' => $item->id, 'custom_field_id' => $key])->first();

                    // Get existing file path for replacement
                    $existingPath = null;
                    if ($value) {
                        $rawValue = $value->getRawOriginal('value');
                        if (!empty($rawValue)) {
                            $existingPath = $this->extractFilePath($rawValue);
                        }
                    }

                    // The replaced file is deleted after the commit.
                    $path = FileService::upload($file, 'custom_fields_files');
                    if ($existingPath && $existingPath !== $path) {
                        $filesToDelete[] = $existingPath;
                    }

                    
                    // Store as single plain path
                    ItemCustomFieldValue::updateOrCreate([
                        'item_id' => $item->id,
                        'custom_field_id' => $key,
                    ],
                    [
                        'value' => $path,
                        'language_id' => HelperService::getDefaultLanguageId(),
                        'updated_at' => now(),
                    ]);
                }
            }
            
            $itemImagesExists = ItemImages::where('item_id', $item->id)->exists();
            if ($request->hasFile('gallery_images')) {
                $galleryImages = [];
                foreach ($request->file('gallery_images') as $index => $file) {
                    $galleryImages[] = [
                        'image'      => FileService::compressAndUpload($file, 'item_images', true),
                        'is_default' => (!$itemImagesExists && $index === 0) ? 1 : 0,
                        'item_id'    => $item->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                ItemImages::insert($galleryImages);
            }

            // Custom field files
            foreach ($request->allFiles() as $key => $file) {
                if (Str::startsWith($key, 'custom_fields.')) {
                    $customFieldId = Str::after($key, 'custom_fields.');
                    $value = ItemCustomFieldValue::where(['item_id' => $item->id, 'custom_field_id' => $customFieldId])->first();

                    // Get existing file path for replacement
                    $existingPath = null;
                    if ($value) {
                        $rawValue = $value->getRawOriginal('value');
                        if (!empty($rawValue)) {
                            $existingPath = $this->extractFilePath($rawValue);
                        }
                    }

                    // The replaced file is deleted after the commit.
                    $filePath = FileService::upload($file, 'custom_fields_files');
                    if ($existingPath && $existingPath !== $filePath) {
                        $filesToDelete[] = $existingPath;
                    }

                    // Store as single plain path
                    ItemCustomFieldValue::updateOrCreate(
                        ['item_id' => $item->id, 'custom_field_id' => $customFieldId],
                        ['value' => $filePath, 'language_id' => HelperService::getDefaultLanguageId(), 'updated_at' => now()]
                    );
                }
            }
            
            // Scoped to this item: ids of another item's images are ignored.
            // The at-least-one / limit checks ran before the transaction.
            if ($deleteImageIds->isNotEmpty()) {
                $deletedDefault = false;
                $itemImagesInDB = ItemImages::where('item_id', $item->id)->whereIn('id', $deleteImageIds)->get();
                foreach ($itemImagesInDB as $itemImage) {
                    if ($itemImage->is_default) {
                        $deletedDefault = true;
                    }
                    FileService::delete($itemImage->getRawOriginal('image'));
                    $itemImage->delete();
                }

                if ($deletedDefault || !ItemImages::where('item_id', $item->id)->where('is_default', 1)->exists()) {
                    $firstImg = ItemImages::where('item_id', $item->id)->orderBy('id')->first();
                    if ($firstImg) {
                        $firstImg->update(['is_default' => 1]);
                    }
                }
            }

            // Handle reel video on update
            // Case 1: user explicitly requested reel deletion
            if ($request->input('delete_reel') == '1') {
                $existingReel = Reel::where('item_id', $item->id)->first();
                if ($existingReel) {
                    $existingReel->delete(); // boot() handles file cleanup
                }
                $item->update(['item_type' => 'normal']);
            }

            // Case 2: new reel video uploaded — delete old reel (if any), then create new one
            if ($request->hasFile('reel_video') && $request->input('item_type') === 'reel') {
                $maxFileSizeMb = (int) (CachingService::getSystemSettings('reel_max_file_size_mb') ?: 50);
                $videoFile = $request->file('reel_video');
                if ($videoFile->getSize() > $maxFileSizeMb * 1024 * 1024) {
                    DB::rollBack();
                    ResponseService::errorResponse("Reel video exceeds maximum allowed size of {$maxFileSizeMb} MB.");
                    return;
                }

                // Delete old reel first
                $existingReel = Reel::where('item_id', $item->id)->first();
                if ($existingReel) {
                    $existingReel->delete();
                }

                $videoPath = FileService::upload($videoFile, 'reels');

                // Thumbnail: prefer captured dataURL, fall back to nothing
                $thumbnailPath = null;
                $thumbData = $request->input('reel_thumbnail_data');
                if (!empty($thumbData) && str_starts_with($thumbData, 'data:image')) {
                    $base64  = preg_replace('/^data:image\/\w+;base64,/', '', $thumbData);
                    $decoded = base64_decode($base64);
                    $tmpFile = tempnam(sys_get_temp_dir(), 'reel_thumb_') . '.jpg';
                    file_put_contents($tmpFile, $decoded);
                    $uploadedFile  = new UploadedFile($tmpFile, 'thumbnail.jpg', 'image/jpeg', null, true);
                    $thumbnailPath = FileService::compressAndUpload($uploadedFile, 'reel_thumbnails', false);
                }

                Reel::create([
                    'item_id'   => $item->id,
                    'video'     => $videoPath,
                    'thumbnail' => $thumbnailPath,
                ]);

                // Ensure item_type is saved as reel
                $item->update(['item_type' => 'reel']);
            }

            DB::commit();

            foreach (array_unique($filesToDelete) as $path) {
                FileService::delete($path);
            }

            // Store SEO details (outside transaction as it's non-critical)
            $languages = CachingService::getLanguages();
            HelperService::storeSeoDetails($item, $request, $languages->pluck('id')->toArray());

            if (! empty($item->user->id)) {
                // Dispatch chunked notification jobs using centralized service
                NotificationService::dispatchChunkedNotifications(
                    'About ' . $item->name,
                    'Your Advertisement is edited by admin',
                    'item-edit',
                    ['id' => $request->id],
                    false,
                    array($item->user->id)
                );
            }

            ResponseService::successResponse('Advertisement Updated Successfully');
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th, 'ItemController -> update', 'An error occurred while updating the Advertisement.', false);
            ResponseService::errorResponse('An error occurred while updating the Advertisement.');
        }
    }

    public function getCustomFields(Request $request, $categoryId)
    {
        $categoryIds = $this->getParentCategoryIds($categoryId);
        $category = Category::find($categoryId);
        $itemId = $request->input('item_id');
        
        $customFields = CustomField::with('translations')
            ->whereHas('custom_field_category', function ($q) use ($categoryIds) {
                $q->whereIn('category_id', $categoryIds);
            })
            ->where('status', 1)
            ->get();
        
        // Load existing values if item_id is provided
        $savedValuesByField = collect();
        $savedValuesByLanguage = collect();
        if ($itemId) {
            $savedValues = ItemCustomFieldValue::where('item_id', $itemId)->get();
            $defaultLanguageId = CachingService::getDefaultLanguage()->id;
            $savedValuesByField = $savedValues->filter(function ($item) use ($defaultLanguageId) {
                return $item->language_id === null || $item->language_id == $defaultLanguageId;
            })->keyBy('custom_field_id');
            $savedValuesByLanguage = $savedValues->filter(function ($item) use ($defaultLanguageId) {
                return $item->language_id !== null && $item->language_id != $defaultLanguageId;
            })->groupBy('custom_field_id');
        }
        
        $customFields = $customFields->map(function ($field) use ($savedValuesByField, $savedValuesByLanguage) {
            $field->has_translations = $field->translations->isNotEmpty();
            $field->translations_count = $field->translations->count();
            
            // Load existing value for this field
            $valueRecord = $savedValuesByField->get($field->id);
            if ($valueRecord) {
                $rawValue = $valueRecord->getRawOriginal('value');
                if (!empty($rawValue)) {
                    if ($field->type === 'fileinput') {
                        $filePath = $this->extractFilePath($rawValue);
                        $field->value = !empty($filePath) ? url(Storage::url($filePath)) : '';
                    } else {
                        $decoded = json_decode($rawValue, true);
                        if (is_array($decoded)) {
                            if (in_array($field->type, ['textbox', 'number'])) {
                                $field->value = count($decoded) === 1 ? ($decoded[0] ?? '') : implode(', ', $decoded);
                            } else {
                                $field->value = $decoded;
                            }
                        } else {
                            $field->value = $decoded ?? '';
                        }
                    }
                } else {
                    $field->value = '';
                }
            } else {
                $field->value = '';
            }
            
            // Load translated values
            $translatedValues = [];
            if ($savedValuesByLanguage->has($field->id)) {
                foreach ($savedValuesByLanguage->get($field->id) as $translatedValueRecord) {
                    $langId = $translatedValueRecord->language_id;
                    $rawValue = $translatedValueRecord->getRawOriginal('value');
                    if (!empty($rawValue)) {
                        $decoded = json_decode($rawValue, true);
                        if (is_array($decoded)) {
                            $translatedValues[$langId] = count($decoded) === 1 ? ($decoded[0] ?? '') : $decoded;
                        } else {
                            $translatedValues[$langId] = $decoded ?? '';
                        }
                    }
                }
            }
            $field->translated_values = $translatedValues;
            
            return $field;
        });

        return response()->json([
            'fields' => $customFields,
            'is_job_category' => $category->is_job_category,
            // Pricing modes, image/video limits and product video types the
            // form applies (see items/partials/module-rules.blade.php).
            'module_settings' => ModuleService::settings(ModuleService::moduleIdForCategory($categoryId)),
            'category_ids' => $categoryIds,
        ]);
    }

    protected function getParentCategoryIds($categoryId, &$ids = [])
    {
        $category = Category::find($categoryId);

        if ($category) {
            $ids[] = $category->id;
            if ($category->parent_category_id) {
                $this->getParentCategoryIds($category->parent_category_id, $ids);
            }
        }

        return array_reverse($ids);
    }

    /**
     * Extract a single file path from a raw value.
     * Handles both old JSON-encoded data (e.g. '["path/to/file.jpg"]') and plain path strings.
     */
    protected function syncItemVideo(Request $request, Item $item): void
    {
        $videoType = $request->input('video_type');

        if ($request->boolean('delete_item_video')) {
            $existing = $item->itemVideo;
            if ($existing && $existing->video_type === 'file' && $existing->getRawOriginal('video_file')) {
                FileService::delete($existing->getRawOriginal('video_file'));
            }
            $item->itemVideo()->delete();
            return;
        }

        if (!$videoType) {
            return;
        }

        $updateData = ['video_type' => $videoType, 'video_link' => null, 'video_file' => null];

        if (in_array($videoType, ['youtube_link', 'vimeo_link', 'other_link'])) {
            $updateData['video_link'] = $request->input('video_link');
        } elseif ($videoType === 'file' && $request->hasFile('item_video')) {
            $existing = $item->itemVideo;
            if ($existing && $existing->video_type === 'file' && $existing->getRawOriginal('video_file')) {
                FileService::delete($existing->getRawOriginal('video_file'));
            }
            $updateData['video_file'] = FileService::upload($request->file('item_video'), 'item_videos');
        } else {
            return;
        }

        ItemVideo::updateOrCreate(
            ['item_id' => $item->id],
            $updateData
        );
    }

    protected function extractFilePath($rawValue)
    {
        if (empty($rawValue)) {
            return '';
        }

        if (json_validate($rawValue)) {
            $decoded = json_decode($rawValue, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded[0] ?? '';
            }
            return is_string($decoded) ? $decoded : '';
        }

        return $rawValue;
    }

    /**
     * For radio/checkbox/dropdown fields, keep only the stored values that still
     * exist among the field's current option list. Removed options must not surface.
     */
    protected function filterValidValues($customField, $itemCustomFieldValue)
    {
        $actualValues = is_array($itemCustomFieldValue->value) ? $itemCustomFieldValue->value : json_decode($itemCustomFieldValue->value, true);
        if (! is_array($actualValues)) {
            $actualValues = [$actualValues];
        }

        $allPossibleLower = array_map(static fn ($v) => mb_strtolower((string) $v), $customField->values ?? []);

        return array_values(array_filter($actualValues, static function ($val) use ($allPossibleLower) {
            return in_array(mb_strtolower((string) $val), $allPossibleLower, true);
        }));
    }

    public function create()
    {
        ResponseService::noAnyPermissionThenRedirect(['advertisement-create']);

        // No need to load categories here, they'll be loaded via AJAX
        $countries = Country::where('status',true)->get();
        $currencies = Currency::all();
        $languages = CachingService::getLanguages()->values();
        $defaultLanguage = CachingService::getDefaultLanguage();

        $geminiEnabled = CachingService::getSystemSettings('gemini_ai_enabled') === '1';
        $geminiAutoTranslateEnabled = $geminiEnabled && CachingService::getSystemSettings('gemini_auto_translate_enabled') === '1';
        $mapProvider = CachingService::getSystemSettings('map_provider') ?? 'free_api';
        $googleMapKey = CachingService::getSystemSettings('google_map_key');
        $defaultLatitude = CachingService::getSystemSettings('default_latitude');
        $defaultLongitude = CachingService::getSystemSettings('default_longitude');
        // The first steps of the form: Module → Format (ads type) → Category. ?module_id= and
        // ?item_type=normal|reel preselect them; a lone active module is preselected as well.
        $modules = Module::active()->orderBy('sequence')->get();
        $moduleId = $modules->firstWhere('id', (int) request('module_id'))?->id
            ?? ($modules->count() === 1 ? $modules->first()->id : '');
        $itemType = in_array(request('item_type'), ['normal', 'reel'], true) ? request('item_type') : '';
        return view('items.create', compact('countries', 'currencies', 'languages', 'defaultLanguage', 'geminiEnabled', 'geminiAutoTranslateEnabled', 'mapProvider', 'googleMapKey', 'defaultLatitude', 'defaultLongitude', 'modules', 'moduleId', 'itemType'));
    }

    public function getParentCategories(Request $request)
    {
        // Category step of both the create and the edit wizard.
        ResponseService::noAnyPermissionThenSendJson(['advertisement-create', 'advertisement-update']);

        try {
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 10);

            $categories = Category::whereNull('parent_category_id')
                ->where('status', 1)
                ->forModule($request->input('module_id'))
                ->orderBy('sequence', 'ASC')
                ->withCount(['subcategories' => function ($q) {
                    $q->where('status', 1);
                }])
                ->skip(($page - 1) * $perPage)
                ->take($perPage + 1)
                ->get(['id', 'name', 'status', 'image']);

            $hasMore = $categories->count() > $perPage;
            $categories = $categories->take($perPage);

            return response()->json([
                'message' => 'Success',
                'data' => $categories,
                'has_more' => $hasMore,
                'current_page' => $page,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemController -> getParentCategories');

            return response()->json(['message' => 'Error loading categories'], 500);
        }
    }

    public function getSubCategories(Request $request)
    {
        // Category step of both the create and the edit wizard.
        ResponseService::noAnyPermissionThenSendJson(['advertisement-create', 'advertisement-update']);

        $validator = Validator::make($request->all(), [
            'category_id' => 'required|integer',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        try {
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 10);

            $subcategories = Category::where('parent_category_id', $request->category_id)
                ->where('status', 1)
                ->orderBy('sequence', 'ASC')
                ->withCount(['subcategories' => function ($q) {
                    $q->where('status', 1);
                }])
                ->skip(($page - 1) * $perPage)
                ->take($perPage + 1)
                ->get(['id', 'name', 'parent_category_id', 'status', 'image']);

            $hasMore = $subcategories->count() > $perPage;
            $subcategories = $subcategories->take($perPage);

            return response()->json([
                'message' => 'Success',
                'data' => $subcategories,
                'has_more' => $hasMore,
                'current_page' => $page,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemController -> getSubCategories');

            return response()->json(['message' => 'Error loading subcategories'], 500);
        }
    }

    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('advertisement-create');

        $moduleId = ModuleService::moduleIdForCategory($request->category_id);
        $moduleSettings = ModuleService::settings($moduleId);
        $maxGalleryImages = (int) $moduleSettings['max_images'];
        $itemVideoMaxSizeMb = (int) $moduleSettings['video_max_file_size_mb'];
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|regex:/^[a-z0-9-]+$/',
            'description' => 'required|string',
            'latitude' => 'required',
            'longitude' => 'required',
            'address' => 'nullable',
            'contact' => 'nullable',
            'show_mobile_number' => 'nullable|boolean',
            'custom_fields' => 'nullable',
            'custom_field_files' => 'nullable|array',
            'custom_field_files.*' => 'nullable|mimes:jpeg,png,jpg,pdf,doc|max:7168',
            'gallery_images' => 'nullable|array|max:' . $maxGalleryImages,
            'gallery_images.*' => 'nullable|mimes:jpeg,png,jpg|max:7168',
            'video_type'      => 'nullable|in:youtube_link,vimeo_link,other_link,file',
            'video_link'      => [
                'required_if:video_type,youtube_link,vimeo_link,other_link',
                'nullable',
                'url',
                new VideoLink($request->input('video_type')),
            ],
            'item_video'      => 'nullable|file|mimes:mp4,webm,avi,mov|max:' . ($itemVideoMaxSizeMb * 1024),
            'category_id'     => 'required|integer',
            'currency_id'     => 'nullable|integer',
            'item_type'       => 'nullable|in:normal,reel',
            'reel_video'      => 'nullable|file|mimes:mp4|max:512000',
            'reel_start_time' => 'nullable|numeric|min:0',
        ], [
            // max_images counts the cover too: gallery_images[0] is the cover.
            'gallery_images.max' => __('You can upload a maximum of :max images (1 cover + :gallery gallery images).', [
                'max' => $maxGalleryImages,
                'gallery' => max($maxGalleryImages - 1, 0),
            ]),
        ]);

        if ($validator->fails()) {
            $errorMessage = $validator->errors()->first();
            ResponseService::errorResponse($errorMessage);
            return; // Ensure execution stops
        }

        if ($schemaError = HelperService::seoSchemaError($request, CachingService::getLanguages()->pluck('id')->all())) {
            ResponseService::errorResponse($schemaError);
            return;
        }

        // Ensure database connection is alive before starting transaction
        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            DB::reconnect();
        }

        DB::beginTransaction();
        try {
            Category::findOrFail($request->category_id);
            // Module settings of the category: product video type and
            // pricing. range reuses min_salary / max_salary as the price
            // range — see ItemModuleValidator.
            $moduleError = ItemModuleValidator::videoTypeError($moduleSettings, ItemModuleValidator::submittedVideoType($request, 'item_video'));
            [$pricingError, $pricingAttributes] = $moduleError ? [null, []] : ItemModuleValidator::pricing($moduleSettings, $request);

            if ($moduleError || $pricingError) {
                DB::rollBack();
                ResponseService::errorResponse($moduleError ?? $pricingError);
                return; // Ensure execution stops
            }

            $customFieldCategories = CustomFieldCategory::with('custom_fields')
                ->whereIn('category_id', $this->getParentCategoryIds($request->category_id))
                ->get();

            $customFieldErrors = [];
            foreach ($customFieldCategories as $relation) {
                $field = $relation->custom_fields;
                if (empty($field) || $field->status != 1) {
                    continue;
                }

                $fieldId = $field->id;
                $fieldLabel = $field->name;
                $val = $request->input("custom_fields.$fieldId");

                // Required check
                if ($field->required == 1) {
                    if (in_array($field->type, ['textbox', 'number', 'dropdown', 'radio'])) {
                        if ($val === null || $val === '' || (is_array($val) && empty($val))) {
                            $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field is required.";
                            continue;
                        }
                    }

                    if ($field->type === 'checkbox') {
                        if (! is_array($val) || empty($val)) {
                            $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field is required.";
                            continue;
                        }
                    }

                    if ($field->type === 'fileinput') {
                        if (! $request->hasFile("custom_field_files.$fieldId")) {
                            $customFieldErrors["custom_field_files.$fieldId"] = "The $fieldLabel file is required.";
                            continue;
                        }
                    }
                }

                // Min/Max restrictions (run if value is present)
                if ($val !== null && $val !== '' && !(is_array($val) && empty($val))) {
                    if ($field->type === 'textbox') {
                        $valStr = (string)$val;
                        if (!is_null($field->min_length) && strlen($valStr) < $field->min_length) {
                            $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field must be at least {$field->min_length} characters.";
                        }
                        if (!is_null($field->max_length) && strlen($valStr) > $field->max_length) {
                            $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field must not exceed {$field->max_length} characters.";
                        }
                    } elseif ($field->type === 'number') {
                        if (!is_numeric($val)) {
                            $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field must be a number.";
                        } else {
                            $digitLen = strlen((string) preg_replace('/[^0-9]/', '', (string) $val));
                            if (!is_null($field->min_length) && $digitLen < $field->min_length) {
                                $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field must be at least {$field->min_length} digits.";
                            }
                            if (!is_null($field->max_length) && $digitLen > $field->max_length) {
                                $customFieldErrors["custom_fields.$fieldId"] = "The $fieldLabel field must not exceed {$field->max_length} digits.";
                            }
                        }
                    }
                }
            }

            if (! empty($customFieldErrors)) {
                DB::rollBack();
                $errorMessage = reset($customFieldErrors); // Get first error message
                ResponseService::errorResponse($errorMessage);
                return; // Ensure execution stops
            }

            $slug = trim($request->input('slug') ?? '');
            $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($slug));
            $slug = trim($slug, '-');
            if (empty($slug)) {
                $slug = HelperService::generateRandomSlug();
            }
            $uniqueSlug = HelperService::generateUniqueSlug(new Item, $slug);

            $user = Auth::user();

            $data = [
                'name' => $request->name,
                'slug' => $uniqueSlug,
                'description' => $request->description,
                'address' => $request->input('address') ?? $request->input('address_input') ?? '',
                'country' => $request->input('country_input') ?? '',
                'state' => $request->input('state_input') ?? '',
                'city' => $request->input('city_input') ?? '',
                'latitude' => $request->input('latitude'),
                'longitude' => $request->input('longitude'),
                'contact' => $request->contact ?? $user->contact,
                'country_code' => $request->country_code ?? $user->country_code ?? null,
                'region_code' => $request->region_code ?? $user->region_code ?? null,
                'show_mobile_number' => $request->boolean('show_mobile_number'),
                'category_id' => $request->category_id,
                ...$pricingAttributes,
                'item_type' => $request->input('item_type', 'normal'),
                'user_id' => $user->id,
                'status' => 'approved',
                'currency_id' => $request->currency_id ?? null,
            ];

            $item = Item::create($data);

            // Handle item video
            $this->syncItemVideo($request, $item);

            // Handle translations - only name and description are translatable
            if ($request->has('translations')) {
                foreach ($request->input('translations', []) as $languageId => $translationData) {
                    if (!empty($translationData['name']) || !empty($translationData['description'])) {
                        HelperService::storeTranslations([
                            ['translatable_id' => $item->id, 'translatable_type' => \App\Models\Item::class, 'key' => 'name', 'value' => $translationData['name'] ?? '', 'language_id' => $languageId],
                            ['translatable_id' => $item->id, 'translatable_type' => \App\Models\Item::class, 'key' => 'description', 'value' => $translationData['description'] ?? '', 'language_id' => $languageId],
                        ]);
                    }
                }
            }

            if ($request->custom_fields) {
                foreach ($request->custom_fields as $key => $custom_field) {
                    $value = is_array($custom_field) ? $custom_field : [$custom_field];
                    ItemCustomFieldValue::create([
                        'item_id' => $item->id,
                        'custom_field_id' => $key,
                        'value' => json_encode($value, JSON_THROW_ON_ERROR),
                    ]);
                }
            }

            // Handle custom field translations
            if ($request->has('custom_field_translations')) {
                $customFieldTranslations = $request->input('custom_field_translations');
                if (is_array($customFieldTranslations)) {
                    foreach ($customFieldTranslations as $languageId => $fieldsByCustomField) {
                        foreach ($fieldsByCustomField as $customFieldId => $translatedValue) {
                            $value = is_array($translatedValue) ? $translatedValue : [$translatedValue];
                            ItemCustomFieldValue::create([
                                'item_id' => $item->id,
                                'custom_field_id' => $customFieldId,
                                'language_id' => $languageId,
                                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                            ]);
                        }
                    }
                }
            }

            if ($request->hasFile('custom_field_files')) {
                foreach ($request->file('custom_field_files') as $key => $file) {
                    $path = FileService::upload($file, 'custom_fields_files');
                    ItemCustomFieldValue::create([
                        'item_id' => $item->id,
                        'custom_field_id' => $key,
                        'value' => $path,
                    ]);
                }
            }
            
            if ($request->hasFile('gallery_images')) {
                $galleryImages = [];
                foreach ($request->file('gallery_images') as $index => $file) {
                    $galleryImages[] = [
                        'image'      => FileService::compressAndUpload($file, 'item_images', true),
                        'is_default' => ($index === 0) ? 1 : 0,
                        'item_id'    => $item->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                 if (count($galleryImages) > 0) {
                    ItemImages::insert($galleryImages);
                }
            }

            // Handle reel video + thumbnail (video is pre-trimmed client-side via MediaRecorder)
            if ($request->input('item_type') === 'reel' && $request->hasFile('reel_video')) {
                $maxFileSizeMb = (int) (CachingService::getSystemSettings('reel_max_file_size_mb') ?: 50);

                $videoFile = $request->file('reel_video');
                if ($videoFile->getSize() > $maxFileSizeMb * 1024 * 1024) {
                    DB::rollBack();
                    ResponseService::errorResponse("Reel video exceeds maximum allowed size of {$maxFileSizeMb} MB.");
                    return;
                }

                $videoPath = FileService::upload($videoFile, 'reels');

                // Thumbnail: prefer captured dataURL, fall back to custom upload
                $thumbnailPath = null;
                $thumbData = $request->input('reel_thumbnail_data');
                if (!empty($thumbData) && str_starts_with($thumbData, 'data:image')) {
                    $base64   = preg_replace('/^data:image\/\w+;base64,/', '', $thumbData);
                    $decoded  = base64_decode($base64);
                    $tmpFile  = tempnam(sys_get_temp_dir(), 'reel_thumb_') . '.jpg';
                    file_put_contents($tmpFile, $decoded);
                    $uploadedFile = new UploadedFile($tmpFile, 'thumbnail.jpg', 'image/jpeg', null, true);
                    $thumbnailPath = FileService::compressAndUpload($uploadedFile, 'reel_thumbnails', false);
                }

                Reel::create([
                    'item_id'   => $item->id,
                    'video'     => $videoPath,
                    'thumbnail' => $thumbnailPath,
                ]);
            }

            // Custom field files from direct custom_fields input
            foreach ($request->allFiles() as $key => $file) {
                if (Str::startsWith($key, 'custom_fields.')) {
                    $customFieldId = Str::after($key, 'custom_fields.');
                    $filePath = FileService::upload($file, 'custom_fields_files');
                    ItemCustomFieldValue::create([
                        'item_id' => $item->id,
                        'custom_field_id' => $customFieldId,
                        'value' => $filePath,
                    ]);
                }
            }

            DB::commit();

            // Store SEO details (outside transaction as it's non-critical)
            $languages = CachingService::getLanguages();
            HelperService::storeSeoDetails($item, $request, $languages->pluck('id')->toArray());

            ResponseService::successResponse('Advertisement Created Successfully');
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th, 'ItemController -> store', 'An error occurred while creating the Advertisement.', false);

            ResponseService::errorResponse('An error occurred while creating the Advertisement.');
        }
    }
}
