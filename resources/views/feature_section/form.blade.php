{{--
    Shared by create.blade.php and edit.blade.php ($featureSection is null on create).
    Sections: Basic Information → Module & Category → Content Rule → Appearance & Style →
    Display Settings → Section Status. Same building blocks as the category / slider forms
    (.category-form cat-* + .ec-subhead); fs-* blocks are this form's own (custom.css).
    The content rule and its values (price range, chosen items, category_criteria categories)
    cannot be changed after creation. `category_id[]` is the category list of category_criteria
    and the optional Category Filter Scope of the other rules (stored in `value`).
--}}
@php
    $isEdit = isset($featureSection);
    $filter = $isEdit ? $featureSection->filter : '';
    $langDir = fn ($lang) => $lang->rtl ? 'rtl' : 'ltr';
    $defaultLangId = $languages->firstWhere('id', 1)?->id ?? $languages->first()?->id;
    // Edit: saved categories (category_criteria or the scope) are pre-selected; locked only for category_criteria.
    $scopeIds = $isEdit && $filter !== 'item_selection' ? array_values($valueIds) : [];
    $limits = [4, 5, 6, 8, 10, 12, 16, 20];
    $itemsLimit = $isEdit ? (int) $featureSection->items_limit : 12;
    $isActive = $isEdit ? (bool) $featureSection->status : true;
@endphp

{{-- BASIC INFORMATION --}}
<h6 class="ec-subhead">{{ __('Basic Information') }}</h6>
<x-lang-pills class="mb-3" :languages="$languages" select="fs-language-selector" :selected="$defaultLangId" />
<div class="row mb-2">
    <div class="col-md-6 mb-3">
        @foreach ($languages as $lang)
            <div class="fs-lang-pane" data-lang-id="{{ $lang->id }}" @if ($lang->id != $defaultLangId) style="display: none;" @endif>
                <input type="hidden" name="languages[]" value="{{ $lang->id }}">
                <label class="form-label" for="fs-title-{{ $lang->id }}">{{ __('Section Title') }} ({{ $lang->name }})
                    @if ($lang->id == 1)<span class="text-danger">*</span>@endif
                </label>
                <input type="text" name="title[{{ $lang->id }}]" id="fs-title-{{ $lang->id }}" dir="{{ $langDir($lang) }}"
                       class="form-control @if ($lang->id == 1) feature-section-name @endif"
                       placeholder="{{ __('e.g., Top Cars Near You') }}"
                       value="{{ $translations[$lang->id]['name'] ?? '' }}"
                       @if ($lang->id == 1) data-parsley-required="true" @endif>
            </div>
        @endforeach
    </div>
    <div class="col-md-6 mb-3">
        <label for="slug" class="form-label">{{ __('Slug') }} <span class="text-danger">*</span></label>
        <input type="text" name="slug" id="slug" dir="ltr" class="form-control ltr feature-section-slug" placeholder="{{ __('e.g., top-cars-near-you') }}"
               value="{{ $isEdit ? $featureSection->slug : '' }}" data-parsley-required="true">
    </div>
</div>

