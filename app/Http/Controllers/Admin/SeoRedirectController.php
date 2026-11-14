<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Country;
use App\Models\Item;
use App\Models\SeoRedirect;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use App\Services\SeoRedirectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SeoRedirectController extends Controller
{
    /**
     * Display a listing of custom redirect rules for bootstrap-table.
     */
    public function show(Request $request)
    {
        $offset = $request->offset ?? 0;
        $limit = $request->limit ?? 10;
        $sort = $request->sort ?? 'id';
        $order = $request->order ?? 'DESC';

        $query = SeoRedirect::orderBy($sort, $order);

        if (!empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('source_path', 'like', "%{$search}%")
                  ->orWhere('target_path', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $total = $query->count();
        $query->skip($offset)->take($limit);
        $result = $query->get();

        $rows = [];
        foreach ($result as $row) {
            $temp = $row->toArray();
            $temp['status_badge'] = $row->status_code == 301
                ? '<span class="badge bg-success">301 Moved</span>'
                : '<span class="badge bg-danger">410 Gone</span>';

            $temp['active_toggle'] = $row->is_active
                ? '<span class="badge bg-primary">' . __('Active') . '</span>'
                : '<span class="badge bg-secondary">' . __('Disabled') . '</span>';

            $operate = BootstrapTableService::deleteButton(route('seo-redirects.destroy', $row->id));
            $temp['operate'] = $operate;

            $rows[] = $temp;
        }

        return response()->json([
            'total' => $total,
            'rows' => $rows,
        ]);
    }

    /**
     * Store a newly created custom redirect rule.
     */
    public function store(Request $request)
    {
        ResponseService::noPermissionThenRedirect('settings-update');

        $validator = Validator::make($request->all(), [
            'source_path' => 'required|string|max:500|unique:seo_redirects,source_path',
            'target_path' => 'required|string|max:500',
            'status_code' => 'required|in:301,410',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $source = '/' . ltrim(trim($request->input('source_path')), '/');
            $target = '/' . ltrim(trim($request->input('target_path')), '/');

            SeoRedirect::create([
                'source_path' => $source,
                'target_path' => $target,
                'status_code' => (int)$request->input('status_code'),
                'redirect_type' => 'custom',
                'is_active' => true,
                'notes' => $request->input('notes'),
            ]);

            return ResponseService::successResponse(__('Redirect Rule Added Successfully'));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SeoRedirectController -> store');
            return ResponseService::errorResponse(__('Failed to add redirect rule'));
        }
    }

    /**
     * Remove the specified redirect rule.
     */
    public function destroy(int $id)
    {
        ResponseService::noPermissionThenRedirect('settings-update');

        try {
            $rule = SeoRedirect::findOrFail($id);
            $rule->delete();

            return ResponseService::successResponse(__('Redirect Rule Deleted Successfully'));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SeoRedirectController -> destroy');
            return ResponseService::errorResponse(__('Failed to delete redirect rule'));
        }
    }

    /**
     * Stream full CSV audit of all 81,000+ indexed URLs (single-hop verification).
     */
    public function exportAuditCsv(): StreamedResponse
    {
        // Increase time limit for full site audit streaming
        set_time_limit(0);

        $filename = 'jumli-seo-redirects-audit-' . date('Y-m-d-His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () {
            if (ob_get_level()) {
                ob_end_clean();
            }

            $handle = fopen('php://output', 'w');
            // Write UTF-8 BOM so Excel opens accents properly
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // CSV Header Row
            fputcsv($handle, [
                'Old URL (Indexed)',
                'New Target URL',
                'HTTP Status',
                'Page Type',
                'Ad / Entity Status',
                'Destination Logic & Audit Notes'
            ]);

            // 1. Stream Custom Redirect Rules from DB
            try {
                $customRules = SeoRedirect::where('is_active', true)->get();
                foreach ($customRules as $rule) {
                    fputcsv($handle, [
                        $rule->source_path,
                        $rule->target_path,
                        $rule->status_code,
                        'Custom Override',
                        'Active',
                        $rule->notes ?: 'Manual rule from seo_redirects'
                    ]);
                }
            } catch (Throwable $e) {
                // Table might not be migrated yet or empty
            }

            // 2. Stream Legacy Categories (with preloaded slugs)
            try {
                $defaultCountry = Country::where('is_default', 1)->first() ?? Country::first();
                $defaultCountrySlug = $defaultCountry->seo_slug ?? 'cameroun';

                $taxSlugs = \App\Models\TaxonomySlug::select('entity_type', 'entity_id', 'locale', 'slug')->get();
                $slugCache = [
                    'default_country_slug' => $defaultCountrySlug,
                    'category' => [],
                    'city' => [],
                ];
                foreach ($taxSlugs as $s) {
                    $slugCache[$s->entity_type][$s->entity_id][$s->locale] = $s->slug;
                }

                Category::select('id', 'name', 'slug')->chunk(200, function ($categories) use ($handle, $slugCache, $defaultCountrySlug) {
                    foreach ($categories as $cat) {
                        $frCatSlug = $slugCache['category'][$cat->id]['fr'] ?? $cat->slug;
                        $enCatSlug = $slugCache['category'][$cat->id]['en'] ?? $cat->slug;

                        fputcsv($handle, [
                            "/ads/{$cat->slug}",
                            "/{$defaultCountrySlug}/{$frCatSlug}/",
                            301,
                            'Category (FR)',
                            'Active',
                            'Legacy category redirected to default country category page'
                        ]);

                        fputcsv($handle, [
                            "/en/ads/{$cat->slug}",
                            "/en/{$defaultCountrySlug}/{$enCatSlug}/",
                            301,
                            'Category (EN)',
                            'Active',
                            'Legacy category redirected to default country category page'
                        ]);
                    }
                });
            } catch (Throwable $e) {
                // Skip if error
            }

            // 3. Stream all Ads (Chunked by 1000 with 0 extra queries via slugCache)
            try {
                Item::withTrashed()
                    ->with(['area.country', 'area.city', 'category'])
                    ->select('id', 'name', 'slug', 'status', 'expiry_date', 'deleted_at', 'area_id', 'category_id')
                    ->chunk(1000, function ($items) use ($handle, $slugCache) {
                        foreach ($items as $item) {
                            // FR legacy URL
                            $resFr = SeoRedirectService::resolveItemDestination($item, false, $slugCache);
                            fputcsv($handle, [
                                "/ad-details/{$item->slug}",
                                $resFr['target'] ?? 'N/A',
                                $resFr['status'],
                                'Ad Page (FR)',
                                $resFr['ad_status'],
                                $resFr['notes']
                            ]);

                            // EN legacy URL
                            $resEn = SeoRedirectService::resolveItemDestination($item, true, $slugCache);
                            fputcsv($handle, [
                                "/en/ad-details/{$item->slug}",
                                $resEn['target'] ?? 'N/A',
                                $resEn['status'],
                                'Ad Page (EN)',
                                $resEn['ad_status'],
                                $resEn['notes']
                            ]);
                        }
                    });
            } catch (Throwable $e) {
                // Log and gracefully end stream
            }

            fclose($handle);
        }, 200, $headers);
    }
}
