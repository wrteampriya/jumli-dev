<?php

namespace App\Http\Requests\Admin\Packages;

use Illuminate\Validation\Validator;
use App\Services\Module\ModuleService;

/**
 * Ad Packages (type item_listing) create / edit — Packages\AdPackageController.
 * Adds video ads and the category scope on top of the shared package rules.
 */
class AdPackageRequest extends PackageRequest
{
    public const TYPE = 'item_listing';

    public const PERMISSION_PREFIX = 'advertisement-listing-package';

    public function rules(): array
    {
        if ($this->isUpdate()) {
            return parent::rules();
        }

        return parent::rules() + [
            'is_reel_allowed' => 'nullable|boolean',
            'is_global' => 'nullable|in:0,1',
            'selected_categories' => 'required_unless:is_global,1|array|min:1',
            'selected_categories.*' => 'integer|exists:categories,id',
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['selected_categories' => __('Categories')];
    }

    /** Category-wise packages: every picked category must belong to the chosen module. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->isUpdate() || $validator->errors()->isNotEmpty() || $this->input('is_global') == 1) {
                return;
            }
            $error = ModuleService::ownershipError($this->integer('module_id') ?: null, (array) $this->input('selected_categories', []));
            if ($error) {
                $validator->errors()->add('selected_categories', $error);
            }
        }];
    }
}
