<?php

namespace App\Http\Requests\Admin\Lookup;

use App\Http\Requests\Admin\AdminJsonRequest;

/**
 * GET lookup/categories — active categories for admin pickers, as a flat list with tree levels.
 *
 * Callers (keep in sync with PERMISSIONS when a form starts / stops using it):
 *  - Slider create                               (slider-create)
 *  - Banner Ad create / edit                     (banner-ad-create, banner-ad-update)
 *  - Feature Section create / edit               (feature-section-create, feature-section-update)
 *  - Home Screen Sections → popular categories   (home-screen-section-update)
 *  - Ad Packages create / edit → category scope  (advertisement-listing-package-create, advertisement-listing-package-update)
 *  - Custom Fields create / edit → category scope (custom-field-create, custom-field-update)
 */
class LookupCategoriesRequest extends AdminJsonRequest
{
    protected const PERMISSIONS = [
        'slider-create',
        'banner-ad-create',
        'banner-ad-update',
        'feature-section-create',
        'feature-section-update',
        'home-screen-section-update',
        'advertisement-listing-package-create',
        'advertisement-listing-package-update',
        'custom-field-create',
        'custom-field-update',
    ];

    public function rules(): array
    {
        return [
            'module_id' => 'nullable|integer|exists:modules,id',
            // Omitted = the whole tree; 0 = top level only; an id = that category's direct children.
            'parent_id' => 'nullable|integer|min:0',
            // Leave out categories already on the home screen's popular list.
            'exclude_popular' => 'nullable|boolean',
            'search' => 'nullable|string|max:100',
            // Paging is opt-in: send per_page to get has_more / current_page back.
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
