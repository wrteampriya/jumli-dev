<?php

namespace App\Http\Controllers\Api;

use App\Support\DbCompat;
use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use App\Models\Language;
use App\Models\Setting;
use App\Models\State;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use App\Models\Item;
use App\Models\Category;
use App\Services\SeoTaxonomyService;
use App\Services\SeoRedirectService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Throwable;

/** @tags Location */
class LocationApiController extends BaseApiController
{
    /** Get Countries */
    public function getCountries(Request $request)
    {
        try {
            $searchQuery = $request->search ?? '';
            $countries = Country::withCount('states')
                ->where('status', 1)
                ->where(function ($query) use ($searchQuery) {
                    $query->whereLike('name', "%{$searchQuery}%")
                        ->orWhereHas('translations', function ($q) use ($searchQuery) {
                            $q->where('key', 'name')->whereLike('value', "%{$searchQuery}%");
                        });
                })
                ->with(['translations.language:id,code', 'locationIndexability'])
                ->orderBy('name', 'ASC')
                ->paginate();

            $countries->getCollection()->transform(function ($country) {
                if ($country->translations instanceof \Illuminate\Support\Collection) {
                    $country->translations = $country->translations
                        ->where('key', 'name')
                        ->map(function ($translation) use ($country) {
                            return [
                                'id' => $translation->id,
                                'country_id' => $country->id,
                                'language_id' => $translation->language_id,
                                'name' => $translation->value,
                                'language_code' => optional($translation->language)->code,
                            ];
                        })->values();
                } else {
                    $country->translations = [];
                }

                // Attach location indexability flags for frontend SEO router
                $indexability = $country->locationIndexability ?? collect();
                $country->location_indexability = [
                    'region' => (bool) ($indexability->where('level', 'region')->first()->is_indexable ?? true),
                    'department' => (bool) ($indexability->where('level', 'department')->first()->is_indexable ?? false),
                    'city' => (bool) ($indexability->where('level', 'city')->first()->is_indexable ?? true),
                ];

                $country->slug = $country->seo_slug;
                $country->slugs = [
                    'fr' => $country->seo_slug,
                    'en' => $country->seo_slug,
                ];

                return $country;
            });

            ResponseService::successResponse(__('Countries Fetched Successfully'), $countries);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getCountries');
            ResponseService::errorResponse(__('Failed to fetch countries'));
        }
    }

