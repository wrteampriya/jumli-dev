<?php

namespace App\Services\Module;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Module-setting rules for creating/updating an ad (API). Every check
// returns an error message (or null) rather than responding itself, so the
// caller can roll back an open transaction before calling
// ResponseService::validationError().
//
// PRICE RANGE STORAGE: pricing_mode = 'range' REUSES the items.min_salary /
// items.max_salary columns as the price range (from / to) for every
// category — there are no separate min_price/max_price columns:
//   fixed / negotiable -> price             (min_salary, max_salary NULL)
//   range              -> min_salary and/or max_salary, at least one (price NULL)
//   free               -> price 0             (min_salary, max_salary NULL)
//   price_on_request   -> all three NULL
class ItemModuleValidator
{
    protected const PRICE_COLUMNS_BY_MODE = [
        'fixed' => ['price'],
        'negotiable' => ['price'],
        'range' => ['min_salary', 'max_salary'],
        'price_on_request' => [],
        'free' => [],
    ];

    protected const LINK_VIDEO_TYPES = ['youtube_link', 'vimeo_link', 'other_link'];

    // The X-Module header (when sent) must name the module the category
    // belongs to — settings always come from the category's module.
    public static function moduleMismatchError(?int $categoryModuleId): ?string
    {
        $headerModuleId = ModuleService::currentId();

        if ($headerModuleId && $headerModuleId !== $categoryModuleId) {
            return __('The selected category does not belong to this module.');
        }

        return null;
    }

    // Module-aware clients (X-Module sent) choose a pricing_mode; it must be
    // one of the module's pricing_modes and the price fields must match it.
    // Returns [error, attributes]: attributes are the normalized
    // pricing_mode/price/min_salary/max_salary to save — empty on an update
    // that doesn't touch pricing at all.
    public static function pricing(array $settings, Request $request, ?Item $item = null, bool $moduleChanged = false): array
    {
        $hasPriceInput = $request->filled('price') || $request->filled('min_salary') || $request->filled('max_salary')
            // A stored range bound sent blank is a change too (it clears that bound).
            || ($item?->pricing_mode === 'range' && $request->hasAny(['min_salary', 'max_salary']));

        // Nothing price-related sent on an update: keep the stored pricing —
        // unless the item moved into a module that doesn't allow the stored
        // mode. In its own module a mode the module stopped listing (or one
        // backfilled by the pricing_mode migration) stays editable.
        $storedModeAllowed = !$moduleChanged
            || !$item?->pricing_mode
            || in_array($item->pricing_mode, $settings['pricing_modes'], true);

        if ($item && !$request->filled('pricing_mode') && !$hasPriceInput && $storedModeAllowed) {
            return [null, []];
        }

        $mode = $request->input('pricing_mode') ?: $item?->pricing_mode ?: $settings['default_pricing_mode'];

        if (!array_key_exists($mode, self::PRICE_COLUMNS_BY_MODE)) {
            return [__('The selected pricing mode is invalid.'), []];
        }

        if (!in_array($mode, $settings['pricing_modes'], true)) {
            return [__('The :mode pricing is not supported for this listing type.', ['mode' => str_replace('_', ' ', $mode)]), []];
        }

        // On update, a field not sent keeps its stored value — but only when
        // the stored mode uses the same columns.
        $sameColumns = $item && $item->pricing_mode
            && (self::PRICE_COLUMNS_BY_MODE[$item->pricing_mode] ?? null) === self::PRICE_COLUMNS_BY_MODE[$mode];

        $input = [];
        foreach (self::PRICE_COLUMNS_BY_MODE[$mode] as $column) {
            // A range bound sent blank clears it (only one bound is required).
            $clearsBound = $mode === 'range' && $request->has($column) && !$request->filled($column);
            $input[$column] = $request->filled($column) ? $request->input($column) : ($sameColumns && !$clearsBound ? $item->{$column} : null);
        }

        // range: at least one bound; max must exceed min only when both are set.
        $rules = match ($mode) {
            'fixed', 'negotiable' => ['price' => 'required|numeric|min:0'],
            'range' => [
                'min_salary' => 'nullable|required_without:max_salary|numeric|min:0',
                'max_salary' => 'nullable|required_without:min_salary|numeric|min:0'
                    .(isset($input['min_salary'], $input['max_salary']) ? '|gt:min_salary' : ''),
            ],
            default => [],
        };

        $validator = Validator::make($input, $rules, [], [
            'min_salary' => __('minimum price'),
            'max_salary' => __('maximum price'),
        ]);

        if ($validator->fails()) {
            return [$validator->errors()->first(), []];
        }

        return [null, [
            'pricing_mode' => $mode,
            'price' => $mode === 'free' ? 0 : ($input['price'] ?? null),
            'min_salary' => $input['min_salary'] ?? null,
            'max_salary' => $input['max_salary'] ?? null,
        ]];
    }

    // Old clients (no X-Module) keep the legacy price/salary rules; the mode
    // is derived from what they sent so pricing_mode stays meaningful.
    public static function legacyPricingMode(Request $request): string
    {
        if ($request->filled('price')) {
            return 'fixed';
        }

        if ($request->filled('min_salary') || $request->filled('max_salary')) {
            return 'range';
        }

        return 'price_on_request';
    }

    // max_images is the TOTAL per ad: every image goes in gallery_images[]
    // and the first one is the cover (is_default) — so 5 = 1 cover + 4.
    public static function galleryLimitError(array $settings, int $totalImages): ?string
    {
        $max = (int) $settings['max_images'];

        if ($totalImages <= $max) {
            return null;
        }

        return __('You can upload a maximum of :max images (1 cover + :gallery gallery images).', [
            'max' => $max,
            'gallery' => max($max - 1, 0),
        ]);
    }

    // The video type the request actually attaches, or null. Clients send
    // video_type even when the user added no video, so a link type counts
    // only with a video_link, and 'file' only with an upload in $fileField.
    // The API passes no $fileField: its file comes later via upload-media,
    // which checks the setting itself.
    public static function submittedVideoType(Request $request, ?string $fileField = null): ?string
    {
        $videoType = $request->input('video_type');

        if (in_array($videoType, self::LINK_VIDEO_TYPES, true)) {
            return filled($request->input('video_link')) ? $videoType : null;
        }

        if ($videoType === 'file' && $fileField && $request->hasFile($fileField)) {
            return 'file';
        }

        return null;
    }

    // product_video: whether an ad in this module may have a product video
    // (one per ad, link or upload).
    public static function videoTypeError(array $settings, ?string $videoType): ?string
    {
        if (empty($videoType) || $settings['product_video']) {
            return null;
        }

        return __('Product videos are not allowed for this listing type.');
    }
}
