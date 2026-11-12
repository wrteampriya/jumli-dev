<?php

namespace App\Http\Requests\Admin\Packages;

/**
 * Featured Packages (type advertisement) create / edit — Packages\FeaturedPackageController.
 *
 * Featured packages are global (all categories of their module) for now. To scope them by
 * category later: add the is_global / selected_categories rules and the ownership check from
 * AdPackageRequest, stop forcing is_global below, and
 * port the category handling into FeaturedPackageController (see its docblock).
 */
class FeaturedPackageRequest extends PackageRequest
{
    public const TYPE = 'advertisement';

    public const PERMISSION_PREFIX = 'featured-advertisement-package';

    protected function prepareForValidation(): void
    {
        if (! $this->isUpdate()) {
            $this->merge(['is_global' => 1, 'selected_categories' => []]);
        }
    }
}
