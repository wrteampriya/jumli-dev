@extends('layouts.main')

@section('title')
    {{ __('Edit Listing') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h4>@yield('title')</h4>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first"></div>
        </div>
    </div>
@endsection

@php
    // Shown for modules / categories without an image, or whose image fails to load.
    $placeholderImage = \App\Services\CachingService::getSystemSettings('placeholder_image') ?: asset('assets/images/logo/placeholder.png');
    $itemIsReel = ($item->item_type ?? 'normal') === 'reel';
    $itemVideo = $item->itemVideo;
    $currentVideoType = $itemVideo?->video_type ?? '';
    $currentVideoLink = $itemVideo?->video_link ?? '';
    $currentVideoFile = $itemVideo?->video_file ?? '';
    $currentVideoTypeLabel = match ($currentVideoType) {
        'youtube_link' => __('Youtube Link'),
        'vimeo_link' => __('Vimeo Link'),
        'other_link' => __('Other Link'),
        'file' => __('Custom'),
        default => __('None'),
    };
    $seoData = $seoTranslations ?? [];
    $translationFor = fn ($langId) => isset($translations) ? ($translations instanceof \Illuminate\Support\Collection ? $translations->get($langId) : ($translations[$langId] ?? null)) : null;
    // The edit reason is only asked (and required) for another user's listing — as update() checks.
    $needsEditReason = $item->user_id != auth()->id();
    $slugHost = parse_url(\App\Services\CachingService::getSystemSettings('web_url') ?: config('app.url'), PHP_URL_HOST) ?: '';
    $wizardSteps = [
        ['target' => 'module', 'label' => __('Module')],
        ['target' => 'format', 'label' => __('Format')],
        ['target' => 'categories', 'label' => __('Category')],
        ['target' => 'listing', 'label' => __('Basic Info')],
        ['target' => 'custom', 'label' => __('Listing Details'), 'id' => 'custom-tab-item', 'hidden' => true],
        ['target' => 'seo', 'label' => __('SEO Details')],
        ['target' => 'images', 'label' => __('Media')],
        ['target' => 'address', 'label' => __('Location')],
        ['target' => 'review', 'label' => __('Review')],
    ];
@endphp

@section('content')

    <section class="section">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('advertisement.update', $item->id) }}" class="edit-form listing-wizard" data-parsley-validate
                    data-pre-submit-function="validateAdvertisementUpdateForm" data-success-function="handleAdvertisementSuccess" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="id" value="{{ $item->id }}">
                    <input type="hidden" name="category_id" id="category_id" value="{{ $item->category_id }}">
                    <input type="hidden" name="item_type" id="item_type_input" value="{{ $item->item_type ?? 'normal' }}">
                    {{-- Only scopes the category list; an item's module comes from its category. --}}
                    <input type="hidden" id="module_id_input" value="{{ $itemModuleId }}">
                    {{-- Selected category path, read by the AI description generator. --}}
                    <span id="selected-category-breadcrumb" class="d-none">{{ collect($categoryTrail)->pluck('name')->join(', ') }}</span>

                    <x-wizard-stepper id="editItemTabs" :steps="$wizardSteps" />

                    <div class="tab-content">

                        {{-- 1. Module: parent of the ads type and category steps --}}
                        <div class="tab-pane fade show active" id="module">
                            <div class="ec-step-head">
                                <h2>{{ __('What are you listing?') }}</h2>
                                <p>{{ __('Choose the appropriate module for your advertisement.') }}</p>
                            </div>
                            <div class="ec-eyebrow">{{ __('Current Modules') }}</div>
                            <div class="ec-option-grid">
                                @forelse ($modules as $module)
                                    <x-option-card class="module-option" :title="$module->name" :description="$module->description"
                                        :image="$module->icon ?: $placeholderImage" :placeholder="$placeholderImage" :selected="(string) $itemModuleId === (string) $module->id"
                                        data-module-id="{{ $module->id }}" data-module-name="{{ $module->name }}" />
                                @empty
                                    <p class="text-muted">{{ __('No active modules found.') }}</p>
                                @endforelse
                            </div>

                            <div class="mt-4 d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-primary btn-next-tab" data-next-tab="format">{{ __('Continue') }} <i class="ph-bold ph-arrow-right ms-1"></i></button>
                            </div>
                        </div>

                        {{-- 2. Format: regular or video (reel) ad --}}
                        <div class="tab-pane fade" id="format">
                            <div class="ec-step-head">
                                <h2>{{ __('Advertisement Format') }}</h2>
                                <p>{{ __('Choose how your listing will be displayed to buyers.') }}</p>
                            </div>
                            <div class="ec-option-grid">
                                <x-option-card class="format-option" icon="ph-list-bullets" :title="__('Regular Ad Listing')"
                                    :description="__('Standard detailed listing format featuring a high-quality photo gallery.')"
                                    :selected="! $itemIsReel" data-type="normal" />
                                <x-option-card class="format-option" icon="ph-play-circle" :title="__('Video Ad Listing')"
                                    :description="__('Immersive vertical video format (Reels/Shorts style) to maximize views.')"
                                    :badge="__('High Engagement')" :selected="$itemIsReel" data-type="reel" />
                            </div>

                            <div class="mt-4 d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-light border btn-prev-tab" data-prev-tab="module"><i class="ph-bold ph-arrow-left me-1"></i> {{ __('Back') }}</button>
                                <button type="button" class="btn btn-primary btn-next-tab" data-next-tab="categories">{{ __('Continue') }} <i class="ph-bold ph-arrow-right ms-1"></i></button>
                            </div>
                        </div>

                        {{-- 3. Category: drill down to a leaf category of the module --}}
                        <div class="tab-pane fade" id="categories">
                            <div class="ec-step-head">
                                <h2>{{ __('Select Category') }}</h2>
                                <p>{{ __('Where does this belong in') }} <strong id="category-step-scope"></strong>?</p>
                            </div>

                            <div id="selected-category-line" class="ec-cat-selected d-none">
                                <i class="ph-fill ph-check-circle text-success"></i>
                                <span>{{ __('Selected') }}: <strong id="selected-category-text"></strong></span>
                                <button type="button" class="ec-link-btn ms-auto" id="category-change-btn">{{ __('Change') }}</button>
                            </div>

                            <div id="breadcrumb-container" class="ec-cat-nav" style="display: none;">
                                <button type="button" class="btn btn-sm btn-light border" id="go-back-category-btn">
                                    <i class="ph-bold ph-arrow-left me-1"></i>{{ __('Back') }}
                                </button>
                                <nav aria-label="breadcrumb">
                                    <ol class="breadcrumb" id="category-breadcrumb"></ol>
                                </nav>
                            </div>

                            <div class="ec-scroll-area" id="categories-scroll">
                                <div id="categories-list" class="ec-tile-grid"></div>
                                <div id="categories-sentinel" aria-hidden="true"></div>
                                <div id="load-more-container" class="text-center mt-3" style="display: none;">
                                    <button type="button" class="btn btn-light border" id="load-more-categories">{{ __('Load More') }}</button>
                                </div>
                            </div>

                            <div class="mt-4 d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-light border btn-prev-tab" data-prev-tab="format"><i class="ph-bold ph-arrow-left me-1"></i> {{ __('Back') }}</button>
                                <button type="button" class="btn btn-primary btn-next-tab" data-next-tab="listing">{{ __('Continue') }} <i class="ph-bold ph-arrow-right ms-1"></i></button>
                            </div>
                        </div>

                        {{-- 4. Basic Info: title / description (per language), contact, pricing --}}
                        <div class="tab-pane fade" id="listing">
                            <div class="ec-step-head">
                                <h2>{{ __('Basic Details') }}</h2>
                                <p>{{ __('The essential information required for every listing.') }}</p>
                            </div>

                            <x-form-section icon="ph-list-bullets" :title="__('Ads Details')" :subtitle="__('Provide the essential information for your advertisement.')">
                                <x-slot:actions>
                                    @if(($geminiAutoTranslateEnabled ?? false) && $languages->count() > 1)
                                        <button type="button" id="auto-translate-btn" class="ec-link-btn">
                                            <i class="ph-bold ph-translate"></i> {{ __('Auto Translate') }}
                                        </button>
                                    @endif
                                </x-slot:actions>

                                <x-lang-pills class="mb-3" :languages="$languages" select="details-language-selector" :selected="$defaultLanguage->id" />

                                {{-- Default language: always required --}}
                                <div class="language-fields default-language-fields" data-language-id="{{ $defaultLanguage->id }}">
                                    <div class="mb-3">
                                        <label class="form-label" for="name-input">{{ __('Listing Title') }} <span class="text-danger">*</span></label>
                                        <input type="text" name="name" id="name-input" value="{{ old('name', $item->name) }}" dir="{{ $defaultLanguage->rtl ? 'rtl' : 'ltr' }}"
                                            class="form-control" required placeholder="{{ __('e.g. iPhone 14 Pro Max - 256GB') }}">
                                    </div>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <label class="form-label" for="description-input">{{ __('Description') }} <span class="text-danger">*</span></label>
                                            @if($geminiEnabled ?? false)
                                                <button type="button" class="ec-link-btn mb-2 generate-description-btn">
                                                    <i class="ph-bold ph-magic-wand"></i> {{ __('Generate with AI') }}
                                                    <span class="spinner-border spinner-border-sm d-none description-loading"></span>
                                                </button>
                                            @endif
                                        </div>
                                        <textarea name="description" id="description-input" dir="{{ $defaultLanguage->rtl ? 'rtl' : 'ltr' }}" class="form-control" rows="5" required placeholder="{{ __('Provide a clear, detailed description...') }}">{{ old('description', $item->description) }}</textarea>
                                    </div>
                                </div>

                                {{-- Other languages: title and description only, optional --}}
                                @foreach ($languages as $lang)
                                    @if ($lang->id != $defaultLanguage->id)
                                        @php
                                            $translation = $translationFor($lang->id);
                                        @endphp
                                        <div class="language-fields other-language-fields" data-language-id="{{ $lang->id }}" style="display: none;">
                                            <div class="mb-3">
                                                <label class="form-label">{{ __('Listing Title') }} ({{ $lang->name }})</label>
                                                <input type="text" name="translations[{{ $lang->id }}][name]" dir="{{ $lang->rtl ? 'rtl' : 'ltr' }}"
                                                    class="form-control translation-name" data-lang-id="{{ $lang->id }}"
                                                    value="{{ $translation->name ?? '' }}"
                                                    placeholder="{{ __('e.g. iPhone 14 Pro Max - 256GB') }}">
                                            </div>
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <label class="form-label">{{ __('Description') }} ({{ $lang->name }})</label>
                                                    @if($geminiEnabled ?? false)
                                                        <button type="button" class="ec-link-btn mb-2 generate-description-btn">
                                                            <i class="ph-bold ph-magic-wand"></i> {{ __('Generate with AI') }}
                                                            <span class="spinner-border spinner-border-sm d-none description-loading"></span>
                                                        </button>
                                                    @endif
                                                </div>
                                                <textarea name="translations[{{ $lang->id }}][description]" dir="{{ $lang->rtl ? 'rtl' : 'ltr' }}"
                                                    class="form-control translation-description" data-lang-id="{{ $lang->id }}" rows="5"
                                                    placeholder="{{ __('Provide a clear, detailed description...') }}">{{ $translation->description ?? '' }}</textarea>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach

                                <div class="mb-3">
                                    <label class="form-label" for="contact-input">{{ __('Contact Number') }}</label>
                                    <input type="tel" name="contact" id="contact-input"
                                        value="{{ old('contact', $item->contact) }}" class="form-control"
                                        placeholder="{{ __('Enter phone number') }}">
                                    <input type="hidden" name="country_code" id="country-code-input" value="{{ old('country_code', $item->country_code ?? '') }}">
                                    <input type="hidden" name="region_code" id="region-code-input" value="{{ old('region_code', $item->region_code ?? '') }}">
                                </div>

                                <div>
                                    <label class="form-label">{{ __('Do you want to show or hide your phone number?') }}</label>
                                    <div class="ec-choice-row">
                                        <label class="ec-choice">
                                            <input class="form-check-input" type="radio" name="show_mobile_number" value="0" @checked((string) old('show_mobile_number', (int) $item->show_mobile_number) === '0')>
                                            {{ __('Hide Phone') }}
                                        </label>
                                        <label class="ec-choice">
                                            <input class="form-check-input" type="radio" name="show_mobile_number" value="1" @checked((string) old('show_mobile_number', (int) $item->show_mobile_number) === '1')>
                                            {{ __('Show Phone') }}
                                        </label>
                                    </div>
                                </div>
                            </x-form-section>

                            <x-form-section icon="ph-currency-circle-dollar" :title="__('Pricing')" :subtitle="__('Set the currency and price for your listing.')">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="currency">{{ __('Currency') }}</label>
                                        <select class="form-control select2" id="currency" name="currency_id">
                                            @foreach ($currencies as $currency)
                                                <option value="{{ $currency->id }}" data-iso-code="{{ $currency->iso_code ?? '' }}" data-symbol="{{ $currency->symbol ?? '' }}" @selected($item->currency_id == $currency->id)>{{ $currency->name }}{{ $currency->symbol ? ' (' . $currency->symbol . ')' : '' }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    {{-- Options come from the category's module (pricing_modes) — see items/partials/module-rules. --}}
                                    <div class="col-md-6 mb-3" id="pricing-mode-field">
                                        <label class="form-label" for="pricing-mode-select">{{ __('Pricing') }} <span class="text-danger">*</span></label>
                                        <select name="pricing_mode" id="pricing-mode-select" class="form-select" data-current="{{ old('pricing_mode', $item->pricing_mode) }}"></select>
                                    </div>

                                    <div class="col-12 mb-3" id="price-field">
                                        <label class="form-label" id="price-label" for="price-input">{{ __('Price') }} <span class="text-danger">*</span></label>
                                        {{-- Visibility / required / labels are set by applyPricingMode() on load. --}}
                                        <input type="number" name="price" id="price-input" value="{{ old('price', $item->price) }}"
                                            class="form-control" placeholder="{{ __('Enter price') }}">
                                    </div>

                                    <div class="col-12 mb-3" id="salary-fields" style="display: none;">
                                        <div class="row">
                                            {{-- Range mode reuses min_salary / max_salary as the price range. --}}
                                            <div class="col-md-6 mb-2">
                                                <label class="form-label" id="min-salary-label" for="min-salary-input">{{ __('Min Salary') }}</label>
                                                <input type="number" name="min_salary" id="min-salary-input" class="form-control"
                                                    value="{{ old('min_salary', $item->min_salary ?? '') }}" placeholder="{{ __('Enter minimum salary') }}">
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <label class="form-label" id="max-salary-label" for="max-salary-input">{{ __('Max Salary') }}</label>
                                                <input type="number" name="max_salary" id="max-salary-input" class="form-control"
                                                    value="{{ old('max_salary', $item->max_salary ?? '') }}" placeholder="{{ __('Enter maximum salary') }}">
                                            </div>
                                        </div>
                                        <small class="text-muted d-block">{{ __('Enter a minimum, a maximum, or both.') }}</small>
                                    </div>
                                </div>
                            </x-form-section>

                            <div class="mt-4 d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-light border btn-prev-tab" data-prev-tab="categories"><i class="ph-bold ph-arrow-left me-1"></i> {{ __('Back') }}</button>
                                <button type="button" class="btn btn-primary btn-next-tab" data-next-tab="custom-or-seo">{{ __('Continue') }} <i class="ph-bold ph-arrow-right ms-1"></i></button>
                            </div>
                        </div>

                        {{-- 5. Listing Details: the category's custom fields (rendered by loadCustomFields) --}}
                        <div class="tab-pane fade" id="custom">
                            <div class="text-muted">{{ __('Select a category to load custom fields.') }}</div>
                        </div>

                        {{-- 6. SEO Details --}}
                        <div class="tab-pane fade" id="seo">
                            <div class="ec-step-head">
                                <h2>{{ __('SEO Details') }}</h2>
                                <p>{{ __('Optimize how your listing appears in search engines.') }}</p>
                            </div>

                            <x-form-section icon="ph-magnifying-glass" :title="__('SEO Details')">
                                <x-slot:actions>
                                    @if($geminiEnabled ?? false)
                                        <button type="button" class="ec-link-btn" id="generate-meta-btn">
                                            <i class="ph-bold ph-magic-wand"></i> {{ __('Generate with AI') }}
                                            <span class="spinner-border spinner-border-sm d-none" id="meta-loading"></span>
                                        </button>
                                    @endif
                                </x-slot:actions>

                                <x-lang-pills class="mb-3" :languages="$languages" select="seo-language-selector" :selected="$defaultLanguage->id" />

                                @foreach ($languages->sortByDesc(fn ($lang) => $lang->id == $defaultLanguage->id) as $lang)
                                    @php
                                        $isDefaultSeo = $lang->id == $defaultLanguage->id;
                                    @endphp
                                    <div class="seo-language-fields" data-seo-language-id="{{ $lang->id }}" @unless($isDefaultSeo) style="display: none;" @endunless>
                                        <div class="mb-3">
                                            <label class="form-label">{{ __('Meta Title') }}@unless($isDefaultSeo) ({{ $lang->name }})@endunless</label>
                                            <input type="text" name="meta_title[{{ $lang->id }}]" dir="{{ $lang->rtl ? 'rtl' : 'ltr' }}" class="form-control" value="{{ $seoData[$lang->id]['meta_title'] ?? '' }}" placeholder="{{ __('Enter meta title') }}">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">{{ __('Meta Description') }}@unless($isDefaultSeo) ({{ $lang->name }})@endunless</label>
                                            <textarea name="meta_description[{{ $lang->id }}]" dir="{{ $lang->rtl ? 'rtl' : 'ltr' }}" class="form-control" rows="3" placeholder="{{ __('Enter meta description') }}">{{ $seoData[$lang->id]['meta_description'] ?? '' }}</textarea>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">{{ __('Keywords') }}@unless($isDefaultSeo) ({{ $lang->name }})@endunless</label>
                                            {{-- Tagify; submitted as a comma-separated string, as before. --}}
                                            <input type="text" name="meta_keywords[{{ $lang->id }}]" dir="{{ $lang->rtl ? 'rtl' : 'ltr' }}" class="form-control seo-keywords-input" value="{{ $seoData[$lang->id]['meta_keywords'] ?? '' }}" placeholder="{{ __('Type and press enter') }}">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">{{ __('Schema') }}@unless($isDefaultSeo) ({{ $lang->name }})@endunless</label>
                                            <textarea name="schema[{{ $lang->id }}]" dir="ltr" class="form-control font-monospace" rows="4" placeholder='{"@@context": "https://schema.org", ...}'>{{ $seoData[$lang->id]['schema'] ?? '' }}</textarea>
                                            <small class="text-muted d-block mt-1">
                                                <i class="ph ph-info"></i>
                                                {{ __('Schema is not auto-generated by AI. Add JSON-LD schema manually using the saved item data (image URLs, price, etc.) which are now available.') }}
                                            </small>
                                        </div>
                                    </div>
                                @endforeach

                                <div>
                                    <label class="form-label" for="slug-input">{{ __('URL Slug') }}</label>
                                    {{-- URLs always read LTR, also in RTL layouts (corners fixed in custom.css). --}}
                                    <div class="input-group ec-input-prefix" dir="ltr">
                                        @if ($slugHost)
                                            <span class="input-group-text" dir="ltr">{{ $slugHost }}/</span>
                                        @endif
                                        <input type="text" name="slug" id="slug-input" dir="ltr" value="{{ old('slug', $item->slug) }}" class="form-control"
                                            placeholder="{{ __('your-listing-slug') }}">
                                    </div>
                                    <small class="text-muted d-block mt-1">{{ __('Optional. Lowercase letters, numbers and hyphens; generated when left empty.') }}</small>
                                </div>
                            </x-form-section>

                            <div class="mt-4 d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-light border btn-prev-tab" data-prev-tab="custom-or-listing"><i class="ph-bold ph-arrow-left me-1"></i> {{ __('Back') }}</button>
                                <button type="button" class="btn btn-primary btn-next-tab" data-next-tab="images">{{ __('Continue') }} <i class="ph-bold ph-arrow-right ms-1"></i></button>
                            </div>
                        </div>

                        {{-- 7. Media --}}
                        <div class="tab-pane fade" id="images">
                            <div class="ec-step-head">
                                <h2>{{ __('Media Upload') }}</h2>
                                <p>{{ __('High-quality images and video increase engagement.') }}</p>
                            </div>

                            <div class="ec-media-grid mb-4">
                                {{-- VIDEO ADS (reel only) --}}
                                <x-form-section id="reel-video-section" :style="$itemIsReel ? null : 'display:none;'" icon="ph-video-camera" :title="__('Video Ad')"
                                    :subtitle="__('Upload a short video to showcase your item.')">
                                    <input type="hidden" name="delete_reel" id="reel-delete-flag" value="0">

                                    @if ($item->reel && $item->reel->video)
                                        <div id="reel-existing-wrap" class="mb-2">
                                            <div class="media-file-card" id="reel-existing-card">
                                                @if ($item->reel->thumbnail)
                                                    <img src="{{ $item->reel->thumbnail }}" alt="" style="width:48px;height:64px;object-fit:cover;border-radius:4px;flex-shrink:0;">
                                                @else
                                                    <span class="d-grid place-items-center flex-shrink-0" style="width:48px;height:64px;background:#111;border-radius:4px;place-items:center;">
                                                        <i class="ph-bold ph-video" style="color:#fff;font-size:20px;"></i>
                                                    </span>
                                                @endif
                                                <div class="flex-grow-1 ms-2 overflow-hidden">
                                                    <div class="fw-semibold small" id="reel-existing-title">{{ __('Current Video Ad') }}</div>
                                                    <small class="text-muted" id="reel-existing-subtitle">{{ __('Upload below to replace') }}</small>
                                                </div>
                                                <a href="{{ $item->reel->video }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary ms-2">{{ __('Preview') }}</a>
                                            </div>
                                        </div>
                                    @endif

                                    <div id="reel-video-upload-area" class="ec-dropzone">
                                        <div class="upload-instructions w-100">
                                            <div class="ec-dropzone-icon"><i class="ph-fill ph-cloud-arrow-up"></i></div>
                                            <p>{{ __('Drag & Drop your video here') }}</p>
                                            <p class="ec-dropzone-or">{{ __('or') }}</p>
                                            <a href="#" class="ec-dropzone-link" onclick="event.preventDefault();">{{ __('Upload Video') }}</a>
                                        </div>
                                        <div class="upload-loading-state w-100" style="display:none;">
                                            <div class="spinner-border text-primary mb-2" role="status"></div>
                                            <p class="mb-1 text-muted fw-semibold reel-upload-status-text">{{ __('Loading...') }}</p>
                                            <div class="progress progress-sm" style="width: 80%; margin: 0 auto; display: none;">
                                                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                                            </div>
                                        </div>
                                        <input type="file" id="reel-video-input" name="reel_video" class="d-none" accept="video/mp4">
                                    </div>
                                    <div class="d-flex justify-content-between mt-2">
                                        <small class="text-muted">{{ __('Maximum Size') }} : {{ \App\Services\CachingService::getSystemSettings('reel_max_file_size_mb') ?: 50 }} MB</small>
                                        <small class="text-muted">{{ __('Recommended Video Ratio') }} : 9:16</small>
                                    </div>

                                    {{-- Selected (new) reel file card --}}
                                    <div id="reel-video-selected" style="display:none;" class="mt-2">
                                        <div class="media-file-card">
                                            <canvas id="reel-thumb-preview-small" width="48" height="64" style="border-radius:4px;flex-shrink:0;background:#111;"></canvas>
                                            <div class="flex-grow-1 overflow-hidden ms-2">
                                                <div class="fw-semibold text-truncate small" id="reel-video-filename"></div>
                                                <small class="text-muted" id="reel-video-duration"></small>
                                                <small id="reel-trim-badge" style="display:none;" class="text-muted"></small>
                                            </div>
                                            <div class="d-flex gap-2 flex-shrink-0 ms-2">
                                                <button type="button" class="btn btn-sm btn-outline-primary px-3" id="reel-edit-video-btn">{{ __('Edit') }}</button>
                                                <button type="button" class="btn btn-sm btn-outline-danger px-3" id="reel-video-change-btn">{{ __('Delete') }}</button>
                                            </div>
                                        </div>
                                        <input type="hidden" name="reel_thumbnail_time" id="reel-thumbnail-time" value="0">
                                        <input type="hidden" name="reel_thumbnail_custom" id="reel-thumbnail-custom" value="">
                                        <input type="hidden" name="reel_thumbnail_data" id="reel-thumbnail-data" value="">
                                    </div>
                                </x-form-section>

                                {{-- AD IMAGES: existing images + new uploads (first one is the cover) --}}
                                <x-form-section id="product-images-col" :class="$itemIsReel ? null : 'ec-media-full'" icon="ph-image" :title="__('Ad Images')"
                                    :subtitle="__('Upload clear and high-quality photos of your item. The first image is the cover.')">
                                    <div class="ec-dropzone" id="gallery-images-upload" onclick="document.getElementById('gallery-images-input').click()">
                                        <div class="ec-dropzone-icon"><i class="ph-fill ph-cloud-arrow-up"></i></div>
                                        <p>{{ __('Drag & Drop your photos here') }}</p>
                                        <p class="ec-dropzone-or">{{ __('or') }}</p>
                                        <a href="#" class="ec-dropzone-link" onclick="event.preventDefault();event.stopPropagation();document.getElementById('gallery-images-input').click()">{{ __('Upload Images') }}</a>
                                        <input type="file" name="gallery_images[]" id="gallery-images-input" class="d-none" multiple accept="image/png,image/jpeg,image/jpg">
                                    </div>

                                    {{-- Existing + new images --}}
                                    <div id="gallery-images-preview" class="mt-3 d-flex flex-nowrap gap-2" style="overflow: hidden; width: 100%;"></div>

                                    {{-- Hidden checkboxes: existing images marked for deletion --}}
                                    <div id="existing-images-checkboxes" class="d-none">
                                        @foreach ($item->gallery_images as $img)
                                            <input type="checkbox" name="delete_item_image_id[]" id="delete-img-{{ $img->id }}" value="{{ $img->id }}" class="delete-existing-img-cb">
                                        @endforeach
                                    </div>
                                </x-form-section>

                                {{-- PRODUCT VIDEO --}}
                                <x-form-section id="product-video-section" class="ec-media-full" icon="ph-link" :title="__('Product Video')"
                                    :subtitle="__('Add a custom video or paste a YouTube link.')">
                                    <x-slot:actions>
                                        <div class="dropdown">
                                            <button class="ec-video-type-btn dropdown-toggle" type="button" id="videoTypeDropdownBtn" data-bs-toggle="dropdown" aria-expanded="false">
                                                <span id="videoTypeLabel">{{ $currentVideoTypeLabel }}</span>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="videoTypeDropdownBtn">
                                                <li><a class="dropdown-item" href="#" data-video-type="" onclick="setVideoType('','{{ __('None') }}');return false;">{{ __('None') }}</a></li>
                                                <li><a class="dropdown-item" href="#" data-video-type="youtube_link" onclick="setVideoType('youtube_link','{{ __('Youtube Link') }}');return false;">{{ __('Youtube Link') }}</a></li>
                                                <li><a class="dropdown-item" href="#" data-video-type="vimeo_link" onclick="setVideoType('vimeo_link','{{ __('Vimeo Link') }}');return false;">{{ __('Vimeo Link') }}</a></li>
                                                <li><a class="dropdown-item" href="#" data-video-type="other_link" onclick="setVideoType('other_link','{{ __('Other Link') }}');return false;">{{ __('Other Link') }}</a></li>
                                                <li><a class="dropdown-item" href="#" data-video-type="file" onclick="setVideoType('file','{{ __('Custom') }}');return false;">{{ __('Custom') }}</a></li>
                                            </ul>
                                        </div>
                                        <input type="hidden" name="video_type" id="video_type_hidden" value="{{ $currentVideoType }}">
                                        <input type="hidden" name="delete_item_video" id="delete-item-video-flag" value="0">
                                    </x-slot:actions>

                                    <p class="text-muted small mb-0" id="item-video-none-hint" @if ($currentVideoType) style="display:none;" @endif>{{ __('Choose a video type to add a product video.') }}</p>

                                    <div id="item-video-link-wrap" @unless (in_array($currentVideoType, ['youtube_link', 'vimeo_link', 'other_link'])) style="display:none;" @endunless>
                                        <div class="ec-video-link-row">
                                            <input type="url" name="video_link" id="item-video-link-input" dir="ltr" class="form-control ignore"
                                                value="{{ in_array($currentVideoType, ['youtube_link', 'vimeo_link', 'other_link']) ? $currentVideoLink : '' }}"
                                                placeholder="{{ __('Enter URL...') }}">
                                            <button type="button" class="btn btn-light" title="{{ __('Remove') }}" onclick="setVideoType('','{{ __('None') }}')"><i class="ph-bold ph-x"></i></button>
                                        </div>
                                    </div>

                                    <div id="item-video-file-wrap" @unless ($currentVideoType === 'file') style="display:none;" @endunless>
                                        @if ($currentVideoType === 'file' && $currentVideoFile)
                                            <div class="media-file-card mb-2" id="item-video-existing-file-card">
                                                <i class="ph-bold ph-file-video" style="font-size:28px;color:var(--bs-primary);flex-shrink:0;"></i>
                                                <div class="flex-grow-1 ms-2"><small class="text-muted">{{ __('Uploaded file') }}</small></div>
                                                <a href="{{ $currentVideoFile }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary me-2">{{ __('Preview') }}</a>
                                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeExistingItemVideo()">{{ __('Remove') }}</button>
                                            </div>
                                        @endif
                                        <div class="ec-dropzone" onclick="document.getElementById('item-video-file-input').click()">
                                            <div class="ec-dropzone-icon"><i class="ph-fill ph-cloud-arrow-up"></i></div>
                                            <p>{{ __('Drag & Drop your video here or') }} <a href="#" class="ec-dropzone-link" onclick="event.preventDefault();event.stopPropagation();document.getElementById('item-video-file-input').click()">{{ __('Upload') }}</a></p>
                                            <input type="file" name="item_video" id="item-video-file-input" class="d-none"
                                                accept="video/mp4,video/webm,video/avi,video/quicktime"
                                                onchange="showItemVideoFilePreview(this)">
                                        </div>
                                        <small class="text-muted d-block mt-1">{{ __('Max size') }}: <span class="item-video-max-size">50</span> MB</small>
                                        <div id="item-video-file-selected" style="display:none;" class="mt-2">
                                            <div class="media-file-card">
                                                <i class="ph-bold ph-file-video" style="font-size:32px;color:var(--bs-primary);flex-shrink:0;"></i>
                                                <div class="flex-grow-1 ms-2 overflow-hidden">
                                                    <div class="fw-semibold text-truncate small" id="item-video-file-name"></div>
                                                    <small class="text-muted" id="item-video-file-size"></small>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="clearItemVideoFile()">{{ __('Delete') }}</button>
                                            </div>
                                        </div>
                                    </div>
                                </x-form-section>
                            </div>

                            @include('items.partials.reel-edit-modal')

                            <div class="mt-4 d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-light border btn-prev-tab" data-prev-tab="seo"><i class="ph-bold ph-arrow-left me-1"></i> {{ __('Back') }}</button>
                                <button type="button" class="btn btn-primary btn-next-tab" data-next-tab="address">{{ __('Continue') }} <i class="ph-bold ph-arrow-right ms-1"></i></button>
                            </div>
                        </div>

                        {{-- 8. Location --}}
                        <div class="tab-pane fade" id="address">
                            <div class="ec-step-head">
                                <h2>{{ __('Select your location') }}</h2>
                                <p>{{ __('Choose where this listing is located.') }}</p>
                            </div>

                            <x-form-section icon="ph-map-pin" :title="__('Select your location')" :subtitle="__('Search, use your current location or pick a point on the map.')">
                                <div class="ec-location-search">
                                    <div class="ec-search-field">
                                        <i class="ph-bold ph-magnifying-glass ec-search-icon"></i>
                                        <input type="text" id="location-search" class="form-control" value="{{ $item->address }}" placeholder="{{ __('Select Location') }}">
                                        <div id="search-results" class="position-absolute w-100 bg-white border rounded mt-1"></div>
                                    </div>
                                    <button type="button" class="btn btn-primary" id="locate-me-btn">
                                        <i class="ph-bold ph-crosshair me-1"></i>{{ __('Locate me') }}
                                    </button>
                                </div>

                                <div id="map"
                                    data-map-provider="{{ $mapProvider ?? 'free_api' }}"
                                    data-google-map-key="{{ $googleMapKey ?? '' }}"
                                    data-settings-lat="{{ $defaultLatitude ?? '' }}"
                                    data-settings-lng="{{ $defaultLongitude ?? '' }}"
                                    data-item-lat="{{ $item->latitude ?? '' }}"
                                    data-item-lng="{{ $item->longitude ?? '' }}"
                                    data-get-location-url="{{ url('api/get-location') }}"
                                    data-locale="{{ app()->getLocale() }}"></div>

                                <div id="selected-address-display" class="ec-address-card" @unless ($item->address) style="display: none;" @endunless>
                                    <span class="ec-address-icon"><i class="ph-fill ph-map-pin"></i></span>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold text-dark">{{ __('Address') }}</div>
                                        <p class="mb-0 text-muted" id="selected-address-text">{{ $item->address }}</p>
                                    </div>
                                </div>

                                @if(($mapProvider ?? 'free_api') === 'free_api')
                                    <div class="text-center mt-3">
                                        <span class="text-muted">{{ __('Or') }}</span>
                                        <button type="button" class="ec-link-btn ms-1" id="add-location-btn" data-bs-toggle="modal" data-bs-target="#manualLocationModal">{{ __('Add Location manually') }}</button>
                                    </div>
                                @endif

                                {{-- Submitted values --}}
                                <input type="hidden" id="latitude-input" name="latitude" value="{{ $item->latitude ?? '' }}" />
                                <input type="hidden" id="longitude-input" name="longitude" value="{{ $item->longitude ?? '' }}" />
                                <input type="hidden" name="country_input" id="country-input" value="{{ $item->country ?? '' }}">
                                <input type="hidden" name="state_input" id="state-input" value="{{ $item->state ?? '' }}">
                                <input type="hidden" name="city_input" id="city-input" value="{{ $item->city ?? '' }}">
                                <input type="hidden" name="address" id="address-hidden" value="{{ $item->address ?? '' }}">
                            </x-form-section>

                            <div class="mt-4 d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-light border btn-prev-tab" data-prev-tab="images"><i class="ph-bold ph-arrow-left me-1"></i> {{ __('Back') }}</button>
                                <button type="button" class="btn btn-primary btn-next-tab" data-next-tab="review">{{ __('Continue') }} <i class="ph-bold ph-arrow-right ms-1"></i></button>
                            </div>
                        </div>

                        {{-- 9. Review & Publish (read-only summary, filled by renderListingReview) --}}
                        <div class="tab-pane fade" id="review">
                            <div class="ec-step-head">
                                <h2>{{ __('Review & Update') }}</h2>
                                <p>{{ __('Please verify all your changes carefully before updating the listing.') }}</p>
                            </div>

                            <div class="ec-status-banner" id="review-status">
                                <span class="ec-status-banner-icon"><i class="ph-bold ph-check"></i></span>
                                <div>
                                    <h5 id="review-status-title">{{ __('Listing Ready') }}</h5>
                                    <p id="review-status-text"></p>
                                </div>
                            </div>

                            <div class="ec-summary-grid">
                                <x-summary-card :title="__('Overview')" edit="module">
                                    <div class="ec-summary-row"><span>{{ __('Module') }}</span><strong data-review="module"></strong></div>
                                    <div class="ec-summary-row"><span>{{ __('Ad Format') }}</span><strong data-review="format"></strong></div>
                                    <div class="ec-summary-row"><span>{{ __('Category') }}</span><strong data-review="category"></strong></div>
                                </x-summary-card>
                                <x-summary-card :title="__('Basic Information')" edit="listing">
                                    <div class="ec-summary-row"><span>{{ __('Title') }}</span><strong data-review="title"></strong></div>
                                    <div class="ec-summary-row"><span>{{ __('Description') }}</span><strong data-review="description"></strong></div>
                                    <div class="ec-summary-row"><span>{{ __('Contact Phone') }}</span><strong data-review="contact" dir="ltr"></strong></div>
                                </x-summary-card>
                            </div>

                            <x-summary-card :title="__('Listing Details & Pricing')" edit="listing">
                                <div class="ec-summary-row"><span>{{ __('Pricing Model') }}</span><strong data-review="pricing"></strong></div>
                                <div class="ec-summary-row"><span>{{ __('Amount') }}</span><strong data-review="amount" dir="ltr"></strong></div>
                                <div class="ec-summary-row" data-review-row="custom"><span>{{ __('Core Information') }}</span><strong data-review="core"></strong></div>
                                <div class="ec-summary-row" data-review-row="custom"><span>{{ __('Additional Details') }}</span><strong data-review="additional"></strong></div>
                            </x-summary-card>

                            <x-summary-card :title="__('Media, SEO & Location')" edit="images">
                                <div class="ec-summary-row"><span>{{ __('Ad Images') }}</span><strong data-review="images"></strong></div>
                                <div class="ec-summary-row" data-review-row="reel"><span>{{ __('Video Ad') }}</span><strong data-review="reel"></strong></div>
                                <div class="ec-summary-row"><span>{{ __('Product Video') }}</span><strong data-review="video"></strong></div>
                                <div class="ec-summary-row"><span>{{ __('Location') }}</span><strong data-review="location"></strong></div>
                                <div class="ec-summary-row"><span>{{ __('SEO Status') }}</span><strong data-review="seo"></strong></div>
                            </x-summary-card>

                            @if ($needsEditReason)
                                <x-form-section icon="ph-note-pencil" :title="__('Reason for Admin Edit')" :subtitle="__('Explain what was changed and why.')">
                                    <label class="form-label" for="admin_edit_reason">{{ __('Reason for Admin Edit') }} <span class="text-danger">*</span></label>
                                    <textarea name="admin_edit_reason" id="admin_edit_reason" class="form-control" rows="3" maxlength="1000" required>{{ old('admin_edit_reason', $item->admin_edit_reason) }}</textarea>
                                </x-form-section>
                            @endif

                            <div class="mt-4 d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-light border btn-prev-tab" data-prev-tab="address"><i class="ph-bold ph-arrow-left me-1"></i> {{ __('Back') }}</button>
                                <button type="submit" id="item-submit-btn" class="btn btn-primary">{{ __('Update Listing') }} <i class="ph-bold ph-check ms-1"></i></button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>

    @if(($geminiEnabled ?? false) && $languages->count() > 1)
    {{-- Auto Translate Language Picker Modal --}}
    <div class="modal fade" id="autoTranslateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-language me-2"></i>{{ __('Select Languages to Translate') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="at-error-alert" class="alert alert-danger d-none mb-2 py-2 small"></div>
                    <div class="mb-2">
                        <a href="#" id="at-select-all" class="small me-2">{{ __('Select All') }}</a>
                        <a href="#" id="at-deselect-all" class="small">{{ __('Deselect All') }}</a>
                    </div>
                    @foreach ($languages as $lang)
                        @if ($lang->id != $defaultLanguage->id)
                            <div class="form-check mb-1">
                                <input class="form-check-input at-lang-check" type="checkbox"
                                    id="at_lang_{{ $lang->id }}" value="{{ $lang->id }}" checked>
                                <label class="form-check-label" for="at_lang_{{ $lang->id }}">{{ $lang->name }}</label>
                            </div>
                        @endif
                    @endforeach
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="button" class="btn btn-success btn-sm" id="at-confirm-btn">
                        <i class="fas fa-language me-1"></i>{{ __('Translate') }}
                        <span class="spinner-border spinner-border-sm d-none" id="auto-translate-loading"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    @if(($mapProvider ?? 'free_api') === 'free_api')
    <div class="modal fade" id="manualLocationModal" tabindex="-1" aria-labelledby="manualLocationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="manualLocationModalLabel">{{ __('Manually Add Location') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <select class="form-select" id="manual-country-select">
                            <option value="">{{ __('Country') }}</option>
                            @foreach($countries as $country)
                                <option value="{{ $country->id }}" data-name="{{ $country->name }}" data-lat="{{ $country->latitude }}" data-lng="{{ $country->longitude }}">{{ $country->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <select class="form-select" id="manual-state-select" disabled>
                            <option value="">{{ __('State') }}</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <select class="form-select" id="manual-city-select" disabled>
                            <option value="">{{ __('City') }}</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <textarea class="form-control" id="manual-address-input" rows="3" placeholder="{{ __('Enter address') }}"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="button" class="btn btn-primary" id="manual-location-save">{{ __('Save') }}</button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Trim blocking overlay — covers entire page while MediaRecorder is processing --}}
    <div id="reel-trim-overlay" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.45);cursor:not-allowed;flex-direction:column;align-items:center;justify-content:center;gap:12px;">
        <div class="spinner-border text-light" style="width:3rem;height:3rem;" role="status"></div>
        <span class="text-white fw-semibold fs-6">{{ __('Video uploading...') }}</span>
    </div>

    {{-- ALL IMAGES MODAL --}}
    <div class="modal fade" id="galleryAllImagesModal" tabindex="-1" aria-labelledby="galleryAllImagesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="galleryAllImagesModalLabel">{{ __('All Images') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="max-height: 400px; overflow-y: auto;">
                    <div id="modal-images-grid" class="d-flex flex-wrap gap-3 justify-content-start">
                        <!-- Dynamic grid of all images inside the modal -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Close') }}</button>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('script')
    @include('items.partials.module-rules')
    <script>
        // Same settings /get-custom-fields returns on a category change.
        $(function () {
            applyItemModuleSettings(@json($moduleSettings), @json($itemIsJobCategory));
        });
    </script>
    <!-- ffmpeg.wasm — client-side reel trim, always outputs mp4 (no server ffmpeg needed, works on shared hosting).
         ffmpeg.js itself is loaded on demand in getReelFFmpeg(), see the note there. -->
    <script src="{{ asset('assets/js/ffmpeg/ffmpeg-util.js') }}"></script>
    <!-- intl-tel-input CSS -->
    <link rel="stylesheet" href="{{ asset('assets/extensions/intl-tel-input/css/intlTelInput.css') }}">
    <!-- intl-tel-input JS -->
    <script src="{{ asset('assets/extensions/intl-tel-input/js/intlTelInput.min.js') }}"></script>
    <script src="{{ asset('assets/js/custom/item-map.js') }}"></script>
    
    
    <script>
        const initialHasVideo = {{ !empty($currentVideoType) ? 'true' : 'false' }};

        function setVideoType(type, label, force = false) {
            if (type === '' && initialHasVideo && !force) {
                const deleteFlag = document.getElementById('delete-item-video-flag').value;
                if (deleteFlag !== '1') {
                    Swal.fire({
                        title: "{{ __('Are you sure?') }}",
                        text: "{{ __('Selecting None will remove the existing product video.') }}",
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#3085d6',
                        cancelButtonColor: '#d33',
                        confirmButtonText: "{{ __('Yes, remove it!') }}",
                        cancelButtonText: "{{ __('Cancel') }}"
                    }).then((result) => {
                        if (result.isConfirmed) {
                            document.getElementById('delete-item-video-flag').value = '1';
                            const card = document.getElementById('item-video-existing-file-card');
                            if (card) card.style.display = 'none';
                            setVideoType('', '{{ __('None') }}', true);
                        }
                    });
                    return;
                }
            }

            document.getElementById('video_type_hidden').value = type;
            document.getElementById('videoTypeLabel').textContent = label;
            document.getElementById('item-video-link-wrap').style.display = ['youtube_link','vimeo_link','other_link'].includes(type) ? '' : 'none';
            document.getElementById('item-video-file-wrap').style.display = type === 'file' ? '' : 'none';
            if (type !== 'file') { 
                const f = document.getElementById('item-video-file-input'); 
                if(f) f.value = ''; 
                document.getElementById('item-video-file-selected').style.display = 'none'; 
            }
            if (!['youtube_link','vimeo_link','other_link'].includes(type)) { 
                const l = document.getElementById('item-video-link-input'); 
                if(l) l.value = ''; 
            }

            if (type !== '') {
                document.getElementById('delete-item-video-flag').value = '0';
            } else {
                document.getElementById('delete-item-video-flag').value = '1';
            }
        }

        function removeExistingItemVideo() {
            Swal.fire({
                title: "{{ __('Are you sure?') }}",
                text: "{{ __('The existing product video will be removed.') }}",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: "{{ __('Yes, remove it!') }}",
                cancelButtonText: "{{ __('Cancel') }}"
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('delete-item-video-flag').value = '1';
                    const card = document.getElementById('item-video-existing-file-card');
                    if (card) card.style.display = 'none';
                    setVideoType('', '{{ __('None') }}', true);
                }
            });
        }
        function showItemVideoFilePreview(input) {
            if (!input.files || !input.files[0]) return;
            const file = input.files[0];
            if (itemVideoTooLarge(file)) {
                showErrorToast("{{ __('Video exceeds the maximum size of') }} " + window.itemVideoMaxSizeMb + " MB");
                input.value = '';
                return;
            }
            document.getElementById('item-video-file-name').textContent = file.name;
            document.getElementById('item-video-file-size').textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
            document.getElementById('item-video-file-selected').style.display = '';
        }
        function clearItemVideoFile() {
            document.getElementById('item-video-file-input').value = '';
            document.getElementById('item-video-file-selected').style.display = 'none';
        }
    </script>
    <script>
        $(document).ready(function() {
            // Languages array for JavaScript
            const languages = @json($languages);
            const defaultLanguageId = {{ $defaultLanguage->id }};
            
            // Language switching for Details tab - only show/hide translatable fields (name and description)
            $('#details-language-selector').on('change', function() {
                const selectedLangId = $(this).val();
                const defaultLangId = {{ $defaultLanguage->id }};
                
                // Hide all other language fields (non-default)
                $('#listing .other-language-fields').hide();
                
                // If default language is selected, show default fields
                if (selectedLangId == defaultLangId) {
                    $('#listing .default-language-fields').show();
                } else {
                    // Hide default language fields (only the translatable ones)
                    $('#listing .default-language-fields').hide();
                    // Show selected language fields (only name and description)
                    $(`#listing .other-language-fields[data-language-id="${selectedLangId}"]`).show();
                }
                
                // Ensure non-translatable fields are always visible
                // They are in a separate div outside language-fields containers, so they should remain visible
            });

            // Initialize: Show default language fields (name and description only)
            const defaultLangId = {{ $defaultLanguage->id }};
            $('#listing .default-language-fields').show();
            $('#listing .other-language-fields').hide();

            // Language switching for SEO tab
            $('#seo-language-selector').on('change', function() {
                const selectedLangId = $(this).val();
                $('.seo-language-fields').hide();
                $(`.seo-language-fields[data-seo-language-id="${selectedLangId}"]`).show();
            });

            // SEO keyword tags: items/partials/wizard-script.
            
            // Initialize country code selector for phone number
            let phoneInputInitialized = false;
            let phoneIti = null;

            function initPhoneInput() {
                const phoneInput = document.getElementById('contact-input');
                if (!phoneInput || phoneInputInitialized) {
                    return;
                }

                // Check if intlTelInput is already initialized on this input
                if (phoneInput.classList.contains('iti-mobile') || phoneInput.closest('.iti')) {
                    phoneInputInitialized = true;
                    return;
                }

                // Wait for intlTelInput library to load
                if (typeof window.intlTelInput === 'undefined') {
                    setTimeout(initPhoneInput, 100);
                    return;
                }

                @php
                    $itemRegionCode = !empty($item->region_code) ? strtolower($item->region_code) : 'in';
                @endphp

                try {
                    const iti = window.intlTelInput(phoneInput, {
                        initialCountry: "{{ $itemRegionCode }}",
                        preferredCountries: ['us', 'gb', 'in', 'ca', 'au'],
                        separateDialCode: true,
                        utilsScript: "{{ asset('assets/extensions/intl-tel-input/js/utils.js') }}"
                    });

                    phoneIti = iti;
                    phoneInputInitialized = true;

                    // Strip dial code from a raw value string based on selected country
                    function stripDialCode(value) {
                        const countryData = iti.getSelectedCountryData();
                        if (!countryData || !countryData.dialCode) return value;
                        value = value.trim();
                        if (value.startsWith('+' + countryData.dialCode)) {
                            value = value.slice(1 + countryData.dialCode.length).trim();
                        } else if (value.startsWith('+')) {
                            value = value.slice(1).trim();
                        }
                        return value;
                    }

                    // Format the current input value as a national phone number, preserving cursor
                    function formatPhoneNumber() {
                        if (typeof intlTelInputUtils === 'undefined') return;
                        const countryData = iti.getSelectedCountryData();
                        if (!countryData || !countryData.iso2) return;
                        const raw = phoneInput.value;
                        const digits = raw.replace(/\D/g, '');
                        if (!digits) { phoneInput.value = ''; return; }
                        try {
                            const cursorPos = phoneInput.selectionStart;
                            const digitsBeforeCursor = raw.slice(0, cursorPos).replace(/\D/g, '').length;
                            const formatted = intlTelInputUtils.formatNumber(
                                digits, countryData.iso2, intlTelInputUtils.numberFormat
                            );
                            if (formatted && formatted !== raw) {
                                phoneInput.value = formatted;
                                // Restore cursor by matching digit count
                                let newPos = formatted.length;
                                let dc = 0;
                                for (let i = 0; i < formatted.length; i++) {
                                    if (/\d/.test(formatted[i])) {
                                        dc++;
                                        if (dc === digitsBeforeCursor) { newPos = i + 1; break; }
                                    }
                                }
                                phoneInput.setSelectionRange(newPos, newPos);
                            }
                        } catch (e) { /* skip formatting on error */ }
                    }

                    // Auto-detect country from a +dialcode number, switch flag, then format
                    let countryDetectTimer = null;
                    function detectAndSetCountry(value) {
                        try {
                            iti.setNumber(value); // sets flag + strips dial code into input
                            const countryData = iti.getSelectedCountryData();
                            if (countryData && countryData.dialCode) {
                                $('#country-code-input').val('+' + countryData.dialCode);
                                $('#region-code-input').val(countryData.iso2 ?? '');
                            }
                            formatPhoneNumber();
                        } catch (e) {
                            phoneInput.value = stripDialCode(value);
                            formatPhoneNumber();
                        }
                    }

                    // Format normally; if starts with +, debounce 400ms to detect country
                    phoneInput.addEventListener('input', function() {
                        clearTimeout(countryDetectTimer);
                        if (this.value.startsWith('+')) {
                            countryDetectTimer = setTimeout(function() {
                                detectAndSetCountry(phoneInput.value.trim());
                            }, 400);
                        } else {
                            formatPhoneNumber();
                        }
                    });

                    // Paste: detect country immediately (no debounce needed)
                    phoneInput.addEventListener('paste', function() {
                        clearTimeout(countryDetectTimer);
                        setTimeout(function() {
                            const val = phoneInput.value.trim();
                            if (val.startsWith('+')) {
                                detectAndSetCountry(val);
                            } else {
                                formatPhoneNumber();
                            }
                        }, 0);
                    });

                    // Update hidden fields, strip dial code, and reformat for new country
                    phoneInput.addEventListener('countrychange', function() {
                        const countryData = iti.getSelectedCountryData();
                        if (countryData && countryData.dialCode) {
                            $('#country-code-input').val('+' + countryData.dialCode);
                            $('#region-code-input').val(countryData.iso2 ?? '');
                        }
                        phoneInput.value = stripDialCode(phoneInput.value);
                        formatPhoneNumber();
                    });

                    // Set initial hidden field values and format pre-filled value after utils load
                    setTimeout(function() {
                        const initialCountryData = iti.getSelectedCountryData();
                        if (initialCountryData && initialCountryData.dialCode) {
                            $('#country-code-input').val('+' + initialCountryData.dialCode);
                            $('#region-code-input').val(initialCountryData.iso2 ?? '');
                        }
                        if (phoneInput.value) {
                            phoneInput.value = stripDialCode(phoneInput.value);
                            formatPhoneNumber();
                        }
                    }, 1000);
                } catch (error) {
                    // Silently handle error
                }
            }
            
            // Initialize phone input when Details tab is shown
            $('#editItemTabs a[href="#listing"]').on('shown.bs.tab', function() {
                setTimeout(initPhoneInput, 300);
            });
            
            // Also initialize on page load if Details tab is already visible
            if ($('#listing').hasClass('active') || $('#listing').hasClass('show')) {
                setTimeout(initPhoneInput, 500);
            } else {
                // Initialize after a short delay to ensure library is loaded
                setTimeout(initPhoneInput, 200);
            }
            
            // ── Wizard state: starts from the listing's saved module / category ──
            const savedCategoryTrail = @json($categoryTrail);
            let selectedCategoryId = savedCategoryTrail.length ? savedCategoryTrail[savedCategoryTrail.length - 1].id : null;
            let selectedCategoryName = savedCategoryTrail.length ? savedCategoryTrail[savedCategoryTrail.length - 1].name : '';
            let selectedCategoryPath = savedCategoryTrail.map(c => c.name);
            let currentParentId = null;
            let hasCustomFields = false;

            // Update hasCustomFields when custom fields are loaded
            function updateCustomFieldsStatus() {
                hasCustomFields = $('#custom .custom-field-input').length > 0;
            }

            @include('items.partials.wizard-script', ['wizardTabs' => '#editItemTabs', 'wizardItemId' => $item->id])

            // Saved category: open its level with it selected; its fields load with saved values.
            selectedCategoryTrail = savedCategoryTrail.slice(0, -1);
            categoryTrail = selectedCategoryTrail.slice();
            renderSelectedCategoryLine();
            if (selectedCategoryId) loadCustomFields(selectedCategoryId);

            // Steps unlock as on create: Module → Format → Category → the rest. An edit starts
            // fully unlocked; changing the module locks the later steps until a category is picked.
            function tabLockedReason(targetTab) {
                if (targetTab === '#module') return null;
                if (!$('#module_id_input').val()) return window.trans('Please select a module first.');
                if (targetTab === '#format') return null;
                if (!$('#item_type_input').val()) return window.trans('Please select an ads type first.');
                if (targetTab === '#categories') return null;
                if (!selectedCategoryId || !$('#category_id').val()) return window.trans('Please select a category first.');
                return null;
            }

            function refreshTabLocks() {
                $('#editItemTabs a[data-bs-toggle="tab"]').each(function() {
                    $(this).toggleClass('tab-disabled', tabLockedReason($(this).attr('href')) !== null);
                });
            }
            window.refreshItemTabLocks = refreshTabLocks;
            refreshTabLocks();

            // Module: categories belong to a module, so switching module drops the category
            // (and its listing details) after a confirmation; the server moves the ad on save.
            function applyModule(moduleId) {
                $('#module_id_input').val(moduleId);
                $('#module .module-option').each(function() {
                    $(this).toggleClass('selected', String($(this).data('module-id')) === String(moduleId));
                });
                resetCategorySelection();
                refreshTabLocks();
            }

            $(document).on('click keydown', '#module .module-option', function(e) {
                if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
                e.preventDefault();
                const moduleId = $(this).data('module-id');
                if (String($('#module_id_input').val()) === String(moduleId)) return;
                if (typeof Swal === 'undefined') { applyModule(moduleId); return; }
                Swal.fire({
                    title: window.trans('Change module?'),
                    text: window.trans('The category and listing details of this listing will be cleared. Pick a category of the new module before updating.'),
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: window.trans('Change'),
                    cancelButtonText: window.trans('Cancel'),
                }).then(function (result) {
                    if (result.isConfirmed) applyModule(moduleId);
                });
            });

            $(document).on('click', '#editItemTabs a[data-bs-toggle="tab"]', function(e) {
                // No step change while a reel is being processed.
                const reason = (window.reelTrimming || window.reelUploading)
                    ? '{{ __('Please wait for the video to finish processing.') }}'
                    : tabLockedReason($(this).attr('href'));
                if (reason) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    showErrorToast(reason);
                    return false;
                }
            });

            $('#editItemTabs a[data-bs-toggle="tab"]').on('show.bs.tab', function(e) {
                if (window.reelTrimming || window.reelUploading || tabLockedReason($(e.target).attr('href'))) {
                    e.preventDefault();
                    return false;
                }
            });

            // Init map on address tab show
            $('#editItemTabs a[href="#address"]').on('shown.bs.tab', function() {
                setTimeout(() => { initMap(); }, 300);
            });

            // Clear invalid highlight once user edits a custom field
            $(document).on('input change', '#custom .custom-field-input, #custom .custom-field-checkbox', function() {
                $(this).removeClass('is-invalid');
            });

            // Char counter for textbox custom fields with min/max length
            $(document).on('input input.cfcounter', '#custom .custom-field-input[data-max-length], #custom .custom-field-input[data-min-length]', function() {
                const $input = $(this);
                const len = ($input.val() || '').length;
                const max = $input.data('max-length');
                const min = $input.data('min-length');
                let text = max ? `${len}/${max}` : `${len}`;
                if (min && len < min) text += ` ({{ __('min') }} ${min})`;
                $input.next('.cf-char-counter').text(text).toggleClass('text-danger', !!(min && len > 0 && len < min));
            });

            // Clear invalid highlight once user edits the admin edit reason
            $(document).on('input', '#admin_edit_reason', function() {
                $(this).removeClass('is-invalid');
            });

            // Step order: Module → Format → Category → Basic Info → Listing Details
            // (only when the category has custom fields) → SEO → Media → Location → Review.
            function showStep(step) {
                if (step === 'custom-or-seo') step = hasCustomFields ? 'custom' : 'seo';
                if (step === 'custom-or-listing') step = hasCustomFields ? 'custom' : 'listing';
                $('#editItemTabs a[href="#' + step + '"]').tab('show');
                if (step === 'address') {
                    setTimeout(() => { initMap(); }, 300);
                }
            }

            function blockedByVideo(e) {
                if (window.reelTrimming || window.reelUploading) {
                    e.preventDefault();
                    e.stopPropagation();
                    showErrorToast('{{ __('Please wait for the video to finish processing.') }}');
                    return true;
                }
                return false;
            }

            // Next button handler
            $(document).on('click', '.listing-wizard .btn-next-tab', function(e) {
                if (blockedByVideo(e)) return false;
                e.preventDefault();
                e.stopPropagation();
                const currentTab = $('.listing-wizard > .tab-content > .tab-pane.active').attr('id');
                if (!validateCurrentTab(currentTab)) {
                    return false;
                }
                showStep($(this).data('next-tab'));
            });

            // Previous button handler
            $(document).on('click', '.listing-wizard .btn-prev-tab', function(e) {
                if (blockedByVideo(e)) return false;
                e.preventDefault();
                e.stopPropagation();
                showStep($(this).data('prev-tab'));
            });

            // Validation function for current tab
            function validateCurrentTab(tabId) {
                let isValid = true;
                let firstInvalidField = null;

                if (tabId === 'module') {
                    if (!$('#module_id_input').val()) {
                        showErrorToast(window.trans('Please select a module first.'));
                        isValid = false;
                    }
                } else if (tabId === 'format') {
                    if (!$('#item_type_input').val()) {
                        showErrorToast(window.trans('Please select an ads type first.'));
                        isValid = false;
                    }
                } else if (tabId === 'categories') {
                    if (!selectedCategoryId || !$('#category_id').val()) {
                        showErrorToast(window.trans('Please select a category first.'));
                        isValid = false;
                    }
                } else if (tabId === 'listing') {
                    const name = $('#name-input').val().trim();
                    const description = $('#description-input').val().trim();
                    const price = $('#price-input').val();
                    const minSalary = $('#min-salary-input').val();
                    const maxSalary = $('#max-salary-input').val();
                    const contact = $('#contact-input').val().trim();

                    if (!name) {
                        showErrorToast(window.trans('Please enter a english title.'));
                        $('#name-input').focus();
                        isValid = false;
                    } else if (!description) {
                        showErrorToast(window.trans('Please enter a english description.'));
                        $('#description-input').focus();
                        isValid = false;
                    } else if ($('#price-field').css('display') !== 'none' && $('#price-input').attr('required') && !price) {
                        showErrorToast(window.trans('Please enter a price.'));
                        $('#price-input').focus();
                        isValid = false;
                    } else if ($('#salary-fields').css('display') !== 'none') {
                        // At least one bound; both are optional on their own.
                        if (!minSalary && !maxSalary) {
                            showErrorToast(itemRangeLabel('either'));
                            $('#min-salary-input').focus();
                            isValid = false;
                        } else if (minSalary && maxSalary && parseFloat(minSalary) >= parseFloat(maxSalary)) {
                            // Server rule: max_salary gt:min_salary.
                            showErrorToast(itemRangeLabel('order'));
                            $('#min-salary-input').focus();
                            isValid = false;
                        }
                    }

                    // Phone number validation
                    if (isValid && contact && phoneIti) {
                        try {
                            if (typeof intlTelInputUtils !== 'undefined' && !phoneIti.isValidNumber()) {
                                showErrorToast(window.trans('Please enter a valid phone number for the selected country.'));
                                $('#contact-input').focus();
                                isValid = false;
                            }
                        } catch (e) {
                            // Skip validation if utils not loaded
                        }
                    }

                } else if (tabId === 'custom') {
                    // Validate required custom fields (only default language fields)

                    // Clear previous invalid state
                    $('#custom .custom-field-input, #custom .custom-field-checkbox').removeClass('is-invalid');

                    // Resolve the visible label text for a given field
                    const fieldLabel = function($field) {
                        const text = $field.closest('.col-md-6, .col-md-12, .col-12').find('label').first().clone()
                            .children().remove().end().text().trim();
                        return text || window.trans('This field');
                    };

                    const formElement = $('#custom').closest('form');
                    const hasParsley = formElement.length && typeof formElement.parsley === 'function';

                    $('#custom .custom-field-input').each(function() {
                        const $field = $(this);
                        let isFieldValid = true;

                        if (hasParsley) {
                            const parsleyField = $field.parsley();
                            if (parsleyField) {
                                isFieldValid = parsleyField.validate() === true;
                            }
                        } else {
                            if ($field.attr('required')) {
                                let invalid = false;
                                if ($field.is(':file')) {
                                    invalid = !$field[0].files || $field[0].files.length === 0;
                                } else if ($field.is('select')) {
                                    invalid = !$field.val();
                                } else {
                                    invalid = !$field.val().trim();
                                }
                                isFieldValid = !invalid;
                            }
                        }

                        if (!isFieldValid) {
                            $field.addClass('is-invalid');
                            if (!firstInvalidField) {
                                firstInvalidField = $field;
                            }
                            isValid = false;
                        } else {
                            $field.removeClass('is-invalid');
                        }
                    });

                    if (!isValid && firstInvalidField) {
                        if (hasParsley) {
                            showErrorToast(fieldLabel(firstInvalidField) + ' ' + window.trans('is invalid.'));
                        } else {
                            showErrorToast(fieldLabel(firstInvalidField) + ' ' + window.trans('is required.'));
                        }
                        firstInvalidField.focus();
                    }

                    // Validate checkbox groups
                    const checkedGroups = {};
                    $('#custom .custom-field-checkbox[required]').each(function() {
                        const $field = $(this);
                        const name = $field.attr('name');
                        if (checkedGroups[name]) return;
                        if (!$('input[name="' + name + '"]:checked').length) {
                            checkedGroups[name] = true;
                            $('input[name="' + name + '"]').addClass('is-invalid');
                            if (isValid) {
                                showErrorToast(fieldLabel($field) + ' ' + window.trans('is required.'));
                            }
                            isValid = false;
                        }
                    });
                } else if (tabId === 'images') {
                    // At least one image: kept existing ones + new uploads (server re-checks).
                    const keptImages = $('.delete-existing-img-cb').not(':checked').length;
                    const newImages = ($('#gallery-images-input')[0]?.files || []).length;
                    if (keptImages + newImages < 1) {
                        showErrorToast(window.trans('Please select at least one product image.'));
                        isValid = false;
                    }

                    // For reel items, a reel video is required (unless existing one kept)
                    if ($('#item_type_input').val() === 'reel') {
                        const hasExistingReel = $('#reel-existing-wrap').length > 0 && $('#reel-delete-flag').val() !== '1';
                        const hasNewVideo = document.getElementById('reel-video-input') && document.getElementById('reel-video-input').files.length > 0;
                        if (!hasExistingReel && !hasNewVideo) {
                            showErrorToast(window.trans('Please upload a Video Ad video.'));
                            isValid = false;
                        }
                        if (window.reelTrimming) {
                            showErrorToast(window.trans('Please wait for video processing to complete.'));
                            isValid = false;
                        }
                    }

                    // Validate item video link/file based on selected type
                    const videoType = document.getElementById('video_type_hidden')?.value || '';
                    if (['youtube_link','vimeo_link','other_link'].includes(videoType)) {
                        const videoLinkInput = document.getElementById('item-video-link-input');
                        const videoLink = videoLinkInput?.value?.trim() || '';
                        if (!videoLink) {
                            showErrorToast(window.trans('Please enter a video URL for the selected video type.'));
                            $(videoLinkInput).addClass('is-invalid').removeClass('is-valid');
                            videoLinkInput?.focus();
                            isValid = false;
                        } else {
                            const error = validateVideoLink(videoLink, videoType);
                            if (error) {
                                showErrorToast(error);
                                $(videoLinkInput).addClass('is-invalid').removeClass('is-valid');
                                videoLinkInput?.focus();
                                isValid = false;
                            } else {
                                $(videoLinkInput).removeClass('is-invalid').removeClass('is-valid');
                            }
                        }
                    } else if (videoType === 'file') {
                        const hasExistingVideo = $('#item-video-existing-file-card').length > 0 && $('#delete-item-video-flag').val() !== '1';
                        const videoFileInput = document.getElementById('item-video-file-input');
                        const hasNewVideo = videoFileInput && videoFileInput.files && videoFileInput.files.length > 0;
                        if (!hasExistingVideo && !hasNewVideo) {
                            showErrorToast(window.trans('Please upload a video file.'));
                            isValid = false;
                        }
                    }

                } else if (tabId === 'address') {
                    const lat = $('#latitude-input').val();
                    const lng = $('#longitude-input').val();

                    if (!lat || !lng) {
                        showErrorToast(window.trans('Please select a location on the map.'));
                        isValid = false;
                    }
                } else if (tabId === 'review') {
                    // Required (another user's listing): reason for admin edit
                    const $reason = $('#admin_edit_reason');
                    $reason.removeClass('is-invalid');
                    if ($reason.length && !$reason.val().trim()) {
                        $reason.addClass('is-invalid');
                        if (isValid) {
                            showErrorToast(window.trans('Reason for Admin Edit') + ' ' + window.trans('is required.'));
                            $reason.focus();
                        }
                        isValid = false;
                    }
                }

                return isValid;
            }

            // URL validation helper
            function isValidUrl(string) {
                if (!string || !string.trim()) return true; // Empty is valid (nullable)
                try {
                    const url = new URL(string);
                    return url.protocol === 'http:' || url.protocol === 'https:';
                } catch (_) {
                    return false;
                }
            }

            // Mirrors App\Rules\VideoLink server-side validation
            function validateVideoLink(link, videoType) {
                let url;
                try {
                    url = new URL(link);
                } catch (_) {
                    return window.trans('Please enter a valid URL.');
                }
                const host = url.hostname.toLowerCase().replace(/^(www\.|m\.)/, '');
                const hostVimeo = url.hostname.toLowerCase().replace(/^www\./, '');

                if (videoType === 'youtube_link') {
                    const youtubePattern = /^(https?:\/\/)?(www\.|m\.)?(youtube\.com|youtu\.be|youtube-nocookie\.com)\/.+$/i;
                    if (!youtubePattern.test(link)) return window.trans('The video link must be a valid YouTube URL.');
                } else if (videoType === 'vimeo_link') {
                    const vimeoPattern = /^(https?:\/\/)?(www\.|player\.)?vimeo\.com\/(video\/)?[0-9]{8,}([?#][^\s]*)?$/i;
                    if (!vimeoPattern.test(link)) return window.trans('The video link must be a valid Vimeo URL.');
                } else if (videoType === 'other_link') {
                    const isYoutube = ['youtube.com', 'youtu.be', 'youtube-nocookie.com'].includes(host);
                    const isVimeo = ['vimeo.com', 'player.vimeo.com'].includes(hostVimeo);
                    if (isYoutube || isVimeo) return window.trans('The video link must not be a YouTube or Vimeo URL.');
                    const allowedExtensions = ['mp4', 'webm', 'ogg', 'ogv', 'mov', 'm3u8', 'mkv', 'avi'];
                    const extMatch = url.pathname.toLowerCase().match(/\.([a-z0-9]+)$/);
                    const extension = extMatch ? extMatch[1] : '';
                    if (!allowedExtensions.includes(extension)) {
                        return window.trans('The video link must be a direct link to a video file (e.g. mp4, webm, m3u8).');
                    }
                }
                return null;
            }


            // ── Format step: regular or video (reel) ad ──────────────────────
            // Video → Regular removes the saved reel on update (after a confirmation);
            // switching back to Video keeps it. Regular → Video needs a reel upload on Media.
            const hasSavedReel = $('#reel-existing-wrap').length > 0;
            function applyItemType(itemType) {
                $('#item_type_input').val(itemType);
                $('#format .format-option').each(function () {
                    $(this).toggleClass('selected', $(this).data('type') === itemType);
                });
                const isReel = itemType === 'reel';
                $('#reel-video-section').toggle(isReel);
                $('#reel-delete-flag').val(!isReel && hasSavedReel ? '1' : '0');
                $('#editItemTabs').closest('form').find('input[name^="reel_"]').prop('disabled', !isReel);
                // Video Ad and Ad Images side by side for reels; images full width otherwise.
                $('#product-images-col').toggleClass('ec-media-full', !isReel);
                refreshTabLocks();
            }
            window.applyItemType = applyItemType;
            applyItemType($('#item_type_input').val() || 'normal');

            $(document).on('click keydown', '#format .format-option', function (e) {
                if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
                e.preventDefault();
                const type = $(this).data('type');
                if (type === $('#item_type_input').val()) return;
                if (type === 'normal' && hasSavedReel && typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: window.trans('Switch to a regular ad?'),
                        text: window.trans('The current Video Ad will be removed when you update the listing.'),
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: window.trans('Switch'),
                        cancelButtonText: window.trans('Cancel'),
                    }).then(function (result) {
                        if (result.isConfirmed) applyItemType('normal');
                    });
                    return;
                }
                applyItemType(type);
            });

            // Validate video link format on input and blur
            $('#item-video-link-input').on('input blur', function() {
                const val = $(this).val().trim();
                const videoType = document.getElementById('video_type_hidden')?.value || '';
                const hasTypeError = val && videoType && validateVideoLink(val, videoType);
                if (val && (!isValidUrl(val) || hasTypeError)) {
                    $(this).addClass('is-invalid').removeClass('is-valid');
                } else {
                    $(this).removeClass('is-invalid').removeClass('is-valid');
                }
            });

            // Pre-submit validation function (called by global form handler)
            window.validateAdvertisementUpdateForm = function() {
                const tabs = ['module', 'format', 'categories', 'listing', 'images', 'address', 'review'];
                if (hasCustomFields) {
                    tabs.splice(4, 0, 'custom');
                }

                for (let i = 0; i < tabs.length; i++) {
                    if (!validateCurrentTab(tabs[i])) {
                        $('#editItemTabs a[href="#' + tabs[i] + '"]').tab('show');
                        if (tabs[i] === 'address') {
                            setTimeout(() => { initMap(); }, 300);
                        }
                        return false;
                    }
                }

                // Normalize contact: send digits-only national number to backend
                // If no phone number entered, clear country_code and region_code so they aren't passed
                if (phoneIti) {
                    try {
                        const rawContact = $('#contact-input').val().trim();
                        if (rawContact) {
                            let nationalDigits = rawContact.replace(/\D/g, '');
                            $('#contact-input').val(nationalDigits);
                        } else {
                            $('#country-code-input').val('');
                            $('#region-code-input').val('');
                        }
                    } catch (e) {
                        // Keep original value on error
                    }
                }

                return true; // Allow form submission
            };
        });
    </script>

    <script>
        $(document).ready(function() {
            // The saved address is rendered into #location-search / #selected-address-text.

            // Image upload handlers — drop zone click handled via onclick in HTML

            // Store gallery files for removal and appending
            let galleryFiles = [];
            const existingImages = @json($item->gallery_images ?? []);

            function remainingGallerySlots() {
                const existingCount = existingImages.filter(img => {
                    const cb = document.getElementById('delete-img-' + img.id);
                    return cb ? !cb.checked : true;
                }).length;
                // Per-module limit, set by applyItemModuleSettings().
                return window.itemMaxGalleryImages - existingCount - galleryFiles.length;
            }

            function appendGalleryFiles(newFiles) {
                const remaining = remainingGallerySlots();
                if (newFiles.length > remaining) {
                    showErrorToast(window.trans('You can upload a maximum of') + ' ' + window.itemMaxGalleryImages + ' ' + window.trans('images'));
                    newFiles = newFiles.slice(0, Math.max(0, remaining));
                }
                if (newFiles.length > 0) { galleryFiles = galleryFiles.concat(newFiles); }
            }

            // Helper to get active cover image info
            function getCoverInfo() {
                // Find the first existing image that has is_default = 1 AND is not deleted
                let defaultImg = existingImages.find(img => img.is_default && !document.getElementById('delete-img-' + img.id)?.checked);
                if (defaultImg) {
                    return { type: 'existing', id: defaultImg.id };
                }
                // If not found (either because default is deleted or there is no default), find the first non-deleted existing image
                let firstActiveExisting = existingImages.find(img => !document.getElementById('delete-img-' + img.id)?.checked);
                if (firstActiveExisting) {
                    return { type: 'existing', id: firstActiveExisting.id };
                }
                // If no active existing images, the first new image (index 0) is the cover
                if (galleryFiles.length > 0) {
                    return { type: 'new', index: 0 };
                }
                return null;
            }

            window.toggleExistingImage = function(id, event) {
                if (event) {
                    event.stopPropagation();
                    event.preventDefault();
                }
                const cb = document.getElementById('delete-img-' + id);
                if (!cb) return;
                cb.checked = !cb.checked;
                renderGalleryPreview();
                if ($('#galleryAllImagesModal').is(':visible')) {
                    renderModalImages();
                }
            };

            function renderGalleryPreview() {
                const preview = document.getElementById('gallery-images-preview');
                if (!preview) return;
                preview.innerHTML = '';

                // Calculate available width
                preview.style.display = 'flex';
                preview.style.flexWrap = 'nowrap';
                preview.style.overflow = 'hidden';
                
                const W = preview.clientWidth || preview.parentElement.clientWidth;
                
                const totalItems = existingImages.length + galleryFiles.length;
                let visibleCount = totalItems;
                let extraCount = 0;

                // Dynamic width calculation
                if (totalItems * 88 - 8 > W && W > 0) {
                    visibleCount = Math.floor((W - 80) / 88);
                    if (visibleCount < 1) visibleCount = 1;
                    extraCount = totalItems - visibleCount;
                }

                let renderedCount = 0;
                const coverInfo = getCoverInfo();

                // 1. Render existing images
                existingImages.forEach((img) => {
                    if (renderedCount >= visibleCount) return;

                    const isDeleted = document.getElementById('delete-img-' + img.id)?.checked || false;
                    const isCover = coverInfo && coverInfo.type === 'existing' && coverInfo.id === img.id;

                    const wrap = document.createElement('div');
                    wrap.className = 'gallery-thumb-wrap existing-image-item' + (isCover ? ' cover-thumb' : '') + (isDeleted ? ' marked-delete' : '');
                    wrap.dataset.id = img.id;
                    wrap.dataset.isDefault = img.is_default;
                    wrap.style.cursor = 'pointer';

                    wrap.innerHTML = `
                        <img src="${img.image}" alt="">
                        ${isCover ? `<span class="cover-badge">${window.trans('Cover')}</span>` : ''}
                        <label class="remove-thumb" title="{{ __('Remove') }}" onclick="toggleExistingImage(${img.id}, event)">
                            ✕
                        </label>
                    `;
                    wrap.addEventListener('click', function(e) {
                        if (e.target.classList.contains('remove-thumb') || e.target.closest('.remove-thumb')) {
                            return;
                        }
                        openAllImagesModal();
                    });
                    preview.appendChild(wrap);
                    renderedCount++;
                });

                // 2. Render new images
                galleryFiles.forEach((file, i) => {
                    if (renderedCount >= visibleCount) return;

                    const imgSrc = URL.createObjectURL(file);
                    const isCover = coverInfo && coverInfo.type === 'new' && coverInfo.index === i;

                    const wrap = document.createElement('div');
                    wrap.className = 'gallery-thumb-wrap' + (isCover ? ' cover-thumb' : '');
                    wrap.dataset.fileIndex = i;
                    wrap.style.cursor = 'pointer';

                    wrap.innerHTML = `
                        <img src="${imgSrc}" alt="">
                        ${isCover ? `<span class="cover-badge">${window.trans('Cover')}</span>` : ''}
                        <span class="remove-thumb remove-gallery-image" data-index="${i}">✕</span>
                    `;
                    wrap.addEventListener('click', function(e) {
                        if (e.target.classList.contains('remove-thumb') || e.target.closest('.remove-thumb') || e.target.classList.contains('remove-gallery-image')) {
                            return;
                        }
                        openAllImagesModal();
                    });
                    preview.appendChild(wrap);
                    renderedCount++;
                });

                // 3. Render +N badge if extraCount > 0
                if (extraCount > 0) {
                    const more = document.createElement('div');
                    more.className = 'gallery-more-badge';
                    more.style.cursor = 'pointer';
                    more.innerHTML = `<span dir="ltr">+${extraCount}</span><small style="font-size:10px;font-weight:400;">${window.trans('More')}</small>`;
                    more.addEventListener('click', openAllImagesModal);
                    preview.appendChild(more);
                }

                // Sync new files to input
                const input = document.getElementById('gallery-images-input');
                if (input) {
                    const dt = new DataTransfer();
                    galleryFiles.forEach(f => dt.items.add(f));
                    input.files = dt.files;
                }
            }

            window.openAllImagesModal = function() {
                renderModalImages();
                const modal = new bootstrap.Modal(document.getElementById('galleryAllImagesModal'));
                modal.show();
            };

            window.renderModalImages = function() {
                const grid = document.getElementById('modal-images-grid');
                if (!grid) return;
                grid.innerHTML = '';

                const coverInfo = getCoverInfo();

                // Existing images inside modal
                existingImages.forEach((img) => {
                    const isDeleted = document.getElementById('delete-img-' + img.id)?.checked || false;
                    const isCover = coverInfo && coverInfo.type === 'existing' && coverInfo.id === img.id;

                    const wrap = document.createElement('div');
                    wrap.className = 'gallery-thumb-wrap existing-image-item' + (isCover ? ' cover-thumb' : '') + (isDeleted ? ' marked-delete' : '');
                    wrap.style.position = 'relative';
                    wrap.style.width = '100px';
                    wrap.style.height = '100px';

                    wrap.innerHTML = `
                        <img src="${img.image}" alt="">
                        ${isCover ? `<span class="cover-badge">${window.trans('Cover')}</span>` : ''}
                        <label class="remove-thumb" title="{{ __('Remove') }}" onclick="toggleExistingImage(${img.id}, event)">
                            ✕
                        </label>
                    `;
                    grid.appendChild(wrap);
                });

                // New images inside modal
                galleryFiles.forEach((file, i) => {
                    const imgSrc = URL.createObjectURL(file);
                    const isCover = coverInfo && coverInfo.type === 'new' && coverInfo.index === i;

                    const wrap = document.createElement('div');
                    wrap.className = 'gallery-thumb-wrap' + (isCover ? ' cover-thumb' : '');
                    wrap.style.position = 'relative';
                    wrap.style.width = '100px';
                    wrap.style.height = '100px';

                    wrap.innerHTML = `
                        <img src="${imgSrc}" alt="">
                        ${isCover ? `<span class="cover-badge">${window.trans('Cover')}</span>` : ''}
                        <span class="remove-thumb remove-gallery-image-modal" data-index="${i}">✕</span>
                    `;
                    grid.appendChild(wrap);
                });
            };

            $(document).on('click', '.remove-gallery-image-modal', function(e) {
                e.stopPropagation();
                e.preventDefault();
                const index = parseInt($(this).data('index'));
                galleryFiles.splice(index, 1);
                renderGalleryPreview();
                renderModalImages();
                
                const totalItems = existingImages.length + galleryFiles.length;
                if (totalItems === 0) {
                    const modalEl = document.getElementById('galleryAllImagesModal');
                    const modalInst = bootstrap.Modal.getInstance(modalEl);
                    if (modalInst) modalInst.hide();
                }
            });

            $('#gallery-images-input').on('change', function(e) {
                const newFiles = Array.from(e.target.files);
                if (newFiles.length > 0) { appendGalleryFiles(newFiles); }
                renderGalleryPreview();
            });

            // Handle new gallery image removal
            $(document).on('click', '.remove-gallery-image', function(e) {
                e.stopPropagation();
                e.preventDefault();
                const index = parseInt($(this).data('index'));
                galleryFiles.splice(index, 1);
                renderGalleryPreview();
            });

            // Drag and drop for gallery images
            const galleryUploadArea = document.getElementById('gallery-images-upload');
            if (galleryUploadArea) {
                galleryUploadArea.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    $(this).css('border-color', 'var(--bs-primary)');
                    $(this).css('background', 'rgba(var(--bs-primary-rgb), 0.08)');
                });
                
                galleryUploadArea.addEventListener('dragleave', function(e) {
                    e.preventDefault();
                    $(this).css('border-color', '#ddd');
                    $(this).css('background', '#f9f9f9');
                });
                
                galleryUploadArea.addEventListener('drop', function(e) {
                    e.preventDefault();
                    $(this).css('border-color', '#ddd');
                    $(this).css('background', '#f9f9f9');
                    
                    const files = e.dataTransfer.files;
                    if (files.length > 0) {
                        const newFiles = [];
                        for (let i = 0; i < files.length; i++) {
                            if (files[i].type.startsWith('image/')) {
                                newFiles.push(files[i]);
                            }
                        }
                        if (newFiles.length > 0) {
                            appendGalleryFiles(newFiles);
                            renderGalleryPreview();
                        }
                    }
                });
            }

            // Render gallery preview on tab activation & screen resize
            $('#editItemTabs a[href="#images"]').on('shown.bs.tab', function() {
                renderGalleryPreview();
            });

            $(window).on('resize', function() {
                if ($('#images').hasClass('active') || $('#images').hasClass('show')) {
                    renderGalleryPreview();
                }
            });

            renderGalleryPreview();
        });
        
        // Success callback function for advertisement form
        window.handleAdvertisementSuccess = function(response) {
            // Redirect to advertisement index page
            // window.location.href = '{{ route('advertisement.index') }}';
            
            // Back to the list once the success toast (shown by formAjaxRequest) has been seen.
            // formAjaxRequest re-enables Save after this callback; keep it off so the ad isn't saved twice.
            
            setTimeout(() => $('#item-submit-btn').prop('disabled', true));
            setTimeout(function () {
                window.location.href = '{{ route('advertisement.index') }}';
            }, 1000);
        };

        @if(($mapProvider ?? 'free_api') === 'free_api')
        // Manual location modal (free_api only)
        $(document).ready(function() {
            const $country = $('#manual-country-select');
            const $state = $('#manual-state-select');
            const $city = $('#manual-city-select');
            const $address = $('#manual-address-input');
            const $modal = $('#manualLocationModal');

            const PER_PAGE = 50;

            function ajaxSelect2($el, url, placeholder, buildParams) {
                if ($el.data('select2')) $el.select2('destroy');
                $el.select2({
                    dropdownParent: $modal,
                    placeholder: placeholder,
                    width: '100%',
                    allowClear: true,
                    ajax: {
                        url: url,
                        dataType: 'json',
                        delay: 250,
                        data: function(params) {
                            return $.extend({
                                search: params.term || '',
                                page: params.page || 1,
                                per_page: PER_PAGE
                            }, buildParams());
                        },
                        processResults: function(res, params) {
                            params.page = params.page || 1;
                            const d = (res && res.data) ? res.data : {};
                            const list = d.data || [];
                            return {
                                results: list.map(function(r) {
                                    return { id: r.id, text: r.name, lat: r.latitude || '', lng: r.longitude || '' };
                                }),
                                pagination: { more: (d.current_page || 1) < (d.last_page || 1) }
                            };
                        },
                        cache: true
                    }
                });
            }

            function plainSelect2($el, placeholder) {
                if ($el.data('select2')) $el.select2('destroy');
                $el.select2({ dropdownParent: $modal, placeholder: placeholder, width: '100%', allowClear: true });
            }

            let prefilled = false;
            $modal.on('shown.bs.modal', function() {
                plainSelect2($country, '{{ __('Country') }}');
                ajaxSelect2($state, '{{ url('api/states') }}', '{{ __('State') }}', function() {
                    return { country_id: $country.val() || '' };
                });
                ajaxSelect2($city, '{{ url('api/cities') }}', '{{ __('City') }}', function() {
                    return { state_id: $state.val() || '' };
                });
                if (!prefilled) {
                    prefilled = true;
                    prefillFromItem();
                }
            });

            // Append a pre-selected option carrying lat/lng so dependent loads use the numeric id.
            function setSelected($el, item) {
                const opt = new Option(item.name, item.id, true, true);
                $el.append(opt).data('selLat', item.latitude || '').data('selLng', item.longitude || '');
                $el.val(String(item.id)).trigger('change');
            }

            function prefillFromItem() {
                const itemCountry = @json($item->country ?? '');
                const itemState = @json($item->state ?? '');
                const itemCity = @json($item->city ?? '');
                const itemAddress = @json($item->address ?? '');

                if (itemAddress) {
                    let raw = itemAddress;
                    [itemCity, itemState, itemCountry].forEach(function(part) {
                        if (part) {
                            const suffix = ', ' + part;
                            if (raw.endsWith(suffix)) raw = raw.slice(0, -suffix.length);
                            else if (raw.endsWith(part)) raw = raw.slice(0, -part.length).replace(/,\s*$/, '');
                        }
                    });
                    $address.val(raw.trim());
                }

                if (!itemCountry) return;
                const $countryOpt = $country.find('option').filter(function() {
                    return ($(this).data('name') || '').toString().toLowerCase() === itemCountry.toLowerCase();
                }).first();
                if (!$countryOpt.length) return;
                $country.val($countryOpt.val()).trigger('change');
                $state.prop('disabled', false);

                if (!itemState) return;
                $.ajax({
                    url: '{{ url('api/states') }}',
                    data: { country_id: $countryOpt.val(), search: itemState, per_page: PER_PAGE },
                    success: function(res) {
                        const list = (res && res.data && res.data.data) ? res.data.data : [];
                        const s = list.find(function(x) { return (x.name || '').toLowerCase() === itemState.toLowerCase(); }) || list[0];
                        if (!s) return;
                        setSelected($state, s);
                        $city.prop('disabled', false);

                        if (!itemCity) return;
                        $.ajax({
                            url: '{{ url('api/cities') }}',
                            data: { state_id: s.id, search: itemCity, per_page: PER_PAGE },
                            success: function(res2) {
                                const list2 = (res2 && res2.data && res2.data.data) ? res2.data.data : [];
                                const c = list2.find(function(x) { return (x.name || '').toLowerCase() === itemCity.toLowerCase(); }) || list2[0];
                                if (c) setSelected($city, c);
                            }
                        });
                    }
                });
            }

            function clearSelect($el) {
                $el.val(null).trigger('change.select2');
                $el.removeData('selLat').removeData('selLng');
            }

            $country.on('change', function() {
                clearSelect($state);
                clearSelect($city);
                $state.prop('disabled', !$(this).val());
                $city.prop('disabled', true);
            });

            $state.on('change', function() {
                clearSelect($city);
                $city.prop('disabled', !$(this).val());
            });

            $('#manual-location-save').on('click', function() {
                const countrySel = $country.find('option:selected');
                const countryName = countrySel.data('name') || '';
                const stateData = ($state.select2('data') || [])[0] || {};
                const cityData = ($city.select2('data') || [])[0] || {};
                const stateName = stateData.text || '';
                const cityName = cityData.text || '';
                const addressText = ($address.val() || '').trim();

                if (!countryName) { showErrorToast('{{ __('Please select a country') }}'); return; }
                if (!stateName) { showErrorToast('{{ __('Please select a state') }}'); return; }
                if (!cityName) { showErrorToast('{{ __('Please select a city') }}'); return; }

                const lat = cityData.lat || $city.data('selLat') || stateData.lat || $state.data('selLat') || countrySel.data('lat') || '';
                const lng = cityData.lng || $city.data('selLng') || stateData.lng || $state.data('selLng') || countrySel.data('lng') || '';

                const fullAddress = [addressText, cityName, stateName, countryName].filter(Boolean).join(', ');

                $('#country-input').val(countryName);
                $('#state-input').val(stateName);
                $('#city-input').val(cityName);
                $('#address-hidden').val(fullAddress);
                if (lat && lng) {
                    $('#latitude-input').val(lat);
                    $('#longitude-input').val(lng);
                    if (typeof selectLocationFromCoords === 'function') {
                        selectLocationFromCoords(parseFloat(lat), parseFloat(lng));
                    }
                }

                $('#selected-address-display').show();
                $('#selected-address-text').text(fullAddress);
                $('#location-search').val(fullAddress);

                bootstrap.Modal.getInstance(document.getElementById('manualLocationModal')).hide();
            });
        });
        @endif

        @if($geminiEnabled ?? false)
        // Gemini AI - Generate Description (per-language aware)
        $('.generate-description-btn').on('click', function() {
            const btn = $(this);
            const spinner = btn.find('.description-loading');
            const $wrap = btn.closest('.language-fields');
            const langId = $wrap.data('language-id');
            const isDefault = $wrap.hasClass('default-language-fields');

            const $title = isDefault
                ? $('#name-input')
                : $(`input.translation-name[data-lang-id="${langId}"]`);
            const $desc = isDefault
                ? $('#description-input')
                : $(`textarea.translation-description[data-lang-id="${langId}"]`);

            const title = $title.val();
            if (!title) {
                Toastify({ text: '{{ __("Please enter a title first") }}', duration: 3000, close: true, backgroundColor: '#dc3545' }).showToast();
                return;
            }

            btn.prop('disabled', true);
            spinner.removeClass('d-none');

            $.ajax({
                url: '{{ route("gemini.generate-description") }}',
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                data: {
                    title: title,
                    location: $('[name="address"]').val() || '',
                    city: $('[name="city"]').val() || $('[name="city"] option:selected').text() || '',
                    state: $('[name="state"]').val() || $('[name="state"] option:selected').text() || '',
                    country: $('[name="country"] option:selected').text() || '',
                    price: $('[name="price"]').val() || '',
                    category_name: $('#selected-category-breadcrumb').text().trim() || '',
                    currency_iso_code: $('#currency option:selected').data('iso-code') || '',
                    language_id: langId
                },
                success: function(response) {
                    if (!response.error && response.data) {
                        $desc.val(response.data.description);
                        Toastify({ text: '{{ __("Description generated successfully") }}', duration: 3000, close: true, backgroundColor: 'linear-gradient(to right, #00b09b, #96c93d)' }).showToast();
                    } else {
                        Toastify({ text: response.message || '{{ __("Failed to generate description") }}', duration: 3000, close: true, backgroundColor: '#dc3545' }).showToast();
                    }
                },
                error: function(xhr) {
                    const errMsg = xhr.responseJSON?.message || '{{ __("Gemini AI service is currently unavailable. Please try again later.") }}';
                    Toastify({ text: errMsg, duration: 6000, close: true, backgroundColor: '#dc3545' }).showToast();
                },
                complete: function() {
                    btn.prop('disabled', false);
                    spinner.addClass('d-none');
                }
            });
        });

        // Gemini AI - Generate Meta Details
        $('#generate-meta-btn').on('click', function() {
            const btn = $(this);
            const spinner = $('#meta-loading');
            const selectedSeoLangId = $('#seo-language-selector').val();
            const defaultLangId = {{ $defaultLanguage->id }};
            const isDefaultLang = parseInt(selectedSeoLangId) === defaultLangId;

            const title = isDefaultLang
                ? $('#name-input').val()
                : $(`input.translation-name[data-lang-id="${selectedSeoLangId}"]`).val();
            const description = isDefaultLang
                ? $('#description-input').val()
                : $(`textarea.translation-description[data-lang-id="${selectedSeoLangId}"]`).val();

            if (!title || !title.trim() || !description || !description.trim()) {
                Toastify({ text: '{{ __("Please enter title and description for the selected language first") }}', duration: 3000, close: true, backgroundColor: '#dc3545' }).showToast();
                return;
            }

            btn.prop('disabled', true);
            spinner.removeClass('d-none');

            $.ajax({
                url: '{{ route("gemini.generate-meta") }}',
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                data: {
                    title: title,
                    location: $('[name="address"]').val() || '',
                    city: $('[name="city"]').val() || $('[name="city"] option:selected').text() || '',
                    state: $('[name="state"]').val() || $('[name="state"] option:selected').text() || '',
                    country: $('[name="country"] option:selected').text() || '',
                    price: $('[name="price"]').val() || '',
                    currency_iso_code: $('#currency option:selected').data('iso-code') || '',
                    language_id: selectedSeoLangId
                },
                success: function(response) {
                    if (!response.error && response.data) {
                        const langId = selectedSeoLangId;
                        const seoFields = $(`.seo-language-fields[data-seo-language-id="${langId}"]`);
                        seoFields.find(`[name="meta_title[${langId}]"]`).val(response.data.meta_title || '');
                        seoFields.find(`[name="meta_description[${langId}]"]`).val(response.data.meta_description || '');
                        setSeoKeywords(seoFields.find(`[name="meta_keywords[${langId}]"]`), response.data.meta_keywords || '');
                        Toastify({ text: '{{ __("SEO details generated successfully") }}', duration: 3000, close: true, backgroundColor: 'linear-gradient(to right, #00b09b, #96c93d)' }).showToast();
                    } else {
                        Toastify({ text: response.message || '{{ __("Failed to generate SEO details") }}', duration: 3000, close: true, backgroundColor: '#dc3545' }).showToast();
                    }
                },
                error: function(xhr) {
                    const errMsg = xhr.responseJSON?.message || '{{ __("Gemini AI service is currently unavailable. Please try again later.") }}';
                    Toastify({ text: errMsg, duration: 6000, close: true, backgroundColor: '#dc3545' }).showToast();
                },
                complete: function() {
                    btn.prop('disabled', false);
                    spinner.addClass('d-none');
                }
            });
        });

        @if($languages->count() > 1)
        // Auto Translate — open modal
        $('#auto-translate-btn').on('click', function() {
            const name = $('#name-input').val().trim();
            const description = $('#description-input').val().trim();
            if (!name || !description) {
                Toastify({ text: '{{ __("Please enter title and description in the default language first") }}', duration: 3000, close: true, backgroundColor: '#dc3545' }).showToast();
                return;
            }
            new bootstrap.Modal(document.getElementById('autoTranslateModal')).show();
        });

        $('#at-select-all').on('click', function(e) { e.preventDefault(); $('.at-lang-check').prop('checked', true); });
        $('#at-deselect-all').on('click', function(e) { e.preventDefault(); $('.at-lang-check').prop('checked', false); });

        $('#at-confirm-btn').on('click', function() {
            const selectedIds = $('.at-lang-check:checked').map(function() { return $(this).val(); }).get();
            if (!selectedIds.length) {
                Toastify({ text: '{{ __("Please select at least one language") }}', duration: 3000, close: true, backgroundColor: '#dc3545' }).showToast();
                return;
            }

            const btn = $(this);
            const spinner = $('#auto-translate-loading');
            btn.prop('disabled', true);
            spinner.removeClass('d-none');

            const customFields = {};
            $('#custom .custom-fields-language-section[data-language-id="{{ $defaultLanguage->id }}"] .custom-field-input').each(function() {
                const $el = $(this);
                const fieldName = $el.attr('name') || '';
                const match = fieldName.match(/^custom_fields\[(\d+)\]$/);
                if (match && $el.val().trim() !== '') {
                    const elType = $el.attr('type');
                    if (elType === 'text' || elType === 'number') {
                        customFields[match[1]] = $el.val().trim();
                    }
                }
            });

            const formData = new FormData();
            formData.append('_token', $('meta[name="csrf-token"]').attr('content'));
            formData.append('name', $('#name-input').val().trim());
            formData.append('description', $('#description-input').val().trim());
            formData.append('source_language_id', {{ $defaultLanguage->id }});
            selectedIds.forEach(function(id) { formData.append('target_language_ids[]', id); });
            Object.entries(customFields).forEach(function([k, v]) { formData.append(`custom_fields[${k}]`, v); });

            $('#at-error-alert').addClass('d-none').text('');

            $.ajax({
                url: '{{ route("gemini.auto-translate") }}',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (!response.error && response.data && response.data.translations) {
                        const translations = response.data.translations;
                        let firstLangId = null;
                        Object.entries(translations).forEach(function([langId, fields]) {
                            $(`input.translation-name[data-lang-id="${langId}"]`).val(fields.name || '');
                            $(`textarea.translation-description[data-lang-id="${langId}"]`).val(fields.description || '');
                            if (fields.custom_fields && typeof fields.custom_fields === 'object') {
                                Object.entries(fields.custom_fields).forEach(function([fieldId, value]) {
                                    $(`input[name="custom_field_translations[${langId}][${fieldId}]"]`).val(value || '');
                                });
                            }
                            if (!firstLangId) firstLangId = langId;
                        });
                        bootstrap.Modal.getInstance(document.getElementById('autoTranslateModal')).hide();
                        if (firstLangId) $('#details-language-selector').val(firstLangId).trigger('change');
                        Toastify({ text: '{{ __("Content translated successfully") }}', duration: 3000, close: true, backgroundColor: 'linear-gradient(to right, #00b09b, #96c93d)' }).showToast();
                    } else {
                        $('#at-error-alert').removeClass('d-none').text(response.message || '{{ __("Failed to translate content") }}');
                    }
                },
                error: function(xhr) {
                    const msg = xhr.responseJSON?.message || '{{ __("Gemini AI service is currently unavailable. Please try again later.") }}';
                    $('#at-error-alert').removeClass('d-none').text(msg);
                },
                complete: function() {
                    btn.prop('disabled', false);
                    spinner.addClass('d-none');
                }
            });
        });
        @endif
        @endif



        // ── Reel Video / Thumbnail ─────────────────────────────────
        (function () {
            const FRAME_COUNT = 10;
            const MAX_DURATION = parseInt("{{ \App\Services\CachingService::getSystemSettings('reel_max_duration_sec') ?: 60 }}");
            let editModal = null;
            let clipStartTime = 0;
            window.reelTrimmedBlob = null;
            window.reelTrimming = false;
            window.reelActualDuration = 0;
            window.reelTrimDuration = 0;

            function fmtDur(sec) {
                const s = Math.floor(sec);
                return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
            }

            let originalSubmitButtonHtml = null;

            function updateSubmitButtonState() {
                // By id: in the side drawer the list page behind it stays in the DOM, so a
                // class selector could also match another form's submit button.
                const submitBtn = $('#item-submit-btn');
                if (!submitBtn.length) return;
                
                if (originalSubmitButtonHtml === null) {
                    originalSubmitButtonHtml = submitBtn.html();
                }

                if (window.reelTrimming || window.reelUploading) {
                    submitBtn.prop('disabled', true);
                    let label = '{{ __('Please Wait...') }}';
                    submitBtn.html('<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> ' + label);
                } else {
                    submitBtn.prop('disabled', false);
                    submitBtn.html(originalSubmitButtonHtml);
                }
            }

            function setTrimBlocking(blocking) {
                window.reelTrimming = blocking;
                updateSubmitButtonState();
                $('#reel-edit-video-btn,#reel-video-change-btn').prop('disabled', blocking);
                $('#reel-trim-overlay').hide();
                
                const uploadArea = $('#reel-video-upload-area');
                const instructions = uploadArea.find('.upload-instructions');
                const loadingState = uploadArea.find('.upload-loading-state');
                const statusText = uploadArea.find('.reel-upload-status-text');
                const progressBarWrap = uploadArea.find('.progress');
                
                if (blocking) {
                    uploadArea.show();
                    instructions.hide();
                    loadingState.show();
                    progressBarWrap.hide();
                    statusText.text('{{ __('Trimming video...') }}');
                    
                    $('#reel-video-duration').text(fmtDur(window.reelActualDuration));
                    $('#reel-trim-badge').text(fmtDur(window.reelTrimDuration) + ' {{ __('Trimming…') }}').removeClass('text-success').addClass('text-warning').show();
                } else {
                    loadingState.hide();
                    instructions.show();
                    uploadArea.hide();
                    
                    const trimmed = fmtDur(window.reelTrimDuration);
                    const actual  = fmtDur(window.reelActualDuration);
                    $('#reel-video-duration').text(
                        window.reelTrimDuration < window.reelActualDuration
                            ? trimmed + ' / ' + actual
                            : actual
                    );
                    $('#reel-trim-badge').text('{{ __('Ready') }}').removeClass('text-warning').addClass('text-success');
                }
            }

            let _ffmpegInstance = null;
            let _ffmpegScriptPromise = null;
            // ffmpeg.js resolves its worker chunk (814.ffmpeg.js) from document.currentScript.src.
            // A static <script src> breaks that when this page is opened in the side drawer: jQuery .html()
            // fetches external scripts and runs them inline (no src), so the worker 404s. Injecting a real
            // <script> element keeps currentScript.src pointing at the ffmpeg folder.
            function loadFFmpegScript(baseURL) {
                if (window.FFmpegWASM) return Promise.resolve();
                if (!_ffmpegScriptPromise) {
                    _ffmpegScriptPromise = new Promise(function(resolve, reject) {
                        const s = document.createElement('script');
                        s.src = baseURL + '/ffmpeg.js';
                        s.onload = resolve;
                        s.onerror = function() {
                            _ffmpegScriptPromise = null;
                            reject(new Error('Failed to load ffmpeg.js'));
                        };
                        document.head.appendChild(s);
                    });
                }
                return _ffmpegScriptPromise;
            }

            async function getReelFFmpeg() {
                if (_ffmpegInstance) return _ffmpegInstance;
                const baseURL = '{{ asset('assets/js/ffmpeg') }}';
                await loadFFmpegScript(baseURL);
                const { FFmpeg } = FFmpegWASM;
                const { toBlobURL } = FFmpegUtil;
                const ffmpeg = new FFmpeg();
                ffmpeg.on('log', function(l) { console.log('[ffmpeg]', l.message); });
                console.log('[ffmpeg] loading core...');
                await ffmpeg.load({
                    coreURL: await toBlobURL(baseURL + '/ffmpeg-core.js', 'text/javascript'),
                    wasmURL: await toBlobURL(baseURL + '/ffmpeg-core.wasm', 'application/wasm'),
                });
                console.log('[ffmpeg] core loaded');
                _ffmpegInstance = ffmpeg;
                return ffmpeg;
            }

            async function startReelTrim(src, startSec, durationSec) {
                window.reelTrimmedBlob = null;
                window.reelTrimDuration = durationSec;

                setTrimBlocking(true);

                const originalFile = document.getElementById('reel-video-input').files[0];

                try {
                    const ffmpeg = await getReelFFmpeg();
                    const { fetchFile } = FFmpegUtil;
                    console.log('[ffmpeg] reading input file...');
                    const inputData = await fetchFile(originalFile || src);
                    console.log('[ffmpeg] input file read, bytes:', inputData.length);

                    const inName = 'in.mp4';
                    const outName = 'out.mp4';

                    await ffmpeg.writeFile(inName, inputData);
                    console.log('[ffmpeg] wrote to virtual FS, starting copy exec...');

                    // Fast path: stream copy (remux only, no decode/encode — near-instant).
                    // Cut snaps to the nearest keyframe, so boundaries may be off by a bit.
                    // exec() resolves with ffmpeg's exit code instead of throwing, so check it.
                    let copyCode;
                    try {
                        copyCode = await ffmpeg.exec([
                            '-ss', String(startSec),
                            '-i', inName,
                            '-t', String(durationSec),
                            '-c', 'copy',
                            '-avoid_negative_ts', 'make_zero',
                            outName
                        ]);
                    } catch (copyErr) {
                        copyCode = -1;
                        console.log('[ffmpeg] copy exec threw', copyErr);
                    }
                    if (copyCode === 0) {
                        console.log('[ffmpeg] copy exec done');
                    } else {
                        console.log('[ffmpeg] copy failed (exit ' + copyCode + '), falling back to re-encode');
                        // Fallback: re-encode (slow, but frame-accurate / handles unsupported codecs).
                        await ffmpeg.deleteFile(outName).catch(function() {});
                        const encodeCode = await ffmpeg.exec([
                            '-ss', String(startSec),
                            '-i', inName,
                            '-t', String(durationSec),
                            '-c:v', 'libx264',
                            '-preset', 'ultrafast',
                            '-c:a', 'aac',
                            '-movflags', '+faststart',
                            outName
                        ]);
                        if (encodeCode !== 0) throw new Error('ffmpeg re-encode failed (exit ' + encodeCode + ')');
                        console.log('[ffmpeg] re-encode exec done');
                    }

                    const data = await ffmpeg.readFile(outName);
                    console.log('[ffmpeg] output read, bytes:', data.length);
                    // Pass Uint8Array directly — data.buffer may include extra bytes outside the view
                    window.reelTrimmedBlob = new Blob([data], { type: 'video/mp4' });

                    await ffmpeg.deleteFile(inName);
                    await ffmpeg.deleteFile(outName);
                } catch (e) {
                    console.error('Reel trim (ffmpeg.wasm) failed, using original file', e);
                    if (originalFile) {
                        window.reelTrimmedBlob = originalFile;
                    }
                } finally {
                    setTrimBlocking(false);
                }
            }

            window.reelUploading = false;

            // Override formAjaxRequest to inject trimmed blob at submit time and show upload progress
            (function() {
                const _orig = window.formAjaxRequest;
                window.formAjaxRequest = function(type, url, data, formElement, submitBtn, successCb, errorCb) {
                    const isReel = $('#item_type_input').val() === 'reel';
                    const hasVideo = window.reelTrimmedBlob || (document.getElementById('reel-video-input') && document.getElementById('reel-video-input').files.length > 0);

                    if (isReel && hasVideo) {
                        // Check parsley validation
                        let parsley = formElement.parsley({
                            excluded: 'input[type=button], input[type=submit], input[type=reset], :hidden'
                        });
                        parsley.validate();
                        if (!parsley.isValid()) {
                            return false;
                        }

                        // Inject trimmed blob if exists
                        if (window.reelTrimmedBlob && data instanceof FormData) {
                            const ext = window.reelTrimmedBlob.type.includes('mp4') ? 'mp4' : 'webm';
                            data.delete('reel_video');
                            data.append('reel_video', window.reelTrimmedBlob, 'reel.' + ext);
                        }

                        window.reelUploading = true;
                        updateSubmitButtonState();

                        // Show loading/progress in the upload input area
                        const uploadArea = $('#reel-video-upload-area');
                        const instructions = uploadArea.find('.upload-instructions');
                        const loadingState = uploadArea.find('.upload-loading-state');
                        const statusText = uploadArea.find('.reel-upload-status-text');
                        const progressBarWrap = uploadArea.find('.progress');
                        const progressBar = uploadArea.find('.progress-bar');

                        uploadArea.show();
                        instructions.hide();
                        loadingState.show();
                        progressBarWrap.show();
                        statusText.text('{{ __('Uploading video...') }}');
                        progressBar.css('width', '0%').attr('aria-valuenow', 0).text('0%');

                        // Disable Edit/Delete buttons during upload
                        $('#reel-edit-video-btn,#reel-video-change-btn').prop('disabled', true);

                        // Adjust request type for _method PUT/DELETE if needed
                        let ajaxType = type;
                        if (!["get", "post"].includes(type.toLowerCase())) {
                            if (data instanceof FormData) {
                                data.append("_method", type);
                            }
                            ajaxType = "POST";
                        }

                        $.ajax({
                            type: ajaxType,
                            url: url,
                            data: data,
                            cache: false,
                            processData: false,
                            contentType: false,
                            dataType: 'json',
                            xhr: function() {
                                var xhr = new window.XMLHttpRequest();
                                xhr.upload.addEventListener("progress", function(evt) {
                                    if (evt.lengthComputable) {
                                        var percentComplete = Math.round((evt.loaded / evt.total) * 100);
                                        progressBar.css('width', percentComplete + '%').attr('aria-valuenow', percentComplete).text(percentComplete + '%');
                                        statusText.text('{{ __('Uploading video...') }} ' + percentComplete + '%');
                                    }
                                }, false);
                                return xhr;
                            },
                            success: function(response) {
                                try {
                                    if (!response.error) {
                                        response.warning ? showWarningToast(response.message) : showSuccessToast(response.message);
                                        if (successCb != null) {
                                            successCb(response);
                                        }
                                    } else {
                                        showErrorToast(response.message);
                                        if (errorCb != null) {
                                            errorCb(response);
                                        }
                                    }
                                } finally {
                                    window.reelUploading = false;
                                    updateSubmitButtonState();
                                    loadingState.hide();
                                    instructions.show();
                                    uploadArea.hide();
                                    $('#reel-edit-video-btn,#reel-video-change-btn').prop('disabled', false);
                                }
                            },
                            error: function(jqXHR) {
                                if (jqXHR.responseJSON) {
                                    showErrorToast(jqXHR.responseJSON.message);
                                } else {
                                    showErrorToast('{{ __('Something went wrong during upload.') }}');
                                }
                                window.reelUploading = false;
                                updateSubmitButtonState();
                                loadingState.hide();
                                instructions.show();
                                $('#reel-edit-video-btn,#reel-video-change-btn').prop('disabled', false);
                                if (errorCb != null) {
                                    errorCb();
                                }
                            }
                        });
                    } else {
                        // Fallback to original formAjaxRequest
                        if (window.reelTrimmedBlob && data instanceof FormData) {
                            const ext = window.reelTrimmedBlob.type.includes('mp4') ? 'mp4' : 'webm';
                            data.delete('reel_video');
                            data.append('reel_video', window.reelTrimmedBlob, 'reel.' + ext);
                        }
                        return _orig(type, url, data, formElement, submitBtn, successCb, errorCb);
                    }
                };
            })();

            $('#reel-video-upload-area').on('click', function (e) {
                if (window.reelTrimming || window.reelUploading) {
                    e.preventDefault();
                    e.stopPropagation();
                    return;
                }
                if (e.target !== document.getElementById('reel-video-input')) {
                    document.getElementById('reel-video-input').click();
                }
            });

            $('#reel-video-input').on('click', function (e) { e.stopPropagation(); });
            $('#reel-video-btn').on('click', function (e) { e.stopPropagation(); $('#reel-video-input').click(); });

            $('#reel-video-input').on('change', function () {
                const file = this.files[0];
                if (!file) return;
                const blobUrl = URL.createObjectURL(file);
                const video = document.getElementById('reel-video-preview');
                video.src = blobUrl;
                video.load();
                video.onloadedmetadata = function () {
                    clipStartTime = 0;
                    $('#reel-start-time').val(0);
                    $('#reel-video-upload-area').hide();
                    $('#reel-existing-card').css({
                        'opacity': '0.65',
                        'border': '1px dashed #ff9800',
                        'background-color': '#fffaf0'
                    });
                    $('#reel-existing-title').html('{{ __("Current Video Ad") }} <span class="badge bg-warning text-dark ms-2">{{ __("To be replaced") }}</span>');
                    $('#reel-existing-subtitle').text('{{ __("This video ad will be replaced by the new video below on save.") }}');
                    $('#reel-video-filename').text(file.name);
                    const clipDuration = Math.min(MAX_DURATION, video.duration);
                    window.reelActualDuration = video.duration;
                    $('#reel-video-duration').text(fmtDur(video.duration));
                    $('#reel-trim-badge').hide();
                    $('#reel-video-selected').show();

                    // When a new video is selected for replace, clear the delete flag
                    $('#reel-delete-flag').val('0');
                    // item_type should be reel since section is showing
                    $('#item_type_input').val('reel');

                    startReelTrim(blobUrl, 0, clipDuration);
                    captureAt(0, function() { copyThumbToSmall(); });
                };
            });

            // Drag and drop for reel video
            const reelUploadArea = document.getElementById('reel-video-upload-area');
            if (reelUploadArea) {
                reelUploadArea.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    if (window.reelTrimming || window.reelUploading) return;
                    $(this).css('border-color', 'var(--bs-primary)');
                    $(this).css('background', 'rgba(var(--bs-primary-rgb), 0.08)');
                });
                
                reelUploadArea.addEventListener('dragleave', function(e) {
                    e.preventDefault();
                    $(this).css('border-color', '#bbb');
                    $(this).css('background', '#fff');
                });
                
                reelUploadArea.addEventListener('drop', function(e) {
                    e.preventDefault();
                    $(this).css('border-color', '#bbb');
                    $(this).css('background', '#fff');
                    
                    if (window.reelTrimming || window.reelUploading) return;
                    
                    const files = e.dataTransfer.files;
                    if (files.length > 0) {
                        const file = files[0];
                        if (file.type.startsWith('video/')) {
                            const dt = new DataTransfer();
                            dt.items.add(file);
                            const fileInput = document.getElementById('reel-video-input');
                            fileInput.files = dt.files;
                            $(fileInput).trigger('change');
                        } else {
                            Toastify({ text: '{{ __("Please select a valid video file.") }}', duration: 3000, close: true, backgroundColor: '#dc3545' }).showToast();
                        }
                    }
                });
            }

            // ── Two-step modal ─────────────────────────────────────
            let clipSetupDone = false;
            const reelModalEl = document.getElementById('reelEditVideoModal');

            function showStep(n) {
                $('#reel-step-1').toggle(n === 1);
                $('#reel-step-2').toggle(n === 2);
                $('#reel-edit-done-btn').text(n === 1 ? '{{ __('Save Video') }}' : '{{ __('Save Thumbnail') }}');
            }

            reelModalEl.addEventListener('shown.bs.modal', function () {
                showStep(1);
                const video = document.getElementById('reel-video-preview');
                if (!video.src && document.getElementById('reel-video-input').files[0]) {
                    video.src = URL.createObjectURL(document.getElementById('reel-video-input').files[0]);
                }
                if (!clipSetupDone) {
                    const ready = function() {
                        if (clipSetupDone || !video.duration || isNaN(video.duration)) return;
                        clipSetupDone = true;
                        extractFrames(video);
                        setupClipWindow(video, video.duration);
                    };
                    if (video.duration && !isNaN(video.duration)) { ready(); }
                    else { video.onloadedmetadata = ready; }
                } else if (_clipRenderFn) {
                    _clipRenderFn(_savedClipStartTime, _savedClipDuration || MAX_DURATION);
                }
            });

            $('#reel-goto-thumb-btn').on('click', function () {
                buildThumbStrip();
                showStep(2);
            });

            $('#reel-back-to-step1-btn').on('click', function () { showStep(1); });

            $('#reel-edit-video-btn').on('click', function () {
                if (!editModal) editModal = new bootstrap.Modal(reelModalEl);
                editModal.show();
            });

            let _savedClipStartTime = 0;

            function closeReelModal(save) {
                const video = document.getElementById('reel-video-preview');
                if (save && video && video.src) {
                    _savedClipStartTime = clipStartTime;
                    _savedClipDuration  = window.reelTrimDuration || Math.min(MAX_DURATION, video.duration - clipStartTime);
                    $('#reel-start-time').val(clipStartTime.toFixed(3));
                    window.reelActualDuration = video.duration;
                    startReelTrim(video.src, clipStartTime, _savedClipDuration);
                } else {
                    clipStartTime = _savedClipStartTime;
                    if (_clipRenderFn) _clipRenderFn(_savedClipStartTime, _savedClipDuration || MAX_DURATION);
                }
                if (editModal) editModal.hide();
            }

            $('#reel-modal-cancel-btn, #reel-modal-x-btn').on('click', function () {
                closeReelModal(false);
            });

            $('#reel-edit-done-btn').on('click', function () {
                const onStep2 = $('#reel-step-2').is(':visible');
                if (onStep2) {
                    copyThumbToSmall();
                    closeReelModal(true);
                } else {
                    buildThumbStrip();
                    showStep(2);
                }
            });

            $('#reel-video-change-btn').on('click', function () {
                $('#reel-video-selected').hide();
                const uploadArea = $('#reel-video-upload-area');
                uploadArea.find('.upload-instructions').show();
                uploadArea.find('.upload-loading-state').hide();
                uploadArea.show();
                
                $('#reel-existing-card').css({
                    'opacity': '1',
                    'border': '1px solid #dee2e6',
                    'background-color': '#f9f9f9'
                });
                $('#reel-existing-title').text('{{ __("Current Video Ad") }}');
                $('#reel-existing-subtitle').text('{{ __("Upload a new video below to replace it.") }}');
                
                $('#reel-video-input').val('');
                document.getElementById('reel-video-preview').src = '';
                document.getElementById('reel-timeline').innerHTML = '';
                $('#reel-thumbnail-time').val('0');
                $('#reel-thumbnail-custom').val('');
                $('#reel-start-time').val('0');
                clipStartTime = 0;
                clipSetupDone = false;
                $('#reel-clip-window,#reel-dim-left,#reel-dim-right').hide();
                $('#reel-thumb-img').attr('src','').hide();
                $('#reel-thumb-placeholder').show();
            });

            // ── Thumbnail scrubber ─────────────────────────────────
            let thumbScrubVideo = null;

            function captureAt(time, callback) {
                const video = document.getElementById('reel-video-preview');
                if (!video || !video.src) return;

                const isNew = !thumbScrubVideo || thumbScrubVideo.src !== video.src;
                if (isNew) {
                    thumbScrubVideo = document.createElement('video');
                    thumbScrubVideo.src = video.src;
                    thumbScrubVideo.muted = true;
                    thumbScrubVideo.preload = 'auto';
                    thumbScrubVideo.crossOrigin = 'anonymous';
                }

                function doSeekAndCapture() {
                    thumbScrubVideo.onseeked = function() {
                        const vw = thumbScrubVideo.videoWidth  || 720;
                        const vh = thumbScrubVideo.videoHeight || 1280;
                        const off = document.createElement('canvas');
                        off.width = vw; off.height = vh;
                        const ctx = off.getContext('2d');
                        ctx.imageSmoothingEnabled = true;
                        ctx.imageSmoothingQuality = 'high';
                        ctx.drawImage(thumbScrubVideo, 0, 0, vw, vh);
                        const dataUrl = off.toDataURL('image/jpeg', 0.92);
                        showThumb(dataUrl);
                        $('#reel-thumbnail-time').val(time.toFixed(3));
                        $('#reel-thumbnail-custom').val('');
                        thumbScrubVideo.onseeked = null;
                        if (typeof callback === 'function') callback(dataUrl);
                    };
                    thumbScrubVideo.currentTime = time;
                }

                if (isNew) {
                    thumbScrubVideo.onloadedmetadata = function() {
                        thumbScrubVideo.onloadedmetadata = null;
                        doSeekAndCapture();
                    };
                } else {
                    doSeekAndCapture();
                }
            }

            function showThumb(dataUrl) {
                const img = document.getElementById('reel-thumb-img');
                img.src = dataUrl;
                img.style.display = 'block';
                document.getElementById('reel-thumb-placeholder').style.display = 'none';
                $('#reel-thumbnail-data').val(dataUrl);
            }

            function copyThumbToSmall(dataUrl) {
                const src = dataUrl || document.getElementById('reel-thumb-img').src;
                if (!src) return;
                const small = document.getElementById('reel-thumb-preview-small');
                const i = new Image();
                i.onload = function() {
                    small.width = 40; small.height = 60;
                    small.getContext('2d').drawImage(i, 0, 0, 40, 60);
                };
                i.src = src;
            }

            function fmtTime(t) {
                return Math.floor(t / 60) + ':' + String(Math.floor(t % 60)).padStart(2, '0');
            }

            // ── Clip-only thumbnail strip ───────────────────────────
            function buildThumbStrip() {
                const strip = document.getElementById('reel-thumb-strip');
                strip.innerHTML = '';
                const video = document.getElementById('reel-video-preview');
                if (!video.src || !video.duration) return;

                const THUMB_N   = 5;
                const start     = clipStartTime;
                const clipDur   = window.reelTrimDuration || Math.min(MAX_DURATION, video.duration - start);
                const end       = Math.min(start + clipDur, video.duration);
                const stripW  = strip.parentElement.offsetWidth || 400;
                const frameW  = Math.floor((stripW - (THUMB_N - 1) * 4) / THUMB_N);
                const frameH  = 70;

                const times = [], wrappers = [], canvases = [];
                for (let i = 0; i < THUMB_N; i++) {
                    const t = start + ((end - start) / (THUMB_N - 1)) * i;
                    times.push(t);

                    const w = document.createElement('div');
                    w.style.cssText = 'flex-shrink:0;border-radius:6px;overflow:hidden;cursor:pointer;border:2px solid transparent;width:' + frameW + 'px;height:' + frameH + 'px;background:#111;';
                    w.dataset.time = t;

                    const c = document.createElement('canvas');
                    c.width = frameW; c.height = frameH;
                    c.style.cssText = 'width:100%;height:100%;display:block;';
                    w.appendChild(c);
                    strip.appendChild(w);
                    wrappers.push(w); canvases.push(c);

                    (function(el, time) {
                        el.addEventListener('click', function() {
                            captureAt(time);
                            strip.querySelectorAll('div[data-time]').forEach(function(x) { x.style.borderColor = 'transparent'; });
                            el.style.borderColor = 'var(--bs-primary)';
                        });
                    })(w, t);
                }

                extractFramesSeq(video.src, times, function(idx, vid) {
                    canvases[idx].getContext('2d').drawImage(vid, 0, 0, frameW, frameH);
                }, function() {
                    if (wrappers[0]) wrappers[0].click();
                });
            }

            function extractFramesSeq(src, times, onFrame, onDone) {
                const tmp = document.createElement('video');
                tmp.src     = src;
                tmp.muted   = true;
                tmp.preload = 'auto';
                let idx = 0;
                tmp.onloadedmetadata = function() { seekNext(); };
                tmp.onseeked = function() {
                    onFrame(idx, tmp);
                    idx++;
                    if (idx < times.length) seekNext();
                    else { if (onDone) onDone(); tmp.src = ''; }
                };
                function seekNext() { tmp.currentTime = times[idx]; }
            }

            function extractFrames(video) {
                const duration = video.duration;
                const timeline = document.getElementById('reel-timeline');
                timeline.innerHTML = '';

                const tlWidth = timeline.parentElement.offsetWidth || 500;
                const frameW  = Math.floor(tlWidth / FRAME_COUNT);
                const frameH  = 60;

                const times = [], canvases = [];
                for (let i = 0; i < FRAME_COUNT; i++) {
                    const t = i === 0 ? 0 : (duration / (FRAME_COUNT - 1)) * i;
                    times.push(t);
                    const c = document.createElement('canvas');
                    c.width  = frameW;
                    c.height = frameH;
                    c.style.cssText = 'flex-shrink:0;width:' + frameW + 'px;height:' + frameH + 'px;display:block;';
                    timeline.appendChild(c);
                    canvases.push(c);
                }

                extractFramesSeq(video.src, times, function(idx, vid) {
                    canvases[idx].getContext('2d').drawImage(vid, 0, 0, frameW, frameH);
                });
            }

            let _clipRenderFn = null;
            let _savedClipDuration = null;

            function setupClipWindow(video, duration) {
                const clipWin  = document.getElementById('reel-clip-window');
                const dimLeft  = document.getElementById('reel-dim-left');
                const dimRight = document.getElementById('reel-dim-right');
                const playhead = document.getElementById('reel-playhead');
                const tl       = document.getElementById('reel-timeline');

                const MIN_SEC = 1;
                let tlW   = tl.offsetWidth;
                let maxPx = Math.min(Math.round((MAX_DURATION / duration) * tlW), tlW);
                let minPx = Math.max(1, Math.ceil((MIN_SEC / duration) * tlW));

                let clipLeft = 0;
                let clipPx   = maxPx;

                clipWin.style.display = 'block';
                dimLeft.style.display = dimRight.style.display = 'block';

                const fmt = t => Math.floor(t/60) + ':' + String(Math.floor(t%60)).padStart(2,'0');

                function render(left, width) {
                    width    = Math.max(minPx, Math.min(width, maxPx));
                    clipLeft = Math.max(0, Math.min(left, tlW - width));
                    clipPx   = width;

                    clipWin.style.left  = clipLeft + 'px';
                    clipWin.style.width = clipPx   + 'px';

                    dimLeft.style.width  = clipLeft + 'px';
                    dimRight.style.left  = (clipLeft + clipPx) + 'px';
                    dimRight.style.width = (tlW - clipLeft - clipPx) + 'px';
                    dimLeft.style.display  = clipLeft > 0 ? 'block' : 'none';
                    dimRight.style.display = (clipLeft + clipPx < tlW) ? 'block' : 'none';

                    clipStartTime = tlW > 0 ? (clipLeft / tlW) * duration : 0;
                    if (!isFinite(clipStartTime)) clipStartTime = 0;

                    const clipDurSec = (clipPx / tlW) * duration;
                    const endSec     = clipStartTime + clipDurSec;

                    window.reelTrimDuration = clipDurSec;
                    $('#reel-start-time').val(clipStartTime.toFixed(2));
                    $('#reel-clip-info').text(fmt(clipStartTime) + ' – ' + fmt(endSec) + '  (' + fmtDur(clipDurSec) + ')');

                    if (!video.paused) video.pause();
                    if (isFinite(clipStartTime)) video.currentTime = clipStartTime;
                }

                _clipRenderFn = function(startSec, durSec) {
                    const left  = tlW > 0 ? (startSec / duration) * tlW : 0;
                    const width = tlW > 0 ? (Math.min(durSec || MAX_DURATION, MAX_DURATION) / duration) * tlW : maxPx;
                    render(left, width);
                };

                // Recompute px bounds when the container resizes (e.g. DevTools open/close,
                // viewport resize) — stale absolute px otherwise pokes outside the modal.
                window.addEventListener('resize', function() {
                    const newTlW = tl.offsetWidth;
                    if (!newTlW || newTlW === tlW) return;
                    tlW   = newTlW;
                    maxPx = Math.min(Math.round((MAX_DURATION / duration) * tlW), tlW);
                    minPx = Math.max(1, Math.ceil((MIN_SEC / duration) * tlW));
                    _clipRenderFn(clipStartTime, window.reelTrimDuration || _savedClipDuration || MAX_DURATION);
                });

                _clipRenderFn(_savedClipStartTime, _savedClipDuration || MAX_DURATION);

                video.ontimeupdate = function() {
                    const clipDurSec = (clipPx / tlW) * duration;
                    const elapsed    = video.currentTime - clipStartTime;
                    playhead.style.left = (Math.min(elapsed / clipDurSec, 1) * (clipPx - 3)) + 'px';
                    if (video.currentTime >= clipStartTime + clipDurSec) {
                        video.pause();
                        video.currentTime = clipStartTime;
                    }
                };

                let drag = null;

                function dragStart(clientX, type) {
                    drag = { type, startX: clientX, startLeft: clipLeft, startWidth: clipPx };
                    if (type === 'window') clipWin.style.cursor = 'grabbing';
                }
                function dragMove(clientX) {
                    if (!drag) return;
                    const dx = clientX - drag.startX;
                    if (drag.type === 'window') {
                        render(drag.startLeft + dx, drag.startWidth);
                    } else if (drag.type === 'left') {
                        render(drag.startLeft + dx, drag.startWidth - dx);
                    } else {
                        render(drag.startLeft, drag.startWidth + dx);
                    }
                }
                function dragEnd() { drag = null; clipWin.style.cursor = 'grab'; }

                const hleft  = document.getElementById('reel-handle-left');
                const hright = document.getElementById('reel-handle-right');

                hleft.addEventListener('mousedown',  e => { e.stopPropagation(); e.preventDefault(); dragStart(e.clientX, 'left'); });
                hright.addEventListener('mousedown', e => { e.stopPropagation(); e.preventDefault(); dragStart(e.clientX, 'right'); });
                clipWin.addEventListener('mousedown', e => {
                    if (hleft.contains(e.target) || hright.contains(e.target)) return;
                    e.preventDefault(); dragStart(e.clientX, 'window');
                });
                hleft.addEventListener('touchstart',  e => { e.stopPropagation(); dragStart(e.touches[0].clientX, 'left'); }, {passive:true});
                hright.addEventListener('touchstart', e => { e.stopPropagation(); dragStart(e.touches[0].clientX, 'right'); }, {passive:true});
                clipWin.addEventListener('touchstart', e => {
                    if (hleft.contains(e.target) || hright.contains(e.target)) return;
                    dragStart(e.touches[0].clientX, 'window');
                }, {passive:true});

                document.addEventListener('mousemove', e => dragMove(e.clientX));
                document.addEventListener('touchmove', e => dragMove(e.touches[0].clientX), {passive:true});
                document.addEventListener('mouseup',  dragEnd);
                document.addEventListener('touchend', dragEnd, {passive:true});
            }

            // Custom thumbnail upload
            $('#reel-thumb-upload-btn').on('click', function () { $('#reel-thumb-custom-input').click(); });
            $('#reel-thumb-custom-input').on('change', function () {
                const file = this.files[0];
                if (!file) return;
                const reader = new FileReader();
                reader.onload = function (e) {
                    showThumb(e.target.result);
                    $('#reel-thumbnail-custom').val(e.target.result);
                    $('#reel-thumbnail-time').val('');
                    document.querySelectorAll('#reel-timeline canvas').forEach(c => c.style.borderColor = 'transparent');
                    copyThumbToSmall(e.target.result);
                };
                reader.readAsDataURL(file);
            });
        })();
    </script>
@endsection
