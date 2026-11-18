<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SeoSitemapThreshold;
use App\Services\ResponseService;
use App\Services\SeoSitemapService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SeoSitemapApiController extends Controller
{
    /**
     * Get real-time status of sitemaps and current thresholds
     */
    public function getStatus()
    {
        try {
            SeoSitemapThreshold::seedDefaults();
            $status = SeoSitemapService::getStatus();

            return ResponseService::successResponse(__('Sitemaps status fetched successfully.'), $status);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SeoSitemapApiController -> getStatus');
            return ResponseService::errorResponse(__('Failed to fetch sitemaps status.'));
        }
    }

    /**
     * Update listing thresholds for sitemaps
     */
    public function updateThresholds(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'thresholds' => 'required|array',
            'thresholds.*.depth' => 'required|integer|in:0,1,2,3',
            'thresholds.*.min_listings' => 'required|integer|min:0|max:1000',
            'thresholds.*.is_enabled' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            foreach ($request->input('thresholds') as $item) {
                SeoSitemapThreshold::where('depth', $item['depth'])->update([
                    'min_listings' => $item['min_listings'],
                    'is_enabled' => $item['is_enabled'] ?? true,
                ]);
            }

            return ResponseService::successResponse(
                __('Sitemap thresholds updated successfully.'),
                SeoSitemapThreshold::orderBy('depth')->get()
            );
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SeoSitemapApiController -> updateThresholds');
            return ResponseService::errorResponse(__('Failed to update sitemap thresholds.'));
        }
    }

    /**
     * Trigger stats refresh and sitemap rebuild on demand
     */
    public function rebuild(Request $request)
    {
        try {
            // 1. Refresh pre-calculated stats
            $statsResult = SeoSitemapService::refreshStats();

            // 2. Build sitemap files
            $buildResult = SeoSitemapService::buildSitemaps();

            return ResponseService::successResponse(
                __('Sitemaps and combination statistics rebuilt successfully.'),
                [
                    'stats' => $statsResult,
                    'sitemaps' => $buildResult,
                ]
            );
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SeoSitemapApiController -> rebuild');
            return ResponseService::errorResponse(__('Failed to rebuild sitemaps.'));
        }
    }
}
