<?php

namespace App\Http\Controllers;

use App\Models\SeoSitemapThreshold;
use App\Services\ResponseService;
use App\Services\SeoSitemapService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SeoSitemapSettingController extends Controller
{
    /**
     * Update thresholds from admin web form
     */
    public function updateThresholds(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-update');

        $validator = Validator::make($request->all(), [
            'thresholds' => 'required|array',
            'thresholds.*.depth' => 'required|integer|in:0,1,2,3',
            'thresholds.*.min_listings' => 'required|integer|min:0|max:1000',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            foreach ($request->input('thresholds') as $item) {
                SeoSitemapThreshold::where('depth', $item['depth'])->update([
                    'min_listings' => $item['min_listings'],
                    'is_enabled' => true,
                ]);
            }

            return ResponseService::successResponse(__('Sitemap thresholds updated successfully.'));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SeoSitemapSettingController -> updateThresholds');
            return ResponseService::errorResponse(__('Failed to update thresholds.'));
        }
    }

    /**
     * Rebuild sitemaps from admin web button
     */
    public function rebuild(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-update');

        try {
            $statsResult = SeoSitemapService::refreshStats();
            $buildResult = SeoSitemapService::buildSitemaps();

            return ResponseService::successResponse(
                __('Sitemaps rebuilt successfully! (:files files generated)', ['files' => $buildResult['total_files']]),
                [
                    'stats' => $statsResult,
                    'sitemaps' => $buildResult,
                ]
            );
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SeoSitemapSettingController -> rebuild');
            return ResponseService::errorResponse(__('Failed to rebuild sitemaps: ' . $th->getMessage()));
        }
    }
}
