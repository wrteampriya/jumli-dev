<?php

namespace App\Http\Requests\Admin\Packages;

use App\Http\Requests\Admin\AdminJsonRequest;
use App\Models\Module;
use App\Services\CachingService;
use App\Services\Packages\PackageModules;
use Illuminate\Validation\Rule;

/**
 * Shared create / edit validation for the Ad and Featured package forms
 * (Packages\AdPackageController, Packages\FeaturedPackageController).
 *
 * Store validates every field. Update only validates what the edit form can change —
 * name, iOS product ID, icon and key points; module, type, prices, duration, limits and
 * categories are fixed once a package exists (subscribers already bought it).
 * Both forms post the same field names: the route already decides the package type.
 */
abstract class PackageRequest extends AdminJsonRequest
{
    /** Package `type` column value. */
    public const TYPE = '';

    /** Prefix of this type's permissions, e.g. "advertisement-listing-package". */
    public const PERMISSION_PREFIX = '';

    protected const DEFAULT_LANGUAGE_ID = 1;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can(static::PERMISSION_PREFIX.($this->isUpdate() ? '-update' : '-create'));
    }

    public function isUpdate(): bool
    {
        return $this->isMethod('put') || $this->isMethod('patch');
    }

    public function rules(): array
    {
        $rules = [
            'name' => 'required|array',
            'name.'.self::DEFAULT_LANGUAGE_ID => 'required|string|max:255',
            'name.*' => 'nullable|string|max:255',
            'ios_product_id' => 'nullable|string|max:255',
            'icon' => ($this->isUpdate() ? 'nullable' : 'required').'|mimes:jpeg,jpg,png|max:7168',
            'key_points' => 'nullable|array',
            'key_points.*' => 'nullable|array',
            'key_points.*.*' => 'nullable|string|max:255',
        ];

        if ($this->isUpdate()) {
            return $rules;
        }

        return $rules + [
            'module_id' => $this->moduleRule(),
            'price' => 'required|numeric|min:0',
            'discount_in_percentage' => 'required|numeric|min:0|max:100',
            'final_price' => 'nullable|numeric|min:0',
            'package_duration_type' => 'required|in:limited,unlimited',
            'duration' => 'required_if:package_duration_type,limited|nullable|integer|min:1',
            'item_limit_type' => 'required|in:limited,unlimited',
            'item_limit' => 'required_if:item_limit_type,limited|nullable|integer|min:1',
            'listing_duration_type' => 'required|in:standard,package,custom',
            'listing_duration_days' => 'required_if:listing_duration_type,custom|nullable|integer|min:1',
        ];
    }

    public function attributes(): array
    {
        $attributes = [
            'name.'.self::DEFAULT_LANGUAGE_ID => __('Package Name'),
            'module_id' => __('Module'),
            'discount_in_percentage' => __('Discount'),
            'duration' => __('Duration (Days)'),
            'item_limit' => __('Number of Listings'),
            'listing_duration_days' => __('Days'),
        ];
        foreach (CachingService::getLanguages() as $language) {
            $attributes["name.{$language->id}"] ??= __('Package Name').' ('.$language->name.')';
        }

        return $attributes;
    }

    /**
     * Module must be one this package kind is enabled for. With a single active module the
     * form has no Module field and BelongsToModule fills in the default module.
     */
    protected function moduleRule(): array
    {
        $allowedIds = PackageModules::allowedFor(static::TYPE)->pluck('id')->all();

        return Module::active()->count() > 1
            ? ['required', 'integer', Rule::in($allowedIds)]
            : ['nullable', 'integer', Rule::in($allowedIds)];
    }
}