{{-- MODULE & CATEGORY --}}
<h6 class="ec-subhead">{{ $modules->count() > 1 ? __('Module & Category') : __('Category') }}</h6>
<div class="row mb-2">
    @if ($modules->count() > 1)
        <div class="col-md-6 mb-3">
            <label for="module_id" class="form-label">{{ __('Module') }} <span class="text-danger">*</span></label>
            <select name="module_id" id="module_id" class="form-select" data-parsley-required="true">
                <option value="">{{ __('Select a Module') }}</option>
                @foreach ($modules as $module)
                    <option value="{{ $module->id }}" @selected($isEdit ? $featureSection->module_id == $module->id : $module->isDefault())>{{ $module->name }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <div class="col-md-6 mb-3 fs-category-field">
        <label for="category_id" class="form-label">
            <span class="fs-category-label" data-scope="{{ __('Category Filter Scope') }}" data-required="{{ __('Categories') }}">{{ __('Category Filter Scope') }}</span>
            <span class="text-danger fs-category-required" style="display: none;">*</span>
        </label>
        @if ($isEdit && $filter === 'category_criteria')
            <small class="text-muted d-block mb-1"><i class="ph-fill ph-lock-simple"></i> {{ __('Category selection cannot be changed after creation.') }}</small>
        @endif
        <select name="category_id[]" id="category_id" class="form-control select-search" multiple data-placeholder="{{ __('All Categories') }}" data-category-module="{{ $categoryModuleId }}"
                data-parsley-errors-container="#fs-category-errors" data-parsley-required-message="{{ __('Please select at least one category.') }}"
                @if ($scopeIds) data-selected='@json($scopeIds)' @endif
                @if ($isEdit && $filter === 'category_criteria') data-locked @endif>
            @include('category.dropdowntree', ['categories' => $categories])
        </select>
        <small class="text-muted d-block mt-1 fs-category-help" data-scope="{{ __('Leave empty to include listings from all categories.') }}"
               data-required="{{ __('Listings from these categories (and their subcategories) appear in this section.') }}">{{ __('Leave empty to include listings from all categories.') }}</small>
        <div id="fs-category-errors"></div>
    </div>
</div>

{{-- CONTENT RULE --}}
<h6 class="ec-subhead">{{ __('Content Rule') }}</h6>
<div class="mb-3">
    <label for="filter" class="form-label">{{ __('Select Content Rule') }} <span class="text-danger">*</span></label>
    @if ($isEdit)
        <small class="text-muted d-block mb-1"><i class="ph-fill ph-lock-simple"></i> {{ __('The content rule and its values cannot be changed after creation.') }}</small>
    @endif
    <select id="filter" name="filter" class="form-select" data-parsley-required="true" @if ($isEdit) data-locked @endif>
        @unless ($isEdit)
            <option value="">{{ __('Select Content Rule') }}</option>
        @endunless
        @foreach (['most_liked' => __('Most Liked'), 'most_viewed' => __('Most Viewed'), 'price_criteria' => __('Price Criteria'), 'category_criteria' => __('Category Criteria'), 'featured_ads' => __('Featured Ads'), 'item_selection' => __('Item Selection')] as $value => $label)
            @if (! $isEdit || $filter === $value)
                <option value="{{ $value }}" @selected($filter === $value)>{{ $label }}</option>
            @endif
        @endforeach
    </select>
</div>

{{-- Most Viewed / Most Liked: which listings (stored in promotion_tier; empty = all) --}}
<div class="fs-criteria fs-panel mb-4" data-filter="most_viewed most_liked" @unless (in_array($filter, ['most_viewed', 'most_liked'])) style="display: none;" @endunless>
    <label for="promotion_tier" class="form-label">{{ __('Promotion Package Tier') }}</label>
    <select name="promotion_tier" id="promotion_tier" class="form-select">
        <option value="">{{ __('All Listings') }}</option>
        <option value="featured" @selected($isEdit && $featureSection->promotion_tier === 'featured')>{{ __('Premium Featured') }}</option>
        <option value="standard" @selected($isEdit && $featureSection->promotion_tier === 'standard')>{{ __('Standard') }}</option>
    </select>
    <small class="text-muted d-block mt-1">{{ __('Premium Featured: listings with an active feature-ad package. Standard: listings that are not featured.') }}</small>
</div>

{{-- Price Criteria --}}
<div class="fs-criteria fs-panel mb-4" data-filter="price_criteria" @if ($filter !== 'price_criteria') style="display: none;" @endif>
    <div class="row">
        <div class="col-md-6 mb-3 mb-md-0">
            <label for="min_price" class="form-label">{{ __('Minimum Price') }} <span class="text-danger">*</span></label>
            <input type="number" name="min_price" id="min_price" class="form-control" required min="1" placeholder="{{ __('e.g., 1000') }}"
                   value="{{ $isEdit ? $featureSection->min_price : '' }}" @readonly($isEdit)>
            <small class="text-muted d-block mt-1">{{ __('Items priced above this value') }}</small>
        </div>
        <div class="col-md-6">
            <label for="max_price" class="form-label">{{ __('Maximum Price') }} <span class="text-danger">*</span></label>
            <input type="number" name="max_price" id="max_price" class="form-control" required min="1" placeholder="{{ __('e.g., 500000') }}"
                   value="{{ $isEdit ? $featureSection->max_price : '' }}" @readonly($isEdit)
                   data-parsley-gt="#min_price" data-parsley-error-message="{{ __('Max Price should be Greater than Min Price') }}">
            <small class="text-muted d-block mt-1">{{ __('Items priced below this value') }}</small>
        </div>
    </div>
</div>

{{-- Item Selection --}}
<div class="fs-criteria fs-panel mb-4" data-filter="item_selection" @if ($filter !== 'item_selection') style="display: none;" @endif>
    @if ($isEdit)
        <label for="item_id" class="form-label">{{ __('Selected Items') }} <span class="badge bg-primary ms-1">{{ $selectedItems->count() }}</span></label>
        <select name="item_id[]" id="item_id" class="form-control select-search" multiple data-locked data-placeholder="{{ __('Select Items') }}">
            @foreach ($selectedItems as $item)
                <option value="{{ $item->id }}" selected>{{ $item->name }}</option>
            @endforeach
        </select>
    @else
        <div class="row">
            <div class="col-md-6 mb-3 mb-md-0">
                <label for="item_filter_category" class="form-label">
                    {{ __('Filter by Category') }}
                    <span id="item_loading_spinner" style="display:none"><i class="fa fa-spinner fa-spin text-primary ms-1"></i></span>
                </label>
                {{-- Options are copied from #category_id with item counts by the form script. --}}
                <select id="item_filter_category" class="form-control select-search" multiple data-placeholder="{{ __('Select Category') }}" data-category-module="{{ $categoryModuleId }}"></select>
                <small class="text-muted d-block mt-1">{{ __('Select categories to load their items') }}</small>
            </div>
            <div class="col-md-6">
                <label for="item_id" class="form-label">{{ __('Select Items') }} <span class="text-danger">*</span> <span id="item_count_badge" class="badge bg-primary ms-1" style="display:none">0</span></label>
                <select name="item_id[]" id="item_id" class="form-control select-search" multiple required data-placeholder="{{ __('Select Items') }}"
                        data-parsley-errors-container="#fs-item-errors" data-parsley-required-message="{{ __('Please select at least one item.') }}"></select>
                <small class="text-muted d-block mt-1">{{ __('Choose specific items to show in this section') }}</small>
                <div id="fs-item-errors"></div>
            </div>
        </div>
    @endif
</div>

{{-- APPEARANCE & STYLE: the four app section styles --}}
<h6 class="ec-subhead">{{ __('Appearance & Style') }}</h6>
<p class="text-muted small mb-3">{{ __('Select how listings should be displayed in the app.') }}</p>
<div class="fs-style-grid mb-2">
    {{-- Display names for the admin only; the stored value stays style_1 … style_4. --}}
    @foreach (['style_1' => __('Spotlight Carousel'), 'style_2' => __('Compact Carousel'), 'style_3' => __('Two-Column Grid'), 'style_4' => __('Photo Carousel')] as $style => $styleName)
        <label class="fs-style-card">
            <input type="radio" name="style" value="{{ $style }}" class="visually-hidden" required
                   data-parsley-errors-container="#fs-style-errors" data-parsley-required-message="{{ __('Please select a style.') }}"
                   @checked($isEdit ? $featureSection->style === $style : $style === 'style_1')>
            <span class="fs-style-check" aria-hidden="true"><i class="ph-bold ph-check"></i></span>
            <span class="fs-style-image"><img src="{{ asset('/images/app_styles/' . $style . '.png') }}" alt="{{ $styleName }}"></span>
            <strong>{{ $styleName }}</strong>
        </label>
    @endforeach
</div>
<div id="fs-style-errors" class="mb-4"></div>

{{-- DISPLAY SETTINGS --}}
<h6 class="ec-subhead">{{ __('Display Settings') }}</h6>
<div class="row mb-4">
    <div class="col-md-6">
        <label for="items_limit" class="form-label">{{ __('Number of Items to Display') }}</label>
        <select name="items_limit" id="items_limit" class="form-select">
            @foreach (array_unique(array_merge($limits, [$itemsLimit])) as $limit)
                <option value="{{ $limit }}" @selected($limit === $itemsLimit)>{{ __(':count Items', ['count' => $limit]) }}</option>
            @endforeach
        </select>
    </div>
</div>

{{-- SECTION STATUS --}}
<div class="fs-status-row">
    <div>
        <strong>{{ __('Section Status') }}</strong>
        <small id="fs-status-text" data-on="{{ __('Active sections are shown on the homepage.') }}"
               data-off="{{ __('Inactive sections will not be available for homepage display.') }}">{{ $isActive ? __('Active sections are shown on the homepage.') : __('Inactive sections will not be available for homepage display.') }}</small>
    </div>
    <div class="form-check form-switch m-0">
        <input type="hidden" name="status" id="fs-status" value="{{ $isActive ? 1 : 0 }}">
        <input class="form-check-input" type="checkbox" role="switch" id="fsStatusSwitch" @checked($isActive) aria-label="{{ __('Section Status') }}">
    </div>
</div>

<div class="form-actions d-flex justify-content-end gap-2 mt-4">
    <a href="{{ route('feature-section.index') }}" class="btn btn-light border fs-cancel-btn">{{ __('Cancel') }}</a>
    <button type="submit" class="btn btn-primary">{{ $isEdit ? __('Save Changes') : __('Save Section') }}</button>
</div>
