<?php

namespace App\Http\Requests\Admin\Lookup;

use App\Http\Requests\Admin\AdminJsonRequest;

/**
 * GET lookup/items — approved, non-expired items for admin pickers.
 *
 * Callers (keep in sync with PERMISSIONS when a form starts / stops using it):
 *  - Slider create            (slider-create)
 *  - Banner Ad create / edit  (banner-ad-create, banner-ad-update)
 *  - Feature Section create   (feature-section-create, feature-section-update)
 *  - Send Notification        (notification-create)
 */
class LookupItemsRequest extends AdminJsonRequest
{
    protected const PERMISSIONS = [
        'slider-create',
        'banner-ad-create',
        'banner-ad-update',
        'feature-section-create',
        'feature-section-update',
        'notification-create',
    ];

    public function rules(): array
    {
        return [
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer',
            'module_id' => 'nullable|integer|exists:modules,id',
            'search' => 'nullable|string|max:100',
            // Omitted = full list (current select2 pickers). Set it once a picker moves to
            // server-side search; the response then carries has_more.
            'limit' => 'nullable|integer|min:1|max:500',
        ];
    }
}
