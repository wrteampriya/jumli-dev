{{--
    Package create / edit form body, one bordered card per section. Shared by:
      - packages/ad/{create,edit}.blade.php       (via packages/partials/form-page)
      - packages/featured/{create,edit}.blade.php (via packages/partials/form-page)
    $package is set on edit: module, prices, duration, limits, video ads and categories are then
    shown read-only (they're fixed once subscribers can buy the package) — only name, iOS product
    ID, key points and icon are editable (see PackageRequest).
    Page flags: $showVideoSection, $limitsTitle, $supportsCategoryScope; optional labels
    $itemLimitLabel, $durationTypeLabel (default to the Ad Packages wording).
--}}
@php
    $isEdit = isset($package);
    // Default language (id 1) first: its tab opens first and its name is the required one.
    $languages = $languages->sortBy(fn ($lang) => $lang->id == 1 ? 0 : 1)->values();
    $defaultLang = $languages->first();
    $multiLang = $languages->count() > 1;

    // Saved values (edit) or the mockup defaults (create).
    $durationType = $isEdit ? ($package->duration ? 'limited' : 'unlimited') : 'limited';
    $itemLimitType = $isEdit ? ($package->item_limit === 'unlimited' ? 'unlimited' : 'limited') : 'limited';
    $listingDurationType = $isEdit ? $package->listing_duration_type : 'package';
    $listingDurationDays = $isEdit && $listingDurationType === 'custom' ? $package->getRawOriginal('listing_duration_days') : null;
    $savedPackage = $package ?? null;
    $savedTranslations = $translations ?? [];
    $keyPointsFor = function ($langId) use ($savedPackage, $savedTranslations) {
        $raw = ! $savedPackage ? null : ($langId == 1 ? $savedPackage->getRawOriginal('key_points') : ($savedTranslations[$langId]['key_points'] ?? null));
        $points = $raw ? (json_decode($raw, true) ?: []) : [];

        return $points ?: [''];
    };
    $locked = $isEdit ? 'disabled' : '';
@endphp

<div class="pkg-form">
    {{-- 1. Basic Information --}}
    <div class="pkg-section">
        <h5 class="pkg-section-title">{{ __('Basic Information') }}</h5>

        @if ($showModuleSelect)
            <div class="form-group">
                <label for="module_id" class="form-label">{{ __('Module') }} <span class="text-danger">*</span></label>
                <select @unless ($isEdit) name="module_id" @endunless id="module_id" class="form-select" {{ $isEdit ? 'disabled' : 'data-parsley-required=true' }}>
                    @foreach ($modules as $module)
                        <option value="{{ $module->id }}" @selected($module->id == $categoryModuleId)>{{ $module->name }}</option>
                    @endforeach
                </select>
                @if ($isEdit)
                    <small class="text-muted">{{ __('Module cannot be changed after creation.') }}</small>
                @endif
            </div>
        @endif

        @include('packages.partials.lang-tabs')
        <div class="row">
            <div class="col-md-6 form-group">
                @foreach ($languages as $lang)
                    <div class="pkg-lang-pane {{ $lang->id == $defaultLang->id ? 'active' : '' }}" data-lang="{{ $lang->id }}">
                        <label class="form-label">{{ __('Package Name') }} ({{ $lang->name }})
                            @if ($lang->id == $defaultLang->id) <span class="text-danger">*</span> @endif
                        </label>
                        <input type="text" name="name[{{ $lang->id }}]" class="form-control" placeholder="{{ __('e.g. Cars Premium') }}"
                               value="{{ $isEdit ? ($lang->id == 1 ? $package->name : ($translations[$lang->id]['name'] ?? '')) : '' }}"
                               @if ($lang->id == $defaultLang->id) data-parsley-required="true" @endif>
                    </div>
                @endforeach
            </div>
            <div class="col-md-6 form-group">
                <label for="ios_product_id" class="form-label">{{ __('iOS Product ID') }}</label>
                <input type="text" name="ios_product_id" id="ios_product_id" class="form-control" placeholder="com.app.package..."
                       value="{{ $isEdit ? $package->ios_product_id : '' }}">
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 form-group">
                <label for="price" class="form-label">{{ __('Price') }} ({{ $currency_symbol }}) <span class="text-danger">*</span></label>
                <input type="number" @unless ($isEdit) name="price" @endunless id="price" class="form-control pkg-price" min="0" step="0.01"
                       value="{{ $isEdit ? $package->price : 0 }}" {{ $isEdit ? 'disabled' : 'data-parsley-required=true' }}>
            </div>
            <div class="col-md-6 form-group">
                <label for="discount_in_percentage" class="form-label">{{ __('Discount') }} (%) <span class="text-danger">*</span></label>
                <input type="number" @unless ($isEdit) name="discount_in_percentage" @endunless id="discount_in_percentage" class="form-control pkg-discount" min="0" max="100" step="0.01"
                       value="{{ $isEdit ? $package->discount_in_percentage : 0 }}" {{ $isEdit ? 'disabled' : 'data-parsley-required=true' }}>
            </div>
        </div>
        <div class="form-group mb-0">
            <label for="final_price" class="form-label">{{ __('Final Price') }} ({{ $currency_symbol }}) <span class="text-danger">*</span></label>
            {{-- Display only: the server recalculates it from price and discount. --}}
            <div class="input-group pkg-final-price">
                <span class="input-group-text">{{ $currency_symbol }}</span>
                <input type="text" @unless ($isEdit) name="final_price" @endunless id="final_price" class="form-control" readonly
                       value="{{ number_format((float) ($isEdit ? $package->final_price : 0), 2, '.', '') }}">
            </div>
        </div>
    </div>

    {{-- 2. Package Duration Type --}}
    <div class="pkg-section">
        <h5 class="pkg-section-title">{{ __('Package Duration Type') }} <span class="text-danger">*</span></h5>
        <div class="pkg-radio-row">
            @foreach (['limited' => __('Limited'), 'unlimited' => __('Unlimited')] as $value => $label)
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" @unless ($isEdit) name="package_duration_type" @endunless id="package_duration_{{ $value }}"
                           value="{{ $value }}" data-toggles="#pkg_duration_input" data-toggle-on="limited" @checked($durationType === $value) {{ $locked }}>
                    <label class="form-check-label" for="package_duration_{{ $value }}">{{ $label }}</label>
                </div>
            @endforeach
        </div>
        <div id="pkg_duration_input" class="form-group mb-0 mt-3" @if ($durationType !== 'limited') style="display: none;" @endif>
            <label for="duration" class="form-label">{{ __('Duration (Days)') }} <span class="text-danger">*</span></label>
            <input type="number" @unless ($isEdit) name="duration" @endunless id="duration" class="form-control" min="1" placeholder="{{ __('Enter duration in days') }}"
                   value="{{ $isEdit ? $package->duration : '' }}" {{ $isEdit ? 'disabled' : 'data-parsley-required=true' }}>
        </div>
    </div>

    {{-- 3. Listing / Featured Limits --}}
    <div class="pkg-section">
        <h5 class="pkg-section-title">{{ $limitsTitle }}</h5>

        <label class="pkg-sub-label">{{ __('Item Limit') }}</label>
        <div class="pkg-radio-row">
            @foreach (['limited' => __('Limited'), 'unlimited' => __('Unlimited')] as $value => $label)
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" @unless ($isEdit) name="item_limit_type" @endunless id="item_limit_{{ $value }}"
                           value="{{ $value }}" data-toggles="#pkg_item_limit_input" data-toggle-on="limited" @checked($itemLimitType === $value) {{ $locked }}>
                    <label class="form-check-label" for="item_limit_{{ $value }}">{{ $label }}</label>
                </div>
            @endforeach
        </div>
        <div id="pkg_item_limit_input" class="form-group mt-3" @if ($itemLimitType !== 'limited') style="display: none;" @endif>
            <label for="item_limit" class="form-label">{{ $itemLimitLabel ?? __('Number of Listings') }}</label>
            <input type="number" @unless ($isEdit) name="item_limit" @endunless id="item_limit" class="form-control" min="1" placeholder="{{ __('Enter item limit') }}"
                   value="{{ $isEdit && $itemLimitType === 'limited' ? $package->item_limit : '' }}" {{ $isEdit ? 'disabled' : 'data-parsley-required=true' }}>
        </div>

        <label class="pkg-sub-label mt-3">{{ $durationTypeLabel ?? __('Listing Duration Type') }}</label>
        @foreach (['standard' => __('Standard (30 days)'), 'package' => __('Package'), 'custom' => __('Custom')] as $value => $label)
            <div class="form-check">
                <input class="form-check-input" type="radio" @unless ($isEdit) name="listing_duration_type" @endunless id="listing_duration_{{ $value }}"
                       value="{{ $value }}" data-toggles="#pkg_listing_days_input" data-toggle-on="custom" @checked($listingDurationType === $value) {{ $locked }}>
                <label class="form-check-label" for="listing_duration_{{ $value }}">
                    {{ $label }}
                    @if ($value === 'package') <span class="text-muted">= {{ __('package duration') }}</span> @endif
                </label>
            </div>
        @endforeach
        <div id="pkg_listing_days_input" class="form-group mb-0 mt-2" @if ($listingDurationType !== 'custom') style="display: none;" @endif>
            <label for="listing_duration_days" class="form-label">{{ __('Days') }} <span class="text-danger">*</span></label>
            <input type="number" @unless ($isEdit) name="listing_duration_days" @endunless id="listing_duration_days" class="form-control" min="1" placeholder="{{ __('Enter days') }}"
                   value="{{ $listingDurationDays }}" {{ $isEdit ? 'disabled' : 'data-parsley-required=true' }}>
        </div>
    </div>

    {{-- 4. Allow Video Ads Upload (Ad Packages only) --}}
    @if ($showVideoSection)
        <div class="pkg-section">
            <label class="pkg-toggle-card" for="is_reel_allowed">
                <span>
                    <span class="pkg-toggle-title">{{ __('Allow Video Ads Upload') }}</span>
                    <span class="pkg-toggle-help">{{ __('Users with this package can upload a video ad.') }}</span>
                </span>
                <input class="form-check-input" type="checkbox" @unless ($isEdit) name="is_reel_allowed" @endunless id="is_reel_allowed" value="1"
                       @checked($isEdit && $package->is_reel_allowed) {{ $locked }}>
            </label>
        </div>
    @endif

    {{-- 5. Key Points --}}
    <div class="pkg-section">
        @foreach ($languages as $lang)
            <div class="pkg-lang-pane {{ $lang->id == $defaultLang->id ? 'active' : '' }}" data-lang="{{ $lang->id }}">
                <h5 class="pkg-section-title">{{ __('Key Points') }} ({{ $lang->name }})</h5>
            </div>
        @endforeach
        @include('packages.partials.lang-tabs')
        @foreach ($languages as $lang)
            <div class="pkg-lang-pane {{ $lang->id == $defaultLang->id ? 'active' : '' }}" data-lang="{{ $lang->id }}">
                <div class="pkg-key-points" data-lang-id="{{ $lang->id }}">
                    @foreach ($keyPointsFor($lang->id) as $point)
                        <div class="pkg-key-point">
                            <input type="text" name="key_points[{{ $lang->id }}][]" class="form-control" value="{{ $point }}"
                                   placeholder="{{ $loop->first ? __('Enter key point') : __('e.g. Priority support') }}">
                            <button type="button" class="btn pkg-key-point-remove" aria-label="{{ __('Remove') }}"><i class="ph ph-x"></i></button>
                        </div>
                    @endforeach
                </div>
                <button type="button" class="btn pkg-key-point-add" data-placeholder="{{ __('e.g. Priority support') }}"><i class="ph ph-plus"></i> {{ __('Add Key Point') }}</button>
            </div>
        @endforeach
    </div>

    {{-- 6. Icon --}}
    <div class="pkg-section">
        <label class="form-label" for="icon">{{ __('Icon') }} @unless ($isEdit) <span class="text-danger">*</span> @endunless</label>
        <div class="pkg-dropzone {{ $isEdit && $package->icon ? 'has-file' : '' }}">
            {{-- The input covers the zone (transparent): click and drag-and-drop both land on it,
                 and Parsley still validates it (it skips display:none inputs). --}}
            <input type="file" name="icon" id="icon" accept=".jpg,.jpeg,.png" class="pkg-dropzone-input"
                   @unless ($isEdit) data-parsley-required="true" @endunless data-parsley-errors-container="#pkg_icon_error">
            <div class="pkg-dropzone-empty">
                <i class="ph-fill ph-cloud-arrow-up pkg-dropzone-icon"></i>
                <div><span class="pkg-dropzone-link">{{ __('Choose a file') }}</span> {{ __('or drag and drop') }}</div>
                <small class="text-muted">PNG, JPG · 256 × 256 {{ __('recommended') }}</small>
            </div>
            <div class="pkg-dropzone-preview">
                <img src="{{ $isEdit ? $package->icon : '' }}" alt="">
                <small class="text-muted pkg-dropzone-name">{{ __('Click or drop to replace') }}</small>
            </div>
        </div>
        <div id="pkg_icon_error"></div>
    </div>

    {{-- 7. Category Scope (types that support it — Ad Packages) --}}
    @if ($supportsCategoryScope)
        @if ($isEdit)
            <input type="hidden" name="is_global" value="{{ $package->is_global }}">
        @endif
        @include('category.category-scope', [
            'categories' => $categories,
            'selected_categories' => $selected_categories,
            'selected_all_categories' => $selected_all_categories,
            'show_global' => true,
            'global_id' => 'is_global',
            'global_checked' => $isEdit && $package->is_global,
            'global_label' => __('Global (Apply to All Categories)'),
            'wrapper_id' => 'category_selection_scope',
            'subtitle' => __('Select the categories where this Package Should be Applied'),
            'disabled' => $isEdit,
            'auto_expand' => $isEdit,
        ])
    @endif
</div>
