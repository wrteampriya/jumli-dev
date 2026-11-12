<?php

namespace App\Http\Controllers\Api;

use Illuminate\Support\Str;
use App\Support\DbCompat;
use App\Http\Resources\ItemApiResource;
use App\Models\Area;
use App\Models\Category;
use App\Models\Favourite;
use App\Models\FeaturedItems;
use App\Models\FeatureSection;
use App\Models\HomeScreenSection;
use App\Models\Item;
use App\Models\ItemCustomFieldValue;
use App\Models\ItemImages;
use App\Models\ItemOffer;
use App\Models\Language;
use App\Models\Module;
use App\Models\Package;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserFollow;
use App\Models\UserPurchasedPackage;
use App\Services\CachingService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\Module\ItemModuleValidator;
use App\Services\NotificationService;
use App\Services\ResponseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\ItemVideo;
use App\Models\Reel;
use App\Rules\VideoLink;
use Throwable;
use App\Services\Module\ModuleService;

/**
 * @tags Item
 */
class ItemApiController extends BaseApiController
{
    private string $uploadFolder;

    public function __construct()
    {
        parent::__construct();
        $this->uploadFolder = 'item_images';
    }

    /**
     * Get Limits
     */
    public function getLimits(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'package_type' => 'required|in:item_listing,advertisement',
            ]);
            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }
            $setting = Setting::where('name', 'free_ad_listing')->first()['value'];
            $moduleId = $this->currentModuleId();
            // A module with Ad Packages switched off lists freely, same as free_ad_listing — see addItem.
            $listsFreely = $setting == 1
                || ($moduleId && ! ModuleService::setting($moduleId, 'ad_packages_enabled'));
            if ($listsFreely && $request->package_type != 'advertisement') {
                return ResponseService::successResponse(__('User is allowed to create Advertisement'));
            }
            // Only the X-Module module's packages count (no-op without the header).
            $user_package = UserPurchasedPackage::onlyActive()->whereHas('package', function ($q) use ($request, $moduleId) {
                $q->where('type', $request->package_type)->forModule($moduleId);
            })->count();
            if ($user_package > 0) {
                ResponseService::successResponse(__('User is allowed to create Advertisement'));
            }
            ResponseService::errorResponse(__('User is not allowed to create Advertisement'), $user_package);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getLimits');
            ResponseService::errorResponse();
        }
    }

    /**
     * Add Item
     */
    public function addItem(Request $request)
    {
        try {
            $moduleId = ModuleService::moduleIdForCategory($request->category_id);
            $moduleSettings = ModuleService::settings($moduleId);
            $maxGalleryImages = (int) $moduleSettings['max_images'];
            // Module-aware clients send X-Module and choose a pricing_mode;
            // old clients keep the legacy price/salary rules below.
            $isModuleRequest = (bool) $this->currentModuleId();
            // Step 1: Base validation rules
            $rules = [
                'item_type'            => 'required|in:normal,reel',
                'name'                 => 'required',
                'category_id'          => 'required|integer',
                'description'          => 'required',
                'latitude'             => 'required',
                'longitude'            => 'required',
                'address'              => 'required',
                'contact'              => 'nullable|numeric',
                'video_type'           => 'nullable|in:youtube_link,vimeo_link,other_link,file',
                'video_link'           => [
                    'required_if:video_type,youtube_link,vimeo_link,other_link',
                    'nullable',
                    'url',
                    new VideoLink($request->input('video_type')),
                ],
                'gallery_images'       => 'required|array|min:1|max:' . $maxGalleryImages,
                'gallery_images.*'     => 'required|mimes:jpeg,png,jpg|max:7168',
                'country'              => 'required',
                'state'                => 'nullable',
                'city'                 => 'required',
                /** Area name; matched to the `areas` table within the item's city and saved as `area_id` */
                'area'                 => 'nullable|string|max:255|exists:areas,name',
                'custom_field_files'   => 'nullable|array',
                'custom_field_files.*' => 'nullable|mimes:jpeg,png,jpg,pdf,doc|max:7168',
                'slug' => [
                    'nullable',
                    'regex:/^(?!-)(?!.*--)(?!.*-$)(?!-$)[a-z0-9-]+$/',
                ],
                'region_code'      => 'nullable|string',
                'country_code'     => 'nullable|string',
                'currency_id'      => 'nullable|exists:currencies,id',
                'all_category_ids'   => 'nullable',
                'seo_details'       => 'nullable|array',
                'seo_details.*'     => 'nullable|array',
                'seo_details.*.meta_title' => 'nullable|string',
                'seo_details.*.meta_description' => 'nullable|string',
                'seo_details.*.meta_keywords' => 'nullable|string',
                'seo_details.*.schema' => 'nullable|string',
            ];

            // Step 2: Extend rules based on category
            $category      = Category::findOrFail($request->category_id);
            $isJobCategory = $category->is_job_category;

            if ($isModuleRequest) {
                // Price fields are checked against the module's pricing
                // modes by ItemModuleValidator::pricing() below.
                $rules['pricing_mode'] = 'nullable|string';
            } elseif ($isJobCategory) {
                $rules['min_salary'] = 'nullable|numeric|min:0';
                if (isset($request->min_salary) && $request->min_salary > 0) {
                    $rules['max_salary'] = 'nullable|numeric|gte:min_salary';
                }
            } else {
                $rules['price'] = 'required|numeric|min:0';
            }

            // Step 3: Run single combined validator
            $validator = Validator::make($request->all(), $rules, [
                // max_images counts the cover too: gallery_images[0] is the cover.
                'gallery_images.max' => __('You can upload a maximum of :max images (1 cover + :gallery gallery images).', [
                    'max' => $maxGalleryImages,
                    'gallery' => max($maxGalleryImages - 1, 0),
                ]),
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            // Step 3b: Module settings — category/module match, pricing and
            // product video.
            $moduleError = ItemModuleValidator::moduleMismatchError($moduleId)
                ?? ItemModuleValidator::videoTypeError($moduleSettings, ItemModuleValidator::submittedVideoType($request));

            if ($moduleError) {
                return ResponseService::validationError($moduleError);
            }

            if ($isModuleRequest) {
                [$pricingError, $pricingAttributes] = ItemModuleValidator::pricing($moduleSettings, $request);

                if ($pricingError) {
                    return ResponseService::validationError($pricingError);
                }
            } else {
                $pricingAttributes = ['pricing_mode' => ItemModuleValidator::legacyPricingMode($request)];
            }

            // Step 4: Translations validation (separate, throws on failure)
            $translations = json_decode($request->input('translations', '{}'), true, 512, JSON_THROW_ON_ERROR);
            if (!empty($translations)) {
                foreach ($translations as $languageId => $translation) {
                    Validator::make($translation, [
                        'name'              => 'required|string|max:255',
                        'slug'              => 'nullable|regex:/^[a-z0-9-]+$/',
                        'description'       => 'nullable|string',
                        'address'           => 'nullable|string',
                        'video_link'        => 'nullable|url',
                        'rejected_reason'   => 'nullable|string',
                        'admin_edit_reason' => 'nullable|string',
                    ])->validate();
                }
            }
            DB::beginTransaction();
            $user = Auth::user();
            $free_ad_listing = Setting::where('name', 'free_ad_listing')->value('value') ?? 0;
            $auto_approve_item = Setting::where('name', 'auto_approve_item')->value('value') ?? 0;

            if ($auto_approve_item == 1 || $user->auto_approve_item == 1) {
                $status = 'approved';
            } else {
                $status = 'review';
            }

            // Get all active packages for the user
            $user_packages = null;
            $selectedPackageId = null;
            $selectedUserPackage = null;

            $listingModuleId = ModuleService::moduleIdForCategory($request->category_id);

            // Only check package if free_ad_listing is not enabled and the
            // category's module has Ad Packages (listing limits) switched on
            if ($free_ad_listing != 1 && ModuleService::setting($listingModuleId, 'ad_packages_enabled')) {
                // Get ALL active packages for the user — only this module's
                // packages can cover the listing
                $user_packages = UserPurchasedPackage::onlyActive()
                    ->whereHas('package', static function ($q) use ($listingModuleId) {
                        $q->where('type', 'item_listing')->forModule($listingModuleId);
                    })
                    ->where('user_id', $user->id)
                    ->with('package.package_categories')
                    ->get();

                if ($user_packages->isEmpty()) {
                    DB::rollBack();
                    ResponseService::errorResponse(__('No Active Package found for Advertisement Creation'));
                }

                // Get all_category_ids from request (should contain category and all parent categories)
                $allCategoryIds = $request->input('all_category_ids', []);

                // If it comes as comma-separated string, convert to array
                if (is_string($allCategoryIds)) {
                    $allCategoryIds = array_filter(
                        array_map('intval', explode(',', $allCategoryIds))
                    );
                }

                // Ensure it's always an array
                if (! is_array($allCategoryIds)) {
                    $allCategoryIds = [];
                }

                foreach ($allCategoryIds as $key => $value) {
                    $category = Category::find($value);
                    if ($category) {
                        $allCategoryIds[] = $category->id;
                    }
                }


                // If all_category_ids not provided, build it from category_id
                if (empty($allCategoryIds) && $request->category_id) {
                    $allCategoryIds = [$request->category_id];
                    $currentCategoryId = $request->category_id;

                    // Traverse up the parent chain
                    while ($currentCategoryId) {
                        $parentCategory = Category::find($currentCategoryId);
                        if ($parentCategory && $parentCategory->parent_category_id) {
                            $allCategoryIds[] = $parentCategory->parent_category_id;
                            $currentCategoryId = $parentCategory->parent_category_id;
                        } else {
                            break;
                        }
                    }
                    $allCategoryIds = array_unique($allCategoryIds);
                }

                // Check for global package first
                $globalPackage = $user_packages->firstWhere(function ($userPackage) {
                    return $userPackage->package && $userPackage->package->is_global == 1;
                });

                if ($globalPackage) {
                    // Use global package
                    $selectedPackageId = $globalPackage->package_id;
                    $selectedUserPackage = $globalPackage;
                } else {
                    // No global package, check if any package contains any category from all_category_ids
                    $matchingPackage = null;

                    foreach ($user_packages as $user_package) {
                        if ($user_package->package && $user_package->package->is_global != 1) {
                            $packageCategoryIds = $user_package->package->package_categories
                                ->pluck('category_id')
                                ->toArray();

                            // Check if any category from all_category_ids is in this package
                            $hasMatchingCategory = ! empty(array_intersect($allCategoryIds, $packageCategoryIds));

                            if ($hasMatchingCategory) {
                                $matchingPackage = $user_package;
                                break;
                            }
                        }
                    }

                    if ($matchingPackage) {
                        $selectedPackageId = $matchingPackage->package_id;
                        $selectedUserPackage = $matchingPackage;
                    } else {
                        DB::rollBack();
                        ResponseService::errorResponse(__('Selected category is not available in your package'));
                    }
                }
            }

            // Increment used_limit for the selected package
            if ($selectedUserPackage) {
                $selectedUserPackage->used_limit++;
                $selectedUserPackage->save();
            }

            $slug = trim($request->input('slug') ?? '');
            $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($slug));
            $slug = trim($slug, '-');
            if (empty($slug)) {
                $slug = HelperService::generateRandomSlug();
            }
            $uniqueSlug = HelperService::generateUniqueSlug(new Item, $slug);

            // Calculate expiry date only when item is auto-approved; pending items get expiry_date on admin approval
            $expiryDate = null;
            if ($status === 'approved') {
                $package = $selectedPackageId ? Package::find($selectedPackageId) : null;
                $expiryDate = HelperService::calculateItemExpiryDate($package, $selectedUserPackage);
            }

            // Process files BEFORE creating item to reduce transaction time
            $customFieldFilePaths = [];

            // Process custom field files before transaction
            if ($request->hasFile('custom_field_files')) {
                foreach ($request->file('custom_field_files') as $key => $file) {
                    if (!empty($file)) {
                        $customFieldFilePaths[$key] = FileService::upload($file, 'custom_fields_files');
                    }
                }
            }

            $data = [
                ...$request->all(),
                // `area` is a name, matched to an area of the item's city; a raw `area_id` is ignored.
                'area_id'    => $this->areaIdFromName($request->input('area'), $request->input('city'), $request->input('state')),
                'name'       => $request->name,
                'slug'       => $uniqueSlug,
                'status'     => $status,
                'active'     => 'deactive',
                'user_id'    => $user->id,
                'package_id' => $selectedPackageId ?? null,
                'expiry_date'=> $expiryDate,
                'published_at' => $status === 'approved' ? Carbon::now() : null,
                ...$pricingAttributes,
            ];
            $item = Item::create($data);

            // Handle item video (link types only; file upload handled via upload-media API)
            $videoType = $request->input('video_type');
            if ($videoType && in_array($videoType, ['youtube_link', 'vimeo_link', 'other_link'])) {
                ItemVideo::create([
                    'item_id'    => $item->id,
                    'video_type' => $videoType,
                    'video_link' => $request->input('video_link'),
                ]);
            }



            if ($request->hasFile('gallery_images')) {
                $files = $request->file('gallery_images');
                $galleryImages = [];
                foreach ($files as $index => $file) {
                    $galleryImages[] = [
                        'image'      => FileService::compressAndUpload($file, 'item_images', true),
                        'is_default' => $index == 0 ? 1 : 0,
                        'item_id'    => $item->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                // Insert in chunks of 10 to avoid large queries
                if(!empty($galleryImages)){
                    foreach (array_chunk($galleryImages, 10) as $chunk) {
                        ItemImages::insert($chunk);
                    }
                }else{
                    ResponseService::validationError(__("Images are empty, need at least one image"));
                }
            }


            if (! empty($translations)) {
                foreach ($translations as $languageId => $translationData) {
                    // Optional: Check if language ID exists
                    if (Language::where('id', $languageId)->exists()) {
                        HelperService::storeTranslations([
                            ['translatable_id' => $item->id, 'translatable_type' => \App\Models\Item::class, 'key' => 'name', 'value' => $translationData['name'], 'language_id' => $languageId],
                            ['translatable_id' => $item->id, 'translatable_type' => \App\Models\Item::class, 'key' => 'description', 'value' => $translationData['description'] ?? '', 'language_id' => $languageId],
                            ['translatable_id' => $item->id, 'translatable_type' => \App\Models\Item::class, 'key' => 'address', 'value' => $translationData['address'] ?? '', 'language_id' => $languageId],
                            ['translatable_id' => $item->id, 'translatable_type' => \App\Models\Item::class, 'key' => 'rejected_reason', 'value' => $translationData['rejected_reason'] ?? null, 'language_id' => $languageId],
                            ['translatable_id' => $item->id, 'translatable_type' => \App\Models\Item::class, 'key' => 'admin_edit_reason', 'value' => $translationData['admin_edit_reason'] ?? null, 'language_id' => $languageId],
                        ]);
                    }
                }
            }

            // Process custom fields in chunks
            if ($request->custom_fields) {
                $defaultLanguageId = HelperService::getDefaultLanguageId();
                $itemCustomFieldValues = [];
                foreach (json_decode($request->custom_fields, true, 512, JSON_THROW_ON_ERROR) as $key => $custom_field) {
                    $itemCustomFieldValues[] = [
                        'item_id' => $item->id,
                        'language_id' => $defaultLanguageId,
                        'custom_field_id' => $key,
                        'value' => json_encode($custom_field, JSON_THROW_ON_ERROR),
                        'created_at' => time(),
                        'updated_at' => time(),
                    ];
                }

                if (count($itemCustomFieldValues) > 0) {
                    foreach (array_chunk($itemCustomFieldValues, 20) as $chunk) {
                        ItemCustomFieldValue::insert($chunk);
                    }
                }
            }

            if (!empty($customFieldFilePaths)) {
                $defaultLanguageId = HelperService::getDefaultLanguageId();
                $itemCustomFieldValues = [];
                foreach ($customFieldFilePaths as $key => $filePath) {
                    $itemCustomFieldValues[] = [
                        'item_id' => $item->id,
                        'language_id' => $defaultLanguageId,
                        'custom_field_id' => $key,
                        'value' => $filePath,
                        'created_at' => time(),
                        'updated_at' => time(),
                    ];
                }

                if (count($itemCustomFieldValues) > 0) {
                    foreach (array_chunk($itemCustomFieldValues, 20) as $chunk) {
                        try {
                            DB::connection()->getPdo();
                        } catch (\Exception $e) {
                            DB::reconnect();
                        }
                        ItemCustomFieldValue::insert($chunk);
                    }
                }
            }
            if ($request->has('custom_field_translations')) {
                $customFieldTranslations = $request->input('custom_field_translations');

                if (! is_array($customFieldTranslations)) {
                    $customFieldTranslations = html_entity_decode($customFieldTranslations);
                    $customFieldTranslations = json_decode($customFieldTranslations, true, 512, JSON_THROW_ON_ERROR);
                }

                $translatedEntries = [];

                foreach ($customFieldTranslations as $languageId => $fieldsByCustomField) {
                    foreach ($fieldsByCustomField as $customFieldId => $translatedValue) {
                        $translatedEntries[] = [
                            'item_id' => $item->id,
                            'custom_field_id' => $customFieldId,
                            'language_id' => $languageId,
                            'value' => json_encode($translatedValue, JSON_THROW_ON_ERROR),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }

                // Insert in chunks of 20
                if (! empty($translatedEntries)) {
                    foreach (array_chunk($translatedEntries, 20) as $chunk) {
                        try {
                            DB::connection()->getPdo();
                        } catch (\Exception $e) {
                            DB::reconnect();
                        }
                        ItemCustomFieldValue::insert($chunk);
                    }
                }
            }

            // Store SEO details from API (outside transaction)
            if ($request->has('seo_details')) {
                $seoData = $request->seo_details;
                if (!empty($seoData)) {
                    HelperService::storeSeoDetailsFromApi($item, $seoData);
                }
            }

            try {
                DB::connection()->getPdo();
            } catch (\Exception $e) {
                DB::reconnect();
            }


            DB::commit();

            $result = Item::with([
                'user:id,name,email,mobile,profile,country_code,created_at',
                'category:id,name,image,is_job_category,module_id',
                'category.module:id,slug,name,description,icon,path_prefix,status,sequence,version,plugin_slug',
                'gallery_images:id,image,item_id,is_default',
                'area:id,name',
                'translations',
                'seoDetail.translations',
            ])
                ->where('items.id', $item->id)
                ->first();

            if ($result) {
                $result->loadMissing(['featured_items', 'favourites']);
                if ($result->item_custom_field_values()->exists()) {
                    $result->load('item_custom_field_values.custom_field');
                }
            }

            $result = (new ItemApiResource(collect([$result])))->asDetail()->asMyItem();
            try {
                $followerIds = UserFollow::where('following_id', $user->id)
                    ->pluck('follower_id')
                    ->toArray();

                if (!empty($followerIds)) {
                    $userName = $user->name ?? 'Someone';
                    $notificationTitle = __('New Advertisement Posted');
                    $notificationMessage = __(':name has posted a new advertisement', ['name' => $userName]);

                    $customBodyFields = [
                        'item_id' => $item->id,
                        'user_id' => $user->id,
                        'user_name' => $userName,
                        'type' => 'new_item',
                    ];

                    NotificationService::dispatchChunkedNotifications(
                        $notificationTitle,
                        $notificationMessage,
                        'new-item',
                        $customBodyFields,
                        false,
                        $followerIds
                    );
                }
            } catch (Throwable $notificationError) {
                Log::error('Failed to send notifications to followers for new item', [
                    'item_id' => $item->id,
                    'user_id' => $user->id,
                    'error' => $notificationError->getMessage(),
                ]);
            }

            ResponseService::successResponse(__('Advertisement Added Successfully'), $result);
        } catch (Throwable $th) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            ResponseService::logErrorResponse($th, 'API Controller -> addItem');
            ResponseService::errorResponse();
        }
    }

    /**
     * Get Item List (lightweight) / Get Item Detail when `id` passed
     */
    public function getItemList(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'nullable|integer',
            'slug' => 'nullable|string',
            'excluded_item_id' => 'nullable|integer',
            'category_slug' => 'nullable|string',
            'search' => 'nullable|string',
            'limit' => 'nullable|integer',
            'page' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'user_id' => 'nullable|integer',
            'min_price' => 'nullable|integer',
            /** Must be greater than or equal to min_price */
            'max_price' => 'nullable|integer|gte:min_price',
            /** Required with Radius */
            'latitude' => 'nullable|numeric|required_with:longitude,radius',
            /** Required with Radius */
            'longitude' => 'nullable|numeric|required_with:latitude,radius',
            'radius' => 'nullable|numeric',
            'country' => 'nullable|string',
            'state' => 'nullable|string',
            'city' => 'nullable|string',
            'area' => 'nullable|string|max:255',
            'sort_by' => 'nullable|in:popular_items,new-to-old,old-to-new,price-high-to-low,price-low-to-high',
            'posted_since' => 'nullable|in:all-time,today,within-1-week,within-2-week,within-1-month,within-3-month',
            /** JSON object of custom_field_id => value (single) or custom_field_id => [values] (multiple) to filter by, e.g. {"1":"Red","2":["Large","Medium"]} */
            'custom_fields' => 'nullable|string',
            'featured_section_id' => 'nullable|integer',
            'featured_section_slug' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            if ($request->has('id') || $request->has('slug')) {
                $item = Item::with(
                    'user:id,name,email,mobile,profile,country_code,show_personal_details,is_verified',
                    'category:id,name,image,is_job_category,slug,module_id',
                    'category.module:id,slug,name,description,icon,path_prefix,status,sequence,version,plugin_slug',
                    'gallery_images:id,image,item_id,is_default',
                    'featured_items',
                    'favourites',
                    'translations',
                    'area',
                    'currency',
                    'seoDetail.translations',
                    'itemVideo',
                    'job_applications',
                    'review',
                    'item_custom_field_values.custom_field.translations'
                )->when($request->id, function ($query) use ($request) {
                    $query->where('id', $request->id);
                })
                ->when($request->slug, function ($query) use ($request) {
                    $query->where('slug', Str::lower($request->slug));
                })
                ->getNonExpiredItems()
                ->first();

                if (! $item) {
                    ResponseService::errorResponse(__('No item Found'));
                }

                Item::withoutTimestamps(function () use ($item) {
                    Item::where('id', $item->id)->increment('clicks');
                });
                $item->clicks++;

                if (Auth::check()) {
                    $item->setRelation('item_offers', $item->item_offers()->where('buyer_id', Auth::id())->get());
                    $item->setRelation('user_reports', $item->user_reports()->where('user_id', Auth::id())->get());
                }

                ResponseService::successResponse(__('Advertisement Fetched Successfully'), (new ItemApiResource(collect([$item])))->asDetail());

                return;
            }

            $limit = (int) ($request->limit ?? 10);
            $page = (int) ($request->page ?? 1);

            $baseQuery = Item::with('category:id,is_job_category', 'translations', 'featured_items', 'currency', 'gallery_images:id,image,item_id,is_default')
                ->where('status', 'approved')
                ->getNonExpiredItems()
                // Items don't own module_id — never whereHas('category', …),
                // that's a correlated EXISTS per row. Filter by the module's
                // (cached) category id list instead.
                ->when($this->currentModuleId(), function ($query) {
                    $query->whereIn('category_id', ModuleService::categoryIds($this->currentModuleId()));
                })
                ->when($request->user_id, function ($query) use ($request) {
                    $query->where('user_id', $request->user_id);
                })
                ->when($request->excluded_item_id, function($query) use($request){
                    $query->where('id', '!=', $request->excluded_item_id);
                })
                ->when($request->search, function($query) use($request){
                    $query->search($request->search);
                })
                ->when($request->min_price, function ($query) use ($request) {
                    $query->where('price', '>=', $request->min_price);
                })
                ->when($request->max_price, function ($query) use ($request) {
                    $query->where('price', '<=', $request->max_price);
                })
                ->when($request->country && $request->current_page != 'home', function ($query) use ($request) {
                    $query->where('country', $request->country);
                })
                ->when($request->state && $request->current_page != 'home', function ($query) use ($request) {
                    $query->where('state', $request->state);
                })
                ->when($request->city && $request->current_page != 'home', function ($query) use ($request) {
                    $query->where('city', $request->city);
                })
                ->when($request->area && $request->current_page != 'home', function ($query) use ($request) {
                    $query->whereIn('area_id', Area::matchingName($request->area, $request->city, $request->state)->select('id'));
                })
                ->when($request->category_slug, function ($query) use ($request) {
                    $category = Category::where('slug', Str::lower($request->category_slug))->first();
                    $categoryIDS = $category ? $category->descendantsAndSelf()->pluck('id')->toArray() : [];

                    return $query->whereIn('category_id', $categoryIDS);
                })
                ->when($request->posted_since, function($query) use($request){
                    $this->itemListPostedSinceFilter($query,$request->posted_since);
                })
                ->when($request->custom_fields, function ($query) use ($request) {
                    $customFields = json_decode($request->custom_fields, true) ?? [];
                    $query->where(function ($outer) use ($customFields) {
                        foreach ($customFields as $fieldId => $value) {
                            $values = is_array($value) ? $value : [$value];
                            $outer->orWhereHas('item_custom_field_values', function ($q) use ($fieldId, $values) {
                                $q->where('custom_field_id', $fieldId);
                                foreach ($values as $v) {
                                    $q->whereLike('value', '%"' . $v . '"%');
                                }
                            });
                        }
                    });
                })
                ->when($request->category_id, function ($sql) use ($request) {
                    $category = Category::find($request->category_id);
                    $categoryIDS = $category ? $category->descendantsAndSelf()->pluck('id')->toArray() : [$request->category_id];

                    return $sql->whereIn('category_id', $categoryIDS);
                });

            [$baseQuery, $featureSectionSort] = $this->itemListFeatureSectionFilter($baseQuery, $request);

            $locationMessage = null;
            if (($request->latitude !== null && $request->longitude !== null) || $request->country || $request->state || $request->city || $request->area) {
                $locationResult = HelperService::applyLocationFilters($baseQuery, $request, fn ($query) => $query);
                $baseQuery = $locationResult['query'];
                $locationMessage = $locationResult['message'];
            }

            $applySort = function ($query, $adminSort, $seedCachePrefix) use ($request) {
                $query->reorder();
                if ($adminSort === 'latest') {
                    $query->orderByRaw('COALESCE(items.published_at, items.created_at) DESC');
                } elseif ($adminSort === 'oldest') {
                    $query->orderByRaw('COALESCE(items.published_at, items.created_at) ASC');
                } else {
                    $seed = HelperService::resolveRandomSeed($request, Auth::id(), $seedCachePrefix);
                    DbCompat::orderBySeededRandom($query, 'items.id', $seed);
                }

                return $query;
            };

            $weightage = (int) (CachingService::getSystemSettings('featured_items_weightage') ?: 10);

            $featuredPerPage = (int) round($limit * $weightage / 100);

            $featuredQuery = (clone $baseQuery)->whereHas('featured_items', fn ($q) => $q->onlyActive());
            $normalQuery = (clone $baseQuery)->whereDoesntHave('featured_items', fn ($q) => $q->onlyActive());

            if ($request->sort_by) {
                $this->itemListSortFilter($featuredQuery, $request->sort_by);
                $this->itemListSortFilter($normalQuery, $request->sort_by);
            } elseif ($featureSectionSort === 'clicks') {
                $featuredQuery->reorder()->orderBy('clicks', 'DESC')->orderByDesc('items.id');
                $normalQuery->reorder()->orderBy('clicks', 'DESC')->orderByDesc('items.id');
            } elseif ($featureSectionSort === 'favourites') {
                $featuredQuery->reorder()->withCount('favourites')->orderBy('favourites_count', 'DESC')->orderByDesc('items.id');
                $normalQuery->reorder()->withCount('favourites')->orderBy('favourites_count', 'DESC')->orderByDesc('items.id');
            } else {
                $adminFeaturedSort = CachingService::getSystemSettings('feature_item_sorting') ?: 'random';
                $adminGeneralSort = CachingService::getSystemSettings('general_item_sorting') ?: 'random';

                $featuredQuery = $applySort($featuredQuery, $adminFeaturedSort, 'items_featured_seed');
                $normalQuery = $applySort($normalQuery, $adminGeneralSort, 'items_general_seed');
            }

            $totalFeatured = (clone $featuredQuery)->count();
            $totalNormal = (clone $normalQuery)->count();
            $totalItems = $totalFeatured + $totalNormal;

            $featuredOffset = 0;
            $normalOffset = 0;
            for ($p = 1; $p < $page; $p++) {
                $fTake = min($featuredPerPage, max(0, $totalFeatured - $featuredOffset));
                $gTake = min($limit - $fTake, max(0, $totalNormal - $normalOffset));
                $fExtra = min($limit - $fTake - $gTake, max(0, $totalFeatured - $featuredOffset - $fTake));

                $featuredOffset += $fTake + $fExtra;
                $normalOffset += $gTake;
            }

            $featuredItems = (clone $featuredQuery)->skip($featuredOffset)->take($featuredPerPage)->get();

            $generalPerPage = max(0, $limit - $featuredItems->count());
            $normalItems = (clone $normalQuery)->skip($normalOffset)->take($generalPerPage)->get();

            $remaining = $limit - $featuredItems->count() - $normalItems->count();
            if ($remaining > 0 && $totalFeatured > $featuredOffset + $featuredItems->count()) {
                $featuredItems = $featuredItems->merge(
                    (clone $featuredQuery)->skip($featuredOffset + $featuredItems->count())->take($remaining)->get()
                );
            }

            $items = $featuredItems->merge($normalItems);

            $paginator = new LengthAwarePaginator(
                $items,
                $totalItems,
                $limit,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            $message = ! empty($locationMessage) ? $locationMessage : __('Advertisement Fetched Successfully');

            ResponseService::successResponse($message, new ItemApiResource($paginator), ['location_message' => $locationMessage]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getItemListLite');
            ResponseService::errorResponse();
        }
    }

    /**
     * Get Item Details by `id` or `slug`
     *
     * Returns the owner view (status, views, translations, custom fields, etc.) when the
     * authenticated user owns the item, otherwise the public detail view.
     */
    public function getItemDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            /** Required without slug */
            'id' => 'required_without:slug|nullable|integer',
            /** Required without id */
            'slug' => 'required_without:id|nullable|string',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $authId = Auth::guard('sanctum')->id();

            $relations = [
                'user:id,name,email,mobile,profile,country_code,show_personal_details,is_verified,created_at',
                'category:id,name,image,is_job_category,slug,module_id',
                'category.module:id,slug,name,description,icon,path_prefix,status,sequence,version,plugin_slug',
                'gallery_images:id,image,item_id,is_default',
                'featured_items',
                'favourites',
                'translations',
                'area',
                'currency',
                'seoDetail.translations',
                'itemVideo',
                'job_applications',
                'review',
                'item_custom_field_values.custom_field.translations',
            ];

            $filter = function ($query) use ($request) {
                $query->when($request->id, function ($query) use ($request) {
                    $query->where('id', $request->id);
                })->when($request->slug, function ($query) use ($request) {
                    $query->where('slug', Str::lower($request->slug));
                });
            };

            // Owner sees their own item in any state (inactive, expired, rejected, ...)
            $item = $authId
                ? Item::withTrashed()->with($relations)->where('user_id', $authId)->where($filter)->first()
                : null;

            if ($item) {
                $item->setRelation('item_offers', $item->item_offers()->get());
                $item->setRelation('user_reports', $item->user_reports()->get());

                ResponseService::successResponse(__('Advertisement Fetched Successfully'), (new ItemApiResource(collect([$item])))->asDetail()->asMyItem());

                return;
            }

            // Anyone else (guest or another user) only sees a live ad: approved and not expired.
            $item = Item::with($relations)->where($filter)->where('status', 'approved')->getNonExpiredItems()->first();

            if (! $item) {
                ResponseService::errorResponse(__('No item Found'));
            }

            Item::withoutTimestamps(function () use ($item) {
                Item::where('id', $item->id)->increment('clicks');
            });
            $item->clicks++;

            if ($authId) {
                $item->setRelation('item_offers', $item->item_offers()->where('buyer_id', $authId)->get());
                $item->setRelation('user_reports', $item->user_reports()->where('user_id', $authId)->get());
            }

            ResponseService::successResponse(__('Advertisement Fetched Successfully'), (new ItemApiResource(collect([$item])))->asDetail());
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getItemDetails');
            ResponseService::errorResponse();
        }
    }

    /**
     * Get My Items (lightweight) / Get My Item Detail when `id` or `slug` passed
     */
    public function getMyItems(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'nullable|integer',
            'slug' => 'nullable|string',
            'limit' => 'nullable|integer',
            'page' => 'nullable|integer',
            /** status values are review, approved, rejected, sold out, soft rejected, permanent rejected, resubmitted, featured, inactive */
            'status' => 'nullable|string',
            'sort_by' => 'nullable|in:popular_items,new-to-old,old-to-new,price-high-to-low,price-low-to-high',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $user = Auth::user();

            if ($request->has('id') || $request->has('slug')) {
                $item = Item::withTrashed()->with(
                    'user:id,name,email,mobile,profile,country_code,created_at',
                    'category:id,name,image,is_job_category,module_id',
                    'category.module:id,slug,name,description,icon,path_prefix,status,sequence,version,plugin_slug',
                    'gallery_images:id,image,item_id,is_default',
                    'featured_items',
                    'favourites',
                    'translations',
                    'area',
                    'currency',
                    'seoDetail.translations',
                    'itemVideo',
                    'job_applications',
                    'review',
                    'item_custom_field_values.custom_field.translations'
                )->where('user_id', $user->id)
                ->when($request->id, function ($query) use ($request) {
                    $query->where('id', $request->id);
                })
                ->when($request->slug, function ($query) use ($request) {
                    $query->where('slug', Str::lower($request->slug));
                })
                ->first();

                if (! $item) {
                    ResponseService::errorResponse(__('No item Found'));
                }

                $item->setRelation('item_offers', $item->item_offers()->get());
                $item->setRelation('user_reports', $item->user_reports()->get());

                ResponseService::successResponse(__('Advertisement Fetched Successfully'), (new ItemApiResource(collect([$item])))->asDetail()->asMyItem());

                return;
            }

            DB::enableQueryLog();
            $sql = Item::withTrashed()->with('category:id,is_job_category', 'translations', 'featured_items', 'currency', 'gallery_images:id,image,item_id,is_default')
                // Not module-scoped: a user's own ads list spans every module.
                ->where('user_id', $user->id)
                ->when($request->status, function ($sql) use ($request) {
                    if (in_array($request->status, ['review', 'approved', 'rejected', 'soft rejected', 'permanent rejected', 'resubmitted'])) {
                        $sql->where('status', $request->status)->getNonExpiredItems()->whereNull('deleted_at');
                    } elseif ($request->status == 'inactive') {
                        // If status is inactive then display only trashed items
                        $sql->onlyTrashed()->getNonExpiredItems();
                    } elseif ($request->status == 'featured') {
                        // If status is featured then display only featured items
                        $sql->where('status', 'approved')->has('featured_items')->getNonExpiredItems();
                    } elseif ($request->status == 'expired') {
                        $sql->whereNotNull('expiry_date')
                            ->where('expiry_date', '<', Carbon::now())->whereNull('deleted_at')
                            ->where('status', '!=', 'sold out');
                    } elseif($request->status == 'sold out') {
                        $sql->where('status', 'sold out');
                    }
                });

            if ($request->sort_by) {
                $this->itemListSortFilter($sql, $request->sort_by);
            } else {
                $sql->orderByRaw('COALESCE(updated_at, created_at) DESC');
            }

            $result = $sql->paginate($request->limit ?? 10);

            ResponseService::successResponse(__('Advertisement Fetched Successfully'), (new ItemApiResource($result))->asMyItem());
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getMyItems');
            ResponseService::errorResponse();
        }
    }

    /**
     * Get Purchased Items
     */
    public function getPurchasedItemsForReview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit'       => 'nullable|integer',
            'page'        => 'nullable|integer',
            'search'      => 'nullable|string',
            'is_reviewed' => 'nullable|in:0,1,true,false',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $user = Auth::user();
            $limit = (int) ($request->limit ?? 10);

            $query = Item::with([
                'category:id,name,image,is_job_category,module_id',
                'category.module:id,slug,name,description,icon,path_prefix,status,sequence,version,plugin_slug',
                'translations',
                'featured_items',
                'currency',
                'gallery_images:id,image,item_id,is_default',
                'user:id,name,email,mobile,profile,country_code',
                'review' => function ($q) use ($user) {
                    $q->where('buyer_id', $user->id);
                }
            ])
            ->where('sold_to', $user->id)
            ->where('status', 'sold out')
            ->where('user_id', '!=', $user->id);

            if ($request->filled('search')) {
                $query->search($request->search);
            }

            if ($request->has('is_reviewed')) {
                $isReviewed = filter_var($request->is_reviewed, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isReviewed === true || $request->is_reviewed == '1') {
                    $query->whereHas('review', function ($q) use ($user) {
                        $q->where('buyer_id', $user->id);
                    });
                } elseif ($isReviewed === false || $request->is_reviewed == '0') {
                    $query->whereDoesntHave('review', function ($q) use ($user) {
                        $q->where('buyer_id', $user->id);
                    });
                }
            }

            $items = $query->orderByRaw('COALESCE(items.published_at, items.created_at) DESC')->paginate($limit);

            ResponseService::successResponse(__('Purchased items fetched successfully'), (new ItemApiResource($items))->asMyPurchased());
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemApiController -> getPurchasedItemsForReview');
            ResponseService::errorResponse();
        }
    }

    /**
     * Update Item
     */
    public function updateItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required',
            'name' => 'nullable',
            'slug' => [
                'nullable',
                'regex:/^(?!-)(?!.*--)(?!.*-$)(?!-$)[a-z0-9-]+$/',
            ],
            'price' => 'nullable|numeric|min:0',
            'min_salary' => 'nullable|numeric|min:0',
            'max_salary' => 'nullable|numeric|min:0',
            'pricing_mode' => 'nullable|string',
            'category_id' => 'nullable|integer|exists:categories,id',
            'item_type' => 'nullable|in:normal,reel',
            'description' => 'nullable',
            'latitude' => 'nullable',
            'longitude' => 'nullable',
            'address' => 'nullable',
            /** Area name; matched to the `areas` table within the item's city and saved as `area_id` */
            'area' => 'nullable|string|max:255|exists:areas,name',
            'contact' => 'nullable',
            'custom_fields' => 'nullable',
            'custom_field_files' => 'nullable|array',
            'custom_field_files.*' => 'nullable|mimes:jpeg,png,jpg,pdf,doc|max:7168',
            'gallery_images' => 'nullable|array',
            'gallery_images.*' => 'mimes:jpeg,png,jpg|max:7168',
            'delete_item_image_id' => 'nullable|array',
            'currency_id'          => 'nullable|exists:currencies,id',
            'country_code'         => 'nullable|string',
            'video_type'           => 'nullable|in:youtube_link,vimeo_link,other_link,file',
            'video_link'           => [
                'required_if:video_type,youtube_link,vimeo_link,other_link',
                'nullable',
                'url',
                new VideoLink($request->input('video_type')),
            ],
            'seo_details'          => 'nullable|array',
            'seo_details.*'     => 'nullable|array',
            'seo_details.*.meta_title' => 'nullable|string',
            'seo_details.*.meta_description' => 'nullable|string',
            'seo_details.*.meta_keywords' => 'nullable|string',
            'seo_details.*.schema' => 'nullable|string',
            'delete_product_video' => 'nullable|in:1'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        DB::beginTransaction();

        try {

            $item = Item::owner()->with('itemVideo')->findOrFail($request->id);

            // category_id may change on update — the new category's module
            // decides the rules.
            $moduleId = ModuleService::moduleIdForCategory($request->input('category_id') ?: $item->category_id);
            $moduleSettings = ModuleService::settings($moduleId);

            $moduleChanged = $moduleId !== ModuleService::moduleIdForCategory($item->category_id);
            $moduleError = ItemModuleValidator::moduleMismatchError($moduleId)
                ?? ItemModuleValidator::videoTypeError($moduleSettings, ItemModuleValidator::submittedVideoType($request));

            if ($moduleError) {
                DB::rollBack();
                ResponseService::validationError($moduleError);
            }

            // Module-aware clients (X-Module) are checked against the
            // module's pricing modes; old clients keep pricing_mode as is.
            $pricingAttributes = [];
            if ($this->currentModuleId()) {
                [$pricingError, $pricingAttributes] = ItemModuleValidator::pricing($moduleSettings, $request, $item, $moduleChanged);

                if ($pricingError) {
                    DB::rollBack();
                    ResponseService::validationError($pricingError);
                }
            } elseif ($request->hasAny(['price', 'min_salary', 'max_salary'])) {
                // Old clients: keep pricing_mode in step with what they sent
                // (an empty price clears it -> price_on_request).
                $pricingAttributes = ['pricing_mode' => ItemModuleValidator::legacyPricingMode($request)];
            }

            // Only ids that really belong to this item count (and get deleted).
            $deleteImageIds = ItemImages::where('item_id', $item->id)
                ->whereIn('id', (array) $request->input('delete_item_image_id', []))
                ->pluck('id');
            $existingImagesCount = ItemImages::where('item_id', $item->id)->count();
            $newImageCount = $request->hasFile('gallery_images') ? count($request->file('gallery_images')) : 0;
            $remainingImages = $existingImagesCount - $deleteImageIds->count() + $newImageCount;

            $imageError = ItemModuleValidator::galleryLimitError($moduleSettings, $remainingImages)
                ?? ($remainingImages < 1 ? __('At least one item image is required') : null);
            if ($imageError) {
                DB::rollBack();
                ResponseService::validationError($imageError);
            }

            $auto_approve_item = Setting::where('name', 'auto_approve_edited_item')->value('value') ?? 0;
            if ($auto_approve_item == 1) {
                $status = 'approved';
            } else if ($item->status == 'soft rejected'){
                $status = 'resubmitted';
            } else {
                $status = 'review';
            }
            $slugInput = $request->input('slug') ?? '';
            $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower(trim($slugInput)));
            $slug = trim($slug, '-');

            // If slug is empty after cleaning, use existing item slug
            if (empty($slug)) {
                $slug = $item->slug;
            }

            // Generate unique slug
            $uniqueSlug = HelperService::generateUniqueSlug(new Item, $slug, $request->id);

            $data = $request->all();
            unset($data['pricing_mode']);
            // `area` is a name, matched to an area of the item's (possibly new) city; sent
            // empty clears it. A raw `area_id` is ignored.
            unset($data['area_id']);
            if ($request->has('area')) {
                $data['area_id'] = $this->areaIdFromName($request->input('area'), $request->input('city', $item->city), $request->input('state', $item->state));
            }
            // Nothing price-related to change: keep the stored price columns
            // (a raw empty "price" must not overwrite them). When pricing()
            // returned attributes they hold all four columns and win below;
            // the legacy branch only sets pricing_mode and keeps the raw
            // (now numeric-validated) price fields.
            if (empty($pricingAttributes)) {
                unset($data['price'], $data['min_salary'], $data['max_salary']);
            }
            $data = [...$data, ...$pricingAttributes];
            $data['slug']   = $uniqueSlug;
            $data['status'] = $status;
            if ($status === 'approved' && is_null($item->published_at)) {
                $data['published_at'] = Carbon::now();
            }

            // Process images before updating item
            $galleryImages = [];

            $itemImagesExists = ItemImages::where('item_id', $item->id)->exists();
            if ($request->hasFile('gallery_images')) {
                $files = $request->file('gallery_images');
                if (!empty($files)) {
                    foreach ($files as $index => $file) {
                        $galleryImages[] = [
                            'image' => FileService::compressAndUpload($file, $this->uploadFolder, true),
                            'is_default' => (!$itemImagesExists && $index === 0) ? 1 : 0,
                            'item_id' => $item->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
            }
            $item->update($data);

            // Handle item video update
            $this->syncItemVideo($request, $item);

            if (!empty($galleryImages)) {
                ItemImages::insert($galleryImages);
            }
            // Update or create item translations
            $translations = json_decode($request->input('translations', '{}'), true, 512, JSON_THROW_ON_ERROR);
            if (! empty($translations)) {
                foreach ($translations as $languageId => $translationData) {
                    if (Language::where('id', $languageId)->exists()) {
                        HelperService::storeTranslations([
                            ['translatable_id' => $item->id, 'translatable_type' => \App\Models\Item::class, 'key' => 'name', 'value' => $translationData['name'], 'language_id' => $languageId],
                            ['translatable_id' => $item->id, 'translatable_type' => \App\Models\Item::class, 'key' => 'description', 'value' => $translationData['description'] ?? '', 'language_id' => $languageId],
                            ['translatable_id' => $item->id, 'translatable_type' => \App\Models\Item::class, 'key' => 'address', 'value' => $translationData['address'] ?? '', 'language_id' => $languageId],
                            ['translatable_id' => $item->id, 'translatable_type' => \App\Models\Item::class, 'key' => 'rejected_reason', 'value' => $translationData['rejected_reason'] ?? null, 'language_id' => $languageId],
                            ['translatable_id' => $item->id, 'translatable_type' => \App\Models\Item::class, 'key' => 'admin_edit_reason', 'value' => $translationData['admin_edit_reason'] ?? null, 'language_id' => $languageId],
                        ]);
                    }
                }
            }

            // Update Custom Field values for item
            if ($request->custom_fields) {
                $defaultLanguageId = HelperService::getDefaultLanguageId();

                // Backfill legacy rows left NULL by older code before upserting on the language_id-aware key
                ItemCustomFieldValue::where('item_id', $item->id)->whereNull('language_id')->update(['language_id' => $defaultLanguageId]);

                $itemCustomFieldValues = [];
                foreach (json_decode($request->custom_fields, true, 512, JSON_THROW_ON_ERROR) as $key => $custom_field) {
                    $itemCustomFieldValues[] = [
                        'item_id' => $item->id,
                        'custom_field_id' => $key,
                        'language_id' => $defaultLanguageId,
                        'value' => json_encode($custom_field, JSON_THROW_ON_ERROR),
                        'updated_at' => time(),
                    ];
                }

                if (count($itemCustomFieldValues) > 0) {
                    ItemCustomFieldValue::upsert($itemCustomFieldValues, ['item_id', 'custom_field_id', 'language_id'], ['value', 'updated_at']);
                }
            }

            if ($request->custom_field_files) {
                foreach ($request->custom_field_files as $key => $file) {
                    $value = ItemCustomFieldValue::where(['item_id' => $item->id, 'custom_field_id' => $key])->first();
                    if (! empty($value)) {
                        $existingPath = $this->extractFilePath($value->getRawOriginal('value'));
                        $path = !empty($existingPath) ? FileService::replace($file, 'custom_fields_files', $existingPath) : FileService::upload($file, 'custom_fields_files');
                    } else {
                        $path = FileService::upload($file, 'custom_fields_files');
                    }

                    // Store as single plain path
                    ItemCustomFieldValue::updateOrCreate(
                        ['item_id' => $item->id, 'custom_field_id' => $key],
                        ['value' => $path, 'language_id' => HelperService::getDefaultLanguageId(), 'updated_at' => time()]
                    );
                }
            }
            // Update or insert custom field translations
            if ($request->has('custom_field_translations')) {
                $customFieldTranslations = $request->input('custom_field_translations');

                if (! is_array($customFieldTranslations)) {
                    $customFieldTranslations = html_entity_decode($customFieldTranslations);
                    $customFieldTranslations = json_decode($customFieldTranslations, true, 512, JSON_THROW_ON_ERROR);
                }
                $translatedEntries = [];

                foreach ($customFieldTranslations as $languageId => $fieldsByCustomField) {
                    foreach ($fieldsByCustomField as $customFieldId => $translatedValue) {
                        $translatedEntries[] = [
                            'item_id' => $item->id,
                            'custom_field_id' => $customFieldId,
                            'language_id' => $languageId,
                            'value' => json_encode($translatedValue, JSON_THROW_ON_ERROR),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ];
                    }
                }

                if (! empty($translatedEntries)) {
                    // Ensure combination is unique
                    ItemCustomFieldValue::upsert(
                        $translatedEntries,
                        ['item_id', 'custom_field_id', 'language_id'], // unique keys
                        ['value', 'updated_at']
                    );
                }
            }

            // Delete gallery images
            // Scoped to this item: ids of another item's images are ignored.
            // The at-least-one / limit checks ran above, before any write.
            if ($deleteImageIds->isNotEmpty()) {
                $deletedDefault = false;
                $itemImages = ItemImages::where('item_id', $item->id)->whereIn('id', $deleteImageIds)->get();
                foreach ($itemImages as $itemImage) {
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

            // Store SEO details from API
            if ($request->has('seo_details')) {
                $seoData = $request->seo_details;
                if (!empty($seoData)) {
                    HelperService::storeSeoDetailsFromApi($item, $seoData);
                }
            }

            $result = Item::with('user:id,name,email,mobile,profile,country_code,created_at', 'category:id,name,image,is_job_category,module_id', 'category.module:id,slug,name,description,icon,path_prefix,status,sequence,version,plugin_slug', 'gallery_images:id,image,item_id,is_default', 'featured_items', 'favourites', 'item_custom_field_values.custom_field.translations', 'area', 'translations', 'seoDetail.translations')->where('items.id', $item->id)->get();
            /*
               * Collection does not support first OR find method's result as of now. It's a part of R&D
               * So currently using this shortcut method
              */
            $result = (new ItemApiResource($result))->asDetail()->asMyItem();

            DB::commit();
            ResponseService::successResponse(__('Advertisement Fetched Successfully'), $result);
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th, 'API Controller -> updateItem');
            ResponseService::errorResponse();
        }
    }

    /**
     * Delete Item
     */
    public function deleteItem(Request $request)
    {
        try {
            // Validation rules
            $rules = [
                'item_id' => 'nullable|exists:items,id',
                'item_ids' => 'nullable|string', // comma-separated IDs
            ];

            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            // Normalize IDs
            $itemIds = [];

            if ($request->filled('item_id')) {
                $itemIds[] = $request->item_id;
            }

            if ($request->filled('item_ids')) {
                $ids = explode(',', $request->item_ids);
                $ids = array_map('trim', $ids);
                $ids = array_filter($ids, 'strlen');
                $itemIds = array_merge($itemIds, $ids);
            }

            if (empty($itemIds)) {
                return ResponseService::validationError(__('Please provide item_id or item_ids'));
            }

            $results = [];

            foreach ($itemIds as $id) {
                try {
                    $item = Item::owner()->with('gallery_images')->withTrashed()->findOrFail($id);

                    // Delete main image
                    FileService::delete($item->getRawOriginal('image'));

                    // Delete gallery images
                    if ($item->gallery_images->count() > 0) {
                        foreach ($item->gallery_images as $gallery) {
                            FileService::delete($gallery->getRawOriginal('image'));
                        }
                    }

                    // Delete item
                    $item->forceDelete();

                    $results[] = [
                        'status' => 'success',
                        'message' => __('Advertisement Deleted Successfully'),
                        'item_id' => $id,
                    ];
                } catch (Throwable $e) {
                    $results[] = [
                        'status' => 'failed',
                        'message' => __('Failed to delete item'),
                        'item_id' => $id,
                    ];
                }
            }

            // Single item response
            if (count($results) === 1) {
                if ($results[0]['status'] === 'success') {
                    return ResponseService::successResponse(
                        __('Advertisement Deleted Successfully'),
                        ['id' => $results[0]['item_id']]
                    );
                } else {
                    return ResponseService::errorResponse($results[0]['message']);
                }
            }

            // Multiple items response
            return ResponseService::successResponse(__('Items processed successfully'), $results);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> deleteItem');

            return ResponseService::errorResponse();
        }
    }

    /**
     * Update Item Status
     */
    public function updateItemStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_id' => 'required|integer',
            'status' => 'required|in:sold out,inactive,active,resubmitted',
            'sold_to' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $item = Item::owner()->whereNotIn('status', ['review', 'permanent rejected'])->withTrashed()->findOrFail($request->item_id);
            if ($item->status == 'permanent rejected' && $request->status == 'resubmitted') {
                ResponseService::errorResponse(__('This Advertisement is permanently rejected and cannot be resubmitted'));
            }
            if ($request->status == 'inactive') {
                $item->delete();
            } elseif ($request->status == 'active') {
                $item->restore();
            } elseif ($request->status == 'sold out') {
                if($request->sold_to){
                    $userExists = User::where('id',$request->sold_to)->exists();
                    if (!$userExists) {
                        ResponseService::errorResponse(__('Invalid User'));
                    }
                }
                $item->update([
                    'status' => 'sold out',
                    'sold_to' => $request->sold_to,
                ]);
            } else {
                $item->update(['status' => $request->status]);
            }
            ResponseService::successResponse(__('Advertisement Status Updated Successfully'));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemController -> updateItemStatus');
            ResponseService::errorResponse(__('Something Went Wrong'));
        }
    }

    /**
     * Get Item Buyer List
     */
    public function getItemBuyerList(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            // Verify the authenticated user owns this item
            Item::owner()->findOrFail($request->item_id);

            $buyer_ids = ItemOffer::where('item_id', $request->item_id)->select('buyer_id')->pluck('buyer_id');
            $users = User::select(['id', 'name', 'profile'])->whereIn('id', $buyer_ids)->get();
            ResponseService::successResponse(__('Buyer List fetched Successfully'), $users);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemController -> updateItemStatus');
            ResponseService::errorResponse(__('Something Went Wrong'));
        }
    }

    /**
     * Renew Item
     */
    public function renewItem(Request $request)
    {
        try {
            DB::beginTransaction();
            $freeAdListingStatus = Setting::where('name', 'free_ad_listing')->value('value') ?? 0;
            // A module with Ad Packages switched off renews freely, same as free_ad_listing
            if ($request->filled('item_id')
                && ! ModuleService::setting(ModuleService::moduleIdForItem((int) $request->item_id), 'ad_packages_enabled')) {
                $freeAdListingStatus = 1;
            }

            // Validation rules
            $rules = [
                'item_id' => 'nullable|exists:items,id',
            ];
            if ($freeAdListingStatus == 0) {
                $rules['package_id'] = 'required|exists:packages,id';
            } else {
                $rules['package_id'] = 'nullable|exists:packages,id';
            }
            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            $package = null;
            $userPackage = null;

            // Fetch package if provided
            if ($request->filled('package_id')) {
                $package = Package::where('id', $request->package_id)->firstOrFail();

                $userPackage = UserPurchasedPackage::onlyActive()->where([
                    'user_id' => $user->id,
                    'package_id' => $package->id,
                ])->first();
                if (!$userPackage) {
                    return ResponseService::errorResponse(__('You have not purchased this package'));
                }
            }

            $currentDate = Carbon::now();
            $results = [];

            $item = Item::owner()->find($request->item_id);
            if (!$item) {
                return ResponseService::errorResponse(__('Advertisement not found'));
            }
            if (Carbon::parse($item->expiry_date)->gt($currentDate)) {
                ResponseService::validationError(__('Advertisement has not expired yet, so it cannot be renewed'));
            }
            if($item->getRawOriginal('status') == 'sold out'){
                ResponseService::validationError(__('Advertisement is sold out, so it cannot be renewed'));
            }
            if ($package) {
                // Calculate expiry date based on package listing duration
                $expiryDate = HelperService::calculateItemExpiryDate($package, $userPackage);
                $userPackage->used_limit++;
                $userPackage->save();
            } else {
                // No package - use standard 30 days
                $expiryDate = HelperService::calculateItemExpiryDate(null, null);
            }

            if($item->getRawOriginal('status') == 'approved'){
                $item->published_at = $currentDate;
            }
            $item->expiry_date = $expiryDate;
            $item->renewed_at = $currentDate;
            $item->save();
            DB::commit();
            ResponseService::successResponse(__('Advertisement renewed successfully'), $item);
        } catch (Throwable $th) {
            DB::rollback();
            ResponseService::logErrorResponse($th, 'API Controller -> renewItem');
            return ResponseService::errorResponse();
        }
    }

    /**
     * Make Item Featured
     */
    public function makeFeaturedItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            $item = Item::owner()->find($request->item_id);
            if (!$item) {
                return ResponseService::errorResponse(__('Advertisement not found'));
            }
            if($item->status != 'approved'){
                return ResponseService::errorResponse(__('Advertisement is not approved'));
            }
            $user = Auth::user();
            $user_package = UserPurchasedPackage::onlyActive()
                ->where(['user_id' => $user->id])
                ->with('package')
                ->whereHas('package', function ($q) {
                    $q->where(['type' => 'advertisement']);
                })
                ->first();

            if (! $user_package) {
                return ResponseService::errorResponse(__('You need to purchase a Featured Ad plan first.'));
            }
            $featuredItems = FeaturedItems::where(['item_id' => $request->item_id, 'package_id' => $user_package->package_id])->where('end_date', '>', date('Y-m-d'))->first();
            if (! empty($featuredItems)) {
                return ResponseService::errorResponse(__('Advertisement is already featured'));
            }

            $user_package->used_limit++;
            $user_package->save();

            FeaturedItems::create([
                'item_id' => $request->item_id,
                'package_id' => $user_package->package_id,
                'user_purchased_package_id' => $user_package->id,
                'start_date' => date('Y-m-d'),
                'end_date' => $user_package->end_date,
            ]);

            DB::commit();
            ResponseService::successResponse(__('Featured Advertisement Created Successfully'));
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th, 'API Controller -> createAdvertisement');
            ResponseService::errorResponse();
        }
    }

    /**
     * Manage Favourite
     */
    public function manageFavourite(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required',
            ]);
            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }
            $favouriteItem = Favourite::where('user_id', Auth::user()->id)->where('item_id', $request->item_id)->first();
            if (empty($favouriteItem)) {
                $favouriteItem = new Favourite;
                $favouriteItem->user_id = Auth::user()->id;
                $favouriteItem->item_id = $request->item_id;
                $favouriteItem->save();
                ResponseService::successResponse(__('Advertisement added to Favourite'));
            } else {
                $favouriteItem->delete();
                ResponseService::successResponse(__('Advertisement remove from Favourite'));
            }
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> manageFavourite');
            ResponseService::errorResponse();
        }
    }

    /**
     * Get Favourite Items
     */
    public function getFavouriteItem(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'page' => 'nullable|integer',
                'per_page' => 'nullable|integer|max:500',
            ]);
            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }
            $perPage = $request->per_page ?? 10;
            $favouriteItemIDS = Favourite::where('user_id', Auth::user()->id)->latest()->pluck('item_id');
            $items = Item::whereIn('id', $favouriteItemIDS)
                ->with('category:id,is_job_category', 'favourites', 'translations', 'featured_items', 'currency', 'gallery_images:id,image,item_id,is_default')->where('status', 'approved')->onlyNonBlockedUsers()->getNonExpiredItems()
                ->when($favouriteItemIDS->isNotEmpty(), function ($query) use ($favouriteItemIDS) {
                    DbCompat::orderByIdList($query, 'id', $favouriteItemIDS);
                })->paginate($perPage);

            ResponseService::successResponse(__('Data Fetched Successfully'), new ItemApiResource($items));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getFavouriteItem');
            ResponseService::errorResponse();
        }
    }

    /**
     * Get Item Slugs
     */
    public function getItemSlugs(Request $request)
    {
        try {
            $items = Item::without('translations')
                ->with('category:id,module_id', 'category.module:id,slug')
                ->select('id', 'slug', 'category_id', 'updated_at')
                ->where('status', 'approved')
                ->getNonExpiredItems()
                ->orderBy('id')
                ->paginate(500);

            // The item's module comes from its category.
            $items->through(fn (Item $item) => [
                'id' => $item->id,
                'slug' => $item->slug,
                'module_slug' => $item->category?->module?->slug,
                'updated_at' => $item->updated_at,
            ]);

            if ($items->isEmpty()) {
                return ResponseService::errorResponse(__('No active items found.'));
            }

            return ResponseService::successResponse(__('Active item slugs fetched successfully.'), $items);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getItemSlugs');

            return ResponseService::errorResponse();
        }
    }

    /**
     * Get Item Status
     */
    public function getItemStatus(Request $request){
        try{
            $validator = Validator::make($request->all(), [
                'item_id' => 'required|exists:items,id',
            ]);

            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }
            $item = Item::findOrFail($request->item_id);
            $data = array(
                'id' => $item->id,
                'name' => $item->name,
                'status' => $item->status,
            );
            ResponseService::successResponse(__('Data Fetched Successfully'), $data);
        } catch(Throwable $th){
            ResponseService::logErrorResponse($th, 'API Controller -> getItemStatus');
            ResponseService::errorResponse();
        }
    }

    /**
     * The id of the area called $name (its own or a translated name, any case) in the
     * given city (and state, when known); null when blank or not found.
     */
    private function areaIdFromName(?string $name, ?string $city, ?string $state): ?int
    {
        return Area::matchingName($name, $city, $state)->value('id');
    }

    private function syncItemVideo(Request $request, Item $item): void
    {
        $videoType = $request->input('video_type');

        // Mostly for Video file
        if ($request->delete_product_video == 1) {
            $itemData = $item->itemVideo()->first();
            if ($itemData) {
                $itemData->delete();
            }
        }

        if (!$videoType || !in_array($videoType, ['youtube_link', 'vimeo_link', 'other_link'])) {
            return;
        }

        if($request->input('video_link')){
            ItemVideo::updateOrCreate(
                ['item_id' => $item->id],
                ['video_type' => $videoType, 'video_link' => $request->input('video_link'), 'video_file' => null]
            );
        }else{
            return;
        }
    }

    /**
     * Upload media (reel video, product video, or thumbnail) for an item (auth)
     */
    public function uploadMedia(Request $request)
    {
        try {
            // Reel size/duration stay global-only (encoder constraints, not a
            // business rule) — only the product video limit is per-module.
            $maxSizeMb = (int) (CachingService::getSystemSettings('reel_max_file_size_mb') ?: 50);
            $maxSizeKb = $maxSizeMb * 1024;

            $mediaModuleId = ModuleService::moduleIdForItem($request->item_id);
            $mediaModuleSettings = ModuleService::settings($mediaModuleId);
            $itemVideoMaxSizeMb = (int) $mediaModuleSettings['video_max_file_size_mb'];
            $itemVideoMaxSizeKb = $itemVideoMaxSizeMb * 1024;

            $validator = Validator::make($request->all(), [
                'item_id'       => 'required|integer|exists:items,id',
                'video'         => "nullable|mimes:mp4,m4v|max:{$maxSizeKb}",
                'thumbnail'     => 'nullable|mimes:jpeg,jpg,png|max:7168',
                'product_video' => "nullable|mimes:mp4|max:{$itemVideoMaxSizeKb}",
            ]);

            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }

            if (!$request->hasFile('video') && !$request->hasFile('product_video')) {
                ResponseService::errorResponse(__('Please provide at least one media file (video or product_video).'));
            }

            $moduleError = ItemModuleValidator::moduleMismatchError($mediaModuleId)
                ?? ($request->hasFile('product_video') ? ItemModuleValidator::videoTypeError($mediaModuleSettings, 'file') : null);

            if ($moduleError) {
                ResponseService::validationError($moduleError);
            }

            $itemQuery = Item::where(['id' => $request->item_id, 'user_id' => Auth::id()])->select('id','item_type');
            $reelTypeItem = $itemQuery->clone()->where('item_type', 'reel')->with('reel')->first();

            // Package check applies when uploading reel video
            if ($request->hasFile('video')) {
                if(collect($reelTypeItem)->isEmpty()){
                    ResponseService::errorResponse(__('Item not found'));
                }

                $moduleId = ModuleService::moduleIdForItem($request->item_id);

                $freeAdListing = CachingService::getSystemSettings('free_ad_listing');
                if (!$freeAdListing && ModuleService::setting($moduleId, 'ad_packages_enabled')) {
                    $hasReelPackage = UserPurchasedPackage::where('user_id', Auth::id())
                        ->whereDate('start_date', '<=', date('Y-m-d'))
                        ->where(function ($q) {
                            $q->whereDate('end_date', '>', date('Y-m-d'))->orWhereNull('end_date');
                        })
                        ->whereHas('package', fn ($q) => $q->where('type', 'item_listing')->where('is_reel_allowed', 1)->forModule($moduleId))
                        ->exists();

                    if (!$hasReelPackage) {
                        ResponseService::errorResponse(__('Your current package does not allow reel uploads.'));
                    }
                }
            }

            $reel = $reelTypeItem ? $reelTypeItem->reel : null;
            $reelData = [];

            // Handle reel video — replace if exists
            if ($request->hasFile('video')) {
                // If thumbnail is not in request along with video then show error
                if(!$request->hasFile('thumbnail')){
                    ResponseService::errorResponse(__('Please upload a thumbnail along with the reel video.'));
                }
                if ($reel && $reel->getRawOriginal('video')) {
                    FileService::delete($reel->getRawOriginal('video'));
                }
                $reelData['video'] = FileService::upload($request->file('video'), 'reels');
            }

            // Handle thumbnail — replace if exists
            if ($request->hasFile('thumbnail')) {
                if ($reel && $reel->getRawOriginal('thumbnail')) {
                    FileService::delete($reel->getRawOriginal('thumbnail'));
                }
                $reelData['thumbnail'] = FileService::compressAndUpload($request->file('thumbnail'), 'reel_thumbnails');
            }

            if (!empty($reelData)) {
                if ($reel) {
                    $reel->update($reelData);
                } else {
                    $reel = Reel::create(array_merge(['item_id' => $reelTypeItem->id], $reelData));
                }
            }

            // Normal Item Query
            $normalItem = $itemQuery->clone()->with('itemVideo')->first();
            // Handle product video — stored in item_videos table, replace file if exists
            if ($request->hasFile('product_video')) {
                if(collect($normalItem)->isEmpty()){
                    ResponseService::errorResponse(__('Item not found'));
                }
                $existingItemVideo = $normalItem->itemVideo;
                if ($existingItemVideo && $existingItemVideo->getRawOriginal('video_file')) {
                    FileService::delete($existingItemVideo->getRawOriginal('video_file'));
                }
                ItemVideo::updateOrCreate(
                    ['item_id' => $normalItem->id],
                    [
                        'video_type' => 'file',
                        'video_link' => null,
                        'video_file' => FileService::upload($request->file('product_video'), 'item_videos'),
                    ]
                );
            }

            ResponseService::successResponse(__('Media Uploaded Successfully'), $reel);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemApiController -> uploadMedia');
            ResponseService::errorResponse();
        }
    }

    /**
     * Apply a FeatureSection's own filter/sort to the item list query when feature_section_id is passed.
     * @return array{0: \Illuminate\Database\Eloquent\Builder, 1: ?string}
     */
    private function itemListFeatureSectionFilter($baseQuery, Request $request): array
    {

        if($request->has('featured_section_id') && !empty($request->featured_section_id)){
            $where = ['id' => $request->featured_section_id];
        }else if ($request->has('featured_section_slug') && !empty($request->featured_section_slug)){
            $where = ['slug' => $request->featured_section_slug];
        }else {
            return [$baseQuery, null];
        }

        $featuredSection = FeatureSection::where($where)->first();
        if (! $featuredSection) {
            return [$baseQuery, null];
        }

        $hasUserPriceFilter = $request->min_price || $request->max_price;
        $hasUserCategoryFilter = (bool) $request->category_id;
        $hasUserSortFilter = (bool) $request->sort_by;

        $baseQuery = match ($featuredSection->filter) {
            'price_criteria' => $hasUserPriceFilter
                ? $baseQuery
                : $baseQuery->where(function ($query) use ($featuredSection) {
                    $query->whereBetween('price', [$featuredSection->min_price, $featuredSection->max_price])
                        ->orWhere(function ($q) use ($featuredSection) {
                            $q->whereBetween('min_salary', [$featuredSection->min_price, $featuredSection->max_price])
                                ->whereBetween('max_salary', [$featuredSection->min_price, $featuredSection->max_price]);
                        });
                }),
            'category_criteria' => $hasUserCategoryFilter
                ? $baseQuery
                : (function () use ($featuredSection, $baseQuery) {
                    $rootIds = array_filter(explode(',', $featuredSection->value ?? ''));
                    $categoryIDS = Category::whereIn('id', $rootIds)->get()
                        ->flatMap(fn ($c) => $c->descendantsAndSelf()->pluck('id'))
                        ->unique()->values()->toArray();

                    return $baseQuery->whereIn('category_id', $categoryIDS);
                })(),
            'featured_ads' => $baseQuery->whereHas('featured_items', fn ($q) => $q->onlyActive()),
            'item_selection' => $baseQuery->whereIn('items.id', DbCompat::idList($featuredSection->value ?? '')),
            default => $baseQuery,
        };

        // Promotion Package Tier (Most Viewed / Most Liked): featured or standard listings only.
        if ($featuredSection->promotion_tier === 'featured') {
            $baseQuery->whereHas('featured_items', fn ($q) => $q->onlyActive());
        } elseif ($featuredSection->promotion_tier === 'standard') {
            $baseQuery->whereDoesntHave('featured_items', fn ($q) => $q->onlyActive());
        }

        // Category Filter Scope of the other rules (ids in `value`), unless the user picked a category.
        if (! in_array($featuredSection->filter, ['category_criteria', 'item_selection']) && filled($featuredSection->value) && ! $hasUserCategoryFilter) {
            $scopeIds = Category::whereIn('id', explode(',', $featuredSection->value))->get()
                ->flatMap(fn ($c) => $c->descendantsAndSelf()->pluck('id'))->unique()->values()->toArray();
            $baseQuery->whereIn('category_id', $scopeIds);
        }

        $featureSectionSort = null;
        if (! $hasUserSortFilter) {
            $featureSectionSort = match ($featuredSection->filter) {
                'most_viewed' => 'clicks',
                'most_liked' => 'favourites',
                default => null,
            };
        }

        return [$baseQuery, $featureSectionSort];
    }

    private function itemListSortFilter($query,$sortBy){

        switch ($sortBy) {
            case 'popular_items':
                $query->withCount('favourites')->orderByDesc('favourites_count')->orderByDesc('items.id');
                break;
            case 'new-to-old':
                $query->orderByRaw('COALESCE(published_at, created_at) desc');
                break;
            case 'old-to-new':
                $query->orderByRaw('COALESCE(published_at, created_at) asc');
                break;
            case 'price-high-to-low':
                $query->orderBy('price', 'desc')->orderByDesc('items.id');
                break;
            case 'price-low-to-high':
                $query->orderBy('price', 'asc')->orderBy('items.id');
                break;
            default:
                $query->orderBy('created_at', 'desc')->orderByDesc('items.id');
                break;
        }
    }

    private function itemListPostedSinceFilter($query, $postedSince){
        $now = Carbon::now();
        switch ($postedSince) {
            case 'all-time':
                break;
            case 'today':
                $query->whereDate('published_at', $now->toDate());
                break;
            case 'within-1-week':
                $query->whereDate('published_at', '>=', $now->subWeek()->toDate());
                break;
            case 'within-2-week':
                $query->whereDate('published_at', '>=', $now->subWeeks(2)->toDate());
                break;
            case 'within-1-month':
                $query->whereDate('published_at', '>=', $now->subMonth()->toDate());
                break;
            case 'within-3-month':
                $query->whereDate('published_at', '>=', $now->subMonths(3)->toDate());
                break;
            default:
                break;
        }
    }
}
