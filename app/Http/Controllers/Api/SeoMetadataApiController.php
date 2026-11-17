<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SeoMetaEntity;
use App\Models\SeoTemplateGlobal;
use App\Services\ResponseService;
use App\Services\SeoMetadataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SeoMetadataApiController extends Controller
{
    /**
     * Get all global page-type templates
     */
    public function getTemplates(Request $request)
    {
        try {
            if (SeoTemplateGlobal::count() === 0) {
                SeoMetadataService::seedDefaultTemplates();
            }

            $query = SeoTemplateGlobal::query();

            if ($request->filled('page_type')) {
                $query->where('page_type', $request->page_type);
            }
            if ($request->filled('locale')) {
                $query->where('locale', $request->locale);
            }
            if ($request->filled('variant')) {
                $query->where('variant', $request->variant);
            }

            $templates = $query->orderBy('page_type')->orderBy('locale')->orderBy('variant')->get();

            return ResponseService::successResponse(__('Global SEO templates fetched successfully.'), $templates);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SeoMetadataApiController -> getTemplates');
            return ResponseService::errorResponse(__('Failed to fetch global SEO templates.'));
        }
    }

    /**
     * Update or create a global page-type template
     */
    public function saveTemplate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page_type' => 'required|in:country,location,category,combination,ad',
            'locale' => 'required|string|max:10',
            'variant' => 'required|in:default,empty',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:1000',
            'h1' => 'nullable|string|max:255',
            'intro_text' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $template = SeoTemplateGlobal::updateOrCreate(
                [
                    'page_type' => $request->page_type,
                    'locale' => $request->locale,
                    'variant' => $request->variant,
                ],
                $request->only(['meta_title', 'meta_description', 'h1', 'intro_text'])
            );

            return ResponseService::successResponse(__('Global SEO template saved successfully.'), $template);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SeoMetadataApiController -> saveTemplate');
            return ResponseService::errorResponse(__('Failed to save global SEO template.'));
        }
    }

    /**
     * Get entity overrides (category, city, state, country)
     */
    public function getEntityOverrides(Request $request)
    {
        try {
            $query = SeoMetaEntity::query();

            if ($request->filled('entity_type')) {
                $query->where('entity_type', $request->entity_type);
            }
            if ($request->filled('entity_id')) {
                $query->where('entity_id', $request->entity_id);
            }
            if ($request->filled('locale')) {
                $query->where('locale', $request->locale);
            }

            $overrides = $query->paginate($request->input('per_page', 20));

            return ResponseService::successResponse(__('Entity SEO overrides fetched successfully.'), $overrides);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SeoMetadataApiController -> getEntityOverrides');
            return ResponseService::errorResponse(__('Failed to fetch entity SEO overrides.'));
        }
    }

    /**
     * Update or create an entity override
     */
    public function saveEntityOverride(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|in:category,city,state,country',
            'entity_id' => 'required|integer',
            'locale' => 'required|string|max:10',
            'variant' => 'required|in:default,empty',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:1000',
            'h1' => 'nullable|string|max:255',
            'intro_text' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $override = SeoMetaEntity::updateOrCreate(
                [
                    'entity_type' => $request->entity_type,
                    'entity_id' => $request->entity_id,
                    'locale' => $request->locale,
                    'variant' => $request->variant,
                ],
                $request->only(['meta_title', 'meta_description', 'h1', 'intro_text'])
            );

            return ResponseService::successResponse(__('Entity SEO override saved successfully.'), $override);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SeoMetadataApiController -> saveEntityOverride');
            return ResponseService::errorResponse(__('Failed to save entity SEO override.'));
        }
    }

    /**
     * Live preview / resolve test endpoint
     */
    public function preview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page_type' => 'required|in:country,location,category,combination,ad',
            'locale' => 'nullable|string|max:10',
            'listing_count' => 'nullable|integer',
            'page' => 'nullable|integer',
            'context' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $pageType = $request->page_type;
            $locale = $request->input('locale', 'fr');
            $listingCount = (int)$request->input('listing_count', 1);
            $page = (int)$request->input('page', 1);
            $context = $request->input('context', []);

            $resolved = SeoMetadataService::resolve($pageType, $context, $locale, $listingCount, $page);

            return ResponseService::successResponse(__('Metadata resolved successfully.'), $resolved);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SeoMetadataApiController -> preview');
            return ResponseService::errorResponse(__('Failed to resolve metadata.'));
        }
    }
}