    /** Get States */
    public function getStates(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'country_id' => 'nullable|integer',
            'search' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $searchQuery = $request->search ?? '';
            $statesQuery = State::withCount('cities')
                ->where('status', 1)
                ->whereLike('name', "%{$searchQuery}%")
                ->orderBy('name', 'ASC');

            if (isset($request->country_id)) {
                $statesQuery->where('country_id', $request->country_id);
            }

            $states = $statesQuery->paginate($request->input('per_page', 15));

            $stateIds = $states->getCollection()->pluck('id');
            $allStateSlugs = \App\Models\TaxonomySlug::where('entity_type', 'state')
                ->whereIn('entity_id', $stateIds)
                ->get()
                ->groupBy('entity_id');

            $states->getCollection()->transform(function ($state) use ($allStateSlugs) {
                $slugMap = ($allStateSlugs->get($state->id) ?? collect())->pluck('slug', 'locale')->toArray();
                $defaultSlug = $slugMap['fr'] ?? $slugMap['en'] ?? \Illuminate\Support\Str::slug($state->name);

                $state->slug = $defaultSlug;
                $state->seo_slug = $defaultSlug;
                $state->slugs = [
                    'fr' => $slugMap['fr'] ?? $defaultSlug,
                    'en' => $slugMap['en'] ?? $defaultSlug,
                ];

                return $state;
            });

            ResponseService::successResponse(__('States Fetched Successfully'), $states);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller->getStates');
            ResponseService::errorResponse(__('Failed to fetch states'));
        }
    }

    /** Get Cities */
    public function getCities(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'state_id' => 'nullable|integer',
                'search' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $searchQuery = $request->search ?? '';

            $citiesQuery = City::with('translations')
                ->withCount('areas')
                ->where('cities.status', 1)
                ->orderBy('cities.name', 'ASC');

            if ($searchQuery !== '') {
                $citiesQuery->where(function ($q) use ($searchQuery) {
                    $q->whereLike('cities.name', "%{$searchQuery}%")
                        ->orWhereHas('translations', function ($t) use ($searchQuery) {
                            $t->where('key', 'name')->whereLike('value', "%{$searchQuery}%");
                        });
                });
            }

            if ($request->filled('state_id')) {
                $citiesQuery->where('cities.state_id', $request->state_id);
            }

            $cities = $citiesQuery->paginate($request->input('per_page', 15));

            $cityIds = $cities->getCollection()->pluck('id');
            $allCitySlugs = \App\Models\TaxonomySlug::where('entity_type', 'city')
                ->whereIn('entity_id', $cityIds)
                ->get()
                ->groupBy('entity_id');

            $cities->getCollection()->transform(function ($city) use ($allCitySlugs) {
                $slugMap = ($allCitySlugs->get($city->id) ?? collect())->pluck('slug', 'locale')->toArray();
                $defaultSlug = $slugMap['fr'] ?? $slugMap['en'] ?? \Illuminate\Support\Str::slug($city->name);

                $city->slug = $defaultSlug;
                $city->seo_slug = $defaultSlug;
                $city->slugs = [
                    'fr' => $slugMap['fr'] ?? $defaultSlug,
                    'en' => $slugMap['en'] ?? $defaultSlug,
                ];

                return $city;
            });

            return ResponseService::successResponse(__('Cities Fetched Successfully'), $cities);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller->getCities');

            return ResponseService::errorResponse(__('Failed to fetch cities'));
        }
    }

    /** Get Areas */
    public function getAreas(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'city_id' => 'nullable|integer',
            'search' => 'nullable',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $searchQuery = $request->search ?? '';
            $data = Area::with('translations')->search($searchQuery)->where('status', 1)->orderBy('name', 'ASC');
            if (isset($request->city_id)) {
                $data->where('city_id', $request->city_id);
            }

            $data = $data->paginate();
            ResponseService::successResponse(__('Area fetched Successfully'), $data);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getAreas');
            ResponseService::errorResponse();
        }
    }

    /** Get Location From Coordinates */
    public function getLocationFromCoordinates(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'lang' => 'nullable|string',
            'search' => 'nullable|string',
            'place_id' => 'nullable|string',
            'session_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $lat = $request->lat;
            $lng = $request->lng;
            $lang = $request->lang ?? 'en';
            $search = $request->search;
            $placeId = $request->place_id;
            $mapProvider = Setting::where('name', 'map_provider')->value('value') ?? 'free_api';

            $contentLangCode = $request->header('Content-Language') ?? app()->getLocale();
            $currentLanguage = Language::where('code', $contentLangCode)->first();
            $currentLangId = $currentLanguage->id ?? 1;

            if ($search) {
                if ($mapProvider === 'google_places') {
                    $apiKey = Setting::where('name', 'place_api_key')->value('value');
                    if (! $apiKey) {
                        return ResponseService::errorResponse(__('Google Maps API key not set'));
                    }

                    $response = Http::get('https://maps.googleapis.com/maps/api/place/autocomplete/json', [
                        'key' => $apiKey,
                        'input' => $search,
                        'language' => $lang,
                        'sessiontoken' => $request->session_id,
                    ]);

                    return $response->successful()
                        ? ResponseService::successResponse(__('Location fetched from Google API'), $response->json())
                        : ResponseService::errorResponse(__('Failed to fetch from Google Maps API'));
                } else {
                    $areas = Area::with([
                        'translations' => fn($q) => $q->where('language_id', $currentLangId),
                        'city.translations' => fn($q) => $q->where('language_id', $currentLangId),
                        'city.state.translations' => fn($q) => $q->where('language_id', $currentLangId),
                        'city.state.country.translations' => fn($q) => $q->where('language_id', $currentLangId),
                    ])
                        ->where('status', 1)
                        ->whereLike('name', "%{$search}%")
                        ->limit(10)
                        ->get();

                    if ($areas->isNotEmpty()) {
                        return ResponseService::successResponse(__('Matching areas found'), $areas->map(function ($area) {
                            return [
                                'area_id' => $area->id,
                                'area' => $area->name,
                                'area_translation' => optional($area->translations->where('key', 'name')->first())->value ?? $area->name,
                                'city_id' => optional($area->city)->id,
                                'city' => optional($area->city)->name,
                                'city_translation' => optional($area->city->translations->where('key', 'name')->first())->value ?? optional($area->city)->name,
                                'state' => optional($area->city->state)->name,
                                'state_translation' => optional($area->city->state->translations->where('key', 'name')->first())->value ?? optional($area->city->state)->name,
                                'country' => optional($area->city->state->country)->name,
                                'country_translation' => optional($area->city->state->country->translations->where('key', 'name')->first())->value ?? optional($area->city->state->country)->name,
                                'latitude' => $area->latitude,
                                'longitude' => $area->longitude,
                            ];
                        }));
                    }

                    $cities = City::with([
                        'translations' => fn($q) => $q->where('language_id', $currentLangId),
                        'state.translations' => fn($q) => $q->where('language_id', $currentLangId),
                        'state.country.translations' => fn($q) => $q->where('language_id', $currentLangId),
                    ])
                        ->where('status', 1)
                        ->where(function ($q) use ($search) {
                            $q->whereLike('name', "%{$search}%")
                                ->orWhereHas('state', fn($sq) => $sq->whereLike('name', "%{$search}%"))
                                ->orWhereHas('state.country', fn($sq) => $sq->whereLike('name', "%{$search}%"));
                        })
                        ->limit(10)
                        ->get();

                    if ($cities->isEmpty()) {
                        return ResponseService::errorResponse(__('No matching location found'));
                    }

                    return ResponseService::successResponse(__('Matching cities found'), $cities->map(function ($city) {
                        return [
                            'city_id' => $city->id,
                            'city' => $city->name,
                            'city_translation' => optional($city->translations->where('key', 'name')->first())->value ?? $city->name,
                            'state' => optional($city->state)->name,
                            'state_translation' => optional($city->state->translations->where('key', 'name')->first())->value ?? optional($city->state)->name,
                            'country' => optional($city->state->country)->name,
                            'country_translation' => optional($city->state->country->translations->where('key', 'name')->first())->value ?? optional($city->state->country)->name,
                            'latitude' => $city->latitude,
                            'longitude' => $city->longitude,
                        ];
                    }));
                }
            }

            if (! empty($lat) && ! empty($lng)) {
                if ($mapProvider === 'google_places') {
                    $apiKey = Setting::where('name', 'place_api_key')->value('value');
                    if (! $apiKey) {
                        return ResponseService::errorResponse(__('Google Maps API key not set'));
                    }

                    $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
                        'latlng' => "{$lat},{$lng}",
                        'key' => $apiKey,
                        'language' => $lang,
                        'sessiontoken' => $request->session_id,
                    ]);

                    return $response->successful()
                        ? ResponseService::successResponse(__('Location fetched from Google API'), $response->json())
                        : ResponseService::errorResponse(__('Failed to fetch from Google Maps API'));
                } else {

                    // STEP 1: Area-first lookup — find the closest Area across ALL cities.
                    // This provides more accurate city assignment near city boundaries,
                    // since areas are smaller geographic units within cities.
                    $closestArea = Area::with([
                        'translations' => fn($q) => $q->where('language_id', $currentLangId),
                        'city.translations' => fn($q) => $q->where('language_id', $currentLangId),
                        'city.state.translations' => fn($q) => $q->where('language_id', $currentLangId),
                        'city.state.country.translations' => fn($q) => $q->where('language_id', $currentLangId),
                    ])
                        ->where('status', 1)
                        ->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->selectRaw('
                        id, name, latitude, longitude, city_id,
                        ' . DbCompat::haversineSql() . ' AS distance
                    ', [$lat, $lng, $lat])
                        ->orderBy('distance', 'asc')
                        ->first();

                    // STEP 2: If an area is found, use its parent city
                    if ($closestArea && $closestArea->city) {
                        $closestCity = $closestArea->city;

                        return ResponseService::successResponse(__('Location fetched from local database'), [
                            'city_id' => $closestCity->id,
                            'city' => $closestCity->name,
                            'city_translation' => optional($closestCity->translations->where('key', 'name')->first())->value ?? $closestCity->name,
                            'state' => optional($closestCity->state)->name,
                            'state_translation' => optional($closestCity->state?->translations?->where('key', 'name')->first())->value ?? optional($closestCity->state)->name,
                            'country' => optional($closestCity->state?->country)->name,
                            'country_translation' => optional($closestCity->state?->country?->translations?->where('key', 'name')->first())->value ?? optional($closestCity->state?->country)->name,
                            'area_id' => $closestArea->id,
                            'area' => $closestArea->name,
                            'area_translation' => optional($closestArea->translations->where('key', 'name')->first())->value ?? $closestArea->name,
                            'latitude' => $closestArea->latitude,
                            'longitude' => $closestArea->longitude,
                        ]);
                    }

                    // STEP 3: Fallback — no areas with coordinates exist in DB,
                    // fall back to closest city center (original behavior)
                    $closestCity = City::with([
                        'translations' => fn($q) => $q->where('language_id', $currentLangId),
                        'state.translations' => fn($q) => $q->where('language_id', $currentLangId),
                        'state.country.translations' => fn($q) => $q->where('language_id', $currentLangId),
                    ])
                        ->where('status', 1)
                        ->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->selectRaw('
                        id, name, latitude, longitude, state_id,
                        ' . DbCompat::haversineSql() . ' AS distance
                    ', [$lat, $lng, $lat])
                        ->orderBy('distance', 'asc')
                        ->first();

                    if (! $closestCity) {
                        return ResponseService::errorResponse(__('No nearby city found'));
                    }

                    return ResponseService::successResponse(__('Location fetched from local database'), [
                        'city_id' => $closestCity->id,
                        'city' => $closestCity->name,
                        'city_translation' => optional($closestCity->translations->where('key', 'name')->first())->value ?? $closestCity->name,
                        'state' => optional($closestCity->state)->name,
                        'state_translation' => optional($closestCity->state->translations->where('key', 'name')->first())->value ?? optional($closestCity->state)->name,
                        'country' => optional($closestCity->state->country)->name,
                        'country_translation' => optional($closestCity->state->country->translations->where('key', 'name')->first())->value ?? optional($closestCity->state->country)->name,
                        'area_id' => null,
                        'area' => null,
                        'area_translation' => null,
                        'latitude' => $closestCity->latitude,
                        'longitude' => $closestCity->longitude,
                    ]);
                }
            }

            if ($placeId) {
                if ($mapProvider === 'google_places') {
                    $apiKey = Setting::where('name', 'place_api_key')->value('value');
                    if (! $apiKey) {
                        return ResponseService::errorResponse(__('Google Maps API key not set'));
                    }
                    $sessionParam = $request->session_id ? "&sessiontoken={$request->session_id}" : '';
                    $url = "https://maps.googleapis.com/maps/api/geocode/json?place_id={$placeId}&key={$apiKey}&language={$lang}{$sessionParam}";

                    $response = Http::get($url);

                    return $response->successful()
                        ? ResponseService::successResponse(__('Location fetched from place_id'), $response->json())
                        : ResponseService::errorResponse(__('Failed to fetch from Google Maps API using place_id'));
                } else {
                    return ResponseService::errorResponse(__('place_id is only supported with Google Maps provider'));
                }
            }
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getLocationFromCoordinates');

            return ResponseService::errorResponse(__('Failed to fetch location'));
        }
    }

    /**
     * Get Country Slugs for Next.js SSR / Routing
     */
    public function getCountrySlugs()
    {
        try {
            $countries = Country::where('status', 1)
                ->whereNotNull('seo_slug')
                ->select('id', 'name', 'seo_slug', 'iso2', 'default_locale', 'currency_code', 'is_default')
                ->get();

            return ResponseService::successResponse(__('Country slugs fetched successfully.'), $countries);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getCountrySlugs');

            return ResponseService::errorResponse(__('Failed to fetch country slugs'));
        }
    }

    /**
     * Resolve slug from Seo Slug Registry
     */
    public function resolveSlug(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'country_slug' => 'required|string',
            'slug' => 'required|string',
            'locale' => 'nullable|string|in:fr,en',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $country = Country::where('seo_slug', $request->country_slug)->first();
            if (!$country) {
                return ResponseService::errorResponse(__('Country not found'), null, 404);
            }

            $locale = $request->locale ?? $country->default_locale ?? 'fr';
            $entry = \App\Models\SeoSlugRegistry::where('country_id', $country->id)
                ->where('locale', $locale)
                ->where('slug', $request->slug)
                ->first();

            if (!$entry) {
                return ResponseService::errorResponse(__('Slug not found in registry'), null, 404);
            }

            return ResponseService::successResponse(__('Slug resolved successfully.'), [
                'country_id' => $country->id,
                'locale' => $locale,
                'slug' => $entry->slug,
                'entity_type' => $entry->entity_type,
                'entity_id' => $entry->entity_id,
                'level' => $entry->level,
                'parent_id' => $entry->parent_id,
            ]);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> resolveSlug');

            return ResponseService::errorResponse(__('Failed to resolve slug'));
        }
    }

    /**
     * Validate full URL path segments for Next.js SSR router
     */
    public function resolveSeoPath(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'country_slug' => 'required|string',
            'path' => 'nullable|array',
            'path.*' => 'string',
            'locale' => 'nullable|string|in:fr,en',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $countrySlug = $request->input('country_slug');
            $segments = $request->input('path', []);
            $locale = $request->input('locale', 'fr');

            $result = SeoTaxonomyService::resolvePath($countrySlug, $segments, $locale);

            if ($result['status'] === 404) {
                return ResponseService::errorResponse($result['error'] ?? __('Page not found'), null, 404);
            }

            return ResponseService::successResponse(__('Path resolved successfully.'), $result);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'LocationApiController -> resolveSeoPath');

            return ResponseService::errorResponse(__('Failed to resolve path'));
        }
    }

    /**
     * Fetch ads for a specific Country x Location x Category combination
     */
    public function getCombinationListings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'country_id' => 'required|integer',
            'location_id' => 'nullable|integer',
            'location_level' => 'nullable|integer|in:1,2,3',
            'category_id' => 'nullable|integer',
            'page' => 'nullable|integer',
            'per_page' => 'nullable|integer|max:48',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $countryId = $request->input('country_id');
            $locationId = $request->input('location_id');
            $locationLevel = (int)$request->input('location_level');
            $categoryId = $request->input('category_id');
            $perPage = (int)$request->input('per_page', 16);

            $query = Item::approved()
                ->getNonExpiredItems()
                ->with(['gallery_images', 'category:id,name,slug', 'area']);

            // 1. Country filter
            // Note: items table links to countries via area_id -> areas.country_id
            $query->whereHas('area', function ($areaQuery) use ($countryId) {
                $areaQuery->where('country_id', $countryId);
            });

            // 2. Location filter with descendant matching (Annex H rule)
            if ($locationId) {
                if ($locationLevel === 1) {
                    // Region / State: match all areas of that state
                    $query->whereHas('area', fn($q) => $q->where('state_id', $locationId));
                } elseif ($locationLevel === 3) {
                    // City: match all areas of that city
                    $query->whereHas('area', fn($q) => $q->where('city_id', $locationId));
                }
            }

            // 3. Category filter with descendant matching (Annex H rule)
            if ($categoryId) {
                $category = Category::find($categoryId);
                $categoryIds = $category ? $category->descendantsAndSelf()->pluck('id')->toArray() : [$categoryId];
                $query->whereIn('category_id', $categoryIds);
            }

            $listings = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return ResponseService::successResponse(__('Combination listings fetched successfully.'), $listings);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'LocationApiController -> getCombinationListings');

            return ResponseService::errorResponse(__('Failed to fetch combination listings'));
        }
    }

    /**
     * Resolve legacy URL to new single-hop SEO destination for Next.js SSR
     */
    public function resolveRedirect(Request $request)
    {
        $path = $request->query('path');
        if (!$path) {
            return ResponseService::validationError(__('Path parameter is required'));
        }

        try {
            $queryParams = $request->except(['path']);
            $result = SeoRedirectService::resolve($path, $queryParams);

            if (!$result) {
                return ResponseService::errorResponse(__('No redirect rule found'), null, 404);
            }

            return ResponseService::successResponse(__('Redirect resolved successfully.'), $result);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'LocationApiController -> resolveRedirect');

            return ResponseService::errorResponse(__('Failed to resolve redirect'));
        }
    }
}
