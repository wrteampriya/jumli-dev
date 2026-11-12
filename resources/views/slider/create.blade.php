@extends('layouts.main')

@section('title')
    {{ __('Create Slider') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h4>@yield('title')</h4>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <div class="buttons">
            <a class="btn btn-primary" href="{{ route('slider.index') }}">
                < {{ __('Back to Slider') }} </a>
        </div>
        <div class="card">
            <div class="card-body">
                {{-- Same building blocks as the category form (.category-form cat-* + ec-*). --}}
                <form method="POST" action="{{ route('slider.store') }}" enctype="multipart/form-data" class="create-form category-form slider-form"
                    id="slider-form" data-pre-submit-function="sliderValidateForm" data-parsley-validate>
                    @csrf

                    {{-- CONTENT --}}
                    <h6 class="ec-subhead">{{ __('Content') }}</h6>
                    <div class="mb-4">
                        <label class="form-label">{{ __('Slider Image') }} <span class="text-danger">*</span></label>
                        <label class="ec-dropzone cat-image-dropzone" for="image">
                            <span class="cat-image-empty">
                                <span class="ec-dropzone-icon cat-upload-icon"><i class="ph-fill ph-image"></i></span>
                                <p>{{ __('Drag & drop or') }} <span class="ec-dropzone-link">{{ __('Choose Image') }}</span></p>
                                <p class="ec-dropzone-or">{{ __('Recommended size: 1200 × 400 px. JPG, PNG, WebP.') }}</p>
                            </span>
                            <span class="cat-image-preview slider-image-preview" style="display: none;">
                                <img src="" alt="" data-no-auto-error>
                                <span class="small text-muted cat-image-name"></span>
                                <button type="button" class="btn btn-sm btn-outline-danger cat-image-remove">{{ __('Remove') }}</button>
                            </span>
                            <input type="file" name="image" id="image" class="d-none" accept=".jpg,.jpeg,.png,.webp" data-parsley-required="true"
                                data-parsley-errors-container="#slider-image-errors" data-parsley-required-message="{{ __('Please choose a slider image.') }}">
                        </label>
                        <div id="slider-image-errors"></div>
                    </div>

                    {{-- BASIC INFORMATION --}}
                    <h6 class="ec-subhead">{{ __('Basic Information') }}</h6>
                    <div class="mb-4">
                        <label class="form-label" for="slider_name">{{ __('Slider Name') }} <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="slider_name" class="form-control" maxlength="255" data-parsley-required="true"
                            placeholder="{{ __('e.g., Summer Car Deals - Sept') }}">
                        <small class="text-muted d-block mt-1">{{ __('This is for your internal reference.') }}</small>
                    </div>

                    {{-- DISPLAY --}}
                    <h6 class="ec-subhead">{{ __('Display') }}</h6>
                    @if ($modules->count() > 1)
                        <div class="cat-module-box mb-4">
                            <label for="module_id" class="form-label">{{ __('Module Context') }} <span class="text-danger">*</span></label>
                            <select name="module_id" id="module_id" class="form-select" data-parsley-required="true">
                                <option value="">{{ __('Select a module context') }}</option>
                                @foreach ($modules as $module)
                                    <option value="{{ $module->id }}" @selected($module->isDefault())>{{ $module->name }}</option>
                                @endforeach
                            </select>
                            <small class="cat-module-hint"><i class="ph-fill ph-info"></i> {{ __('Determines which homepages this can appear on, and filters destination options.') }}</small>
                        </div>
                    @endif
                    <div class="mb-4">
                        <label class="form-label">{{ __('Platform') }} <span class="text-danger">*</span></label>
                        <div class="d-flex flex-wrap gap-4">
                            <label class="form-check m-0">
                                <input type="hidden" name="show_on_web" value="0">
                                <input class="form-check-input slider-platform" type="checkbox" name="show_on_web" value="1" checked>
                                <span class="form-check-label">{{ __('Web Interface') }}</span>
                            </label>
                            <label class="form-check m-0">
                                <input type="hidden" name="show_on_app" value="0">
                                <input class="form-check-input slider-platform" type="checkbox" name="show_on_app" value="1" checked>
                                <span class="form-check-label">{{ __('Mobile App') }}</span>
                            </label>
                        </div>
                    </div>

                    {{-- DESTINATION: exactly one of listing / category / external link --}}
                    <h6 class="ec-subhead">{{ __('Destination') }}</h6>
                    <div class="slider-dest-grid mb-3">
                        <label class="cat-type-card">
                            <input class="form-check-input" type="radio" name="destination_type" value="item" checked>
                            <span><strong>{{ __('Specific Listing') }}</strong><small>{{ __('Link to a single item') }}</small></span>
                        </label>
                        <label class="cat-type-card">
                            <input class="form-check-input" type="radio" name="destination_type" value="category">
                            <span><strong>{{ __('Category') }}</strong><small>{{ __('Link to a category page') }}</small></span>
                        </label>
                        <label class="cat-type-card">
                            <input class="form-check-input" type="radio" name="destination_type" value="link">
                            <span><strong>{{ __('External Link') }}</strong><small>{{ __('URL outside the app') }}</small></span>
                        </label>
                    </div>
                    <div class="slider-panel mb-4" data-destination="item">
                        <label class="form-label" for="items">{{ __('Search Listing') }}</label>
                        {{-- Type-to-search through lookup.items (approved listings of the chosen module). --}}
                        <select name="item" id="items" class="form-select" data-placeholder="{{ __('Type to search listings in selected module...') }}">
                            <option value=""></option>
                        </select>
                    </div>
                    <div class="slider-panel mb-4" data-destination="category" style="display: none;">
                        <label class="form-label mb-1" for="category">{{ __('Category Context') }}</label>
                        @if ($modules->count() > 1)
                            <small class="slider-filter-note d-block mb-2"><i class="ph-fill ph-funnel"></i> {{ __('Filtered by:') }} <strong id="slider-module-name"></strong></small>
                        @endif
                        <select name="category_id" id="category" class="form-select" data-placeholder="{{ __('Select Category') }}" data-category-module="{{ $categoryModuleId }}">
                            <option value="">{{ __('Select Category') }}</option>
                            @include('category.dropdowntree', ['categories' => $categories])
                        </select>
                    </div>
                    <div class="slider-panel mb-4" data-destination="link" style="display: none;">
                        <label class="form-label" for="link">{{ __('External URL') }}</label>
                        <div class="slider-url-field" dir="ltr">
                            <i class="ph-bold ph-link" aria-hidden="true"></i>
                            <input type="url" name="link" id="link" dir="ltr" class="form-control" placeholder="https://example.com/promo"
                                data-parsley-type="url" data-parsley-type-message="{{ __('Enter a valid URL, e.g. https://example.com/promo') }}">
                        </div>
                    </div>
                    <div class="invalid-form-error-message text-danger small mb-3"></div>

                    {{-- LOCATION (OPTIONAL) --}}
                    <h6 class="ec-subhead">{{ __('Location (Optional)') }}</h6>
                    <div class="d-flex flex-wrap gap-4 mb-3">
                        <label class="form-check m-0">
                            <input class="form-check-input" type="radio" name="location_scope" value="all" checked>
                            <span class="form-check-label">{{ __('Worldwide (All)') }}</span>
                        </label>
                        <label class="form-check m-0">
                            <input class="form-check-input" type="radio" name="location_scope" value="specific">
                            <span class="form-check-label">{{ __('Specific Location') }}</span>
                        </label>
                    </div>
                    <div class="slider-panel slider-location mb-4" style="display: none;">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="slider_country">{{ __('Country') }}</label>
                                <select class="form-select" id="slider_country" name="country_id" disabled>
                                    <option value="">{{ __('Any') }}</option>
                                    @foreach ($countries as $country)
                                        <option value="{{ $country->id }}">{{ $country->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="slider_state">{{ __('State/Region') }}</label>
                                <select class="form-select" id="slider_state" name="state_id" disabled>
                                    <option value="">{{ __('Any') }}</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="slider_city">{{ __('City') }}</label>
                                <select class="form-select" id="slider_city" name="city_id" disabled>
                                    <option value="">{{ __('Any') }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    {{-- STATUS --}}
                    <h6 class="ec-subhead">{{ __('Status') }}</h6>
                    <div class="slider-status-row mb-2">
                        <div>
                            <strong>{{ __('Slider Status') }}</strong>
                            <small id="slider-status-text" data-on="{{ __('Active sliders can be shown on selected homepages.') }}"
                                data-off="{{ __('Inactive sliders will not be shown anywhere.') }}">{{ __('Active sliders can be shown on selected homepages.') }}</small>
                        </div>
                        <div class="form-check form-switch m-0">
                            <input type="hidden" name="status" id="status" value="1">
                            <input class="form-check-input status-switch" type="checkbox" role="switch" id="sliderStatusSwitch" checked aria-label="{{ __('Slider Status') }}">
                        </div>
                    </div>

                    <div class="form-actions d-flex justify-content-end gap-2 mt-4">
                        <a href="{{ route('slider.index') }}" class="btn btn-light border slider-cancel-btn">{{ __('Cancel') }}</a>
                        <button type="submit" class="btn btn-primary">{{ __('Save Slider') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
@endsection

@section('script')
    <script>
        (function () {
            const $form = $('#slider-form');
            if (!$form.length) return;
            const $drawer = $form.closest('.offcanvas');
            const dropdownParent = $drawer.length ? $drawer : $(document.body);
            const $module = $form.find('#module_id');
            const $items = $form.find('#items');
            const $category = $form.find('#category');

            // Destination: exactly one is sent — the other panels' inputs are disabled.
            function syncDestination() {
                const type = $form.find('input[name="destination_type"]:checked').val();
                $form.find('[data-destination]').each(function () {
                    const show = $(this).data('destination') === type;
                    $(this).toggle(show).find('input, select').prop('disabled', !show);
                });
                $form.find('.invalid-form-error-message').text('');
            }
            $form.on('change', 'input[name="destination_type"]', syncDestination);

            // Specific listing: type-to-search approved listings of the chosen module.
            $items.select2({
                width: '100%',
                allowClear: true,
                dropdownParent: dropdownParent,
                placeholder: $items.data('placeholder'),
                minimumInputLength: 0,
                ajax: {
                    url: "{{ route('lookup.items') }}",
                    dataType: 'json',
                    delay: 250,
                    data: params => Object.assign({search: params.term || '', limit: 20}, $module.val() ? {module_id: $module.val()} : {}),
                    processResults: response => ({results: (response.data || []).map(item => ({id: item.id, text: item.name}))}),
                    // No module context chosen yet: nothing to list (the lookup would return every module's listings).
                    transport: function (params, success, failure) {
                        if ($module.length && !$module.val()) {
                            success({data: []});
                            return {abort: $.noop};
                        }
                        const request = $.ajax(params);
                        request.then(success, failure);
                        return request;
                    },
                },
            });
            $category.select2({width: '100%', allowClear: true, dropdownParent: dropdownParent, placeholder: $category.data('placeholder')});

            // Module context: filters the listing search and the category list.
            function syncModule() {
                const moduleId = $module.val() || '';
                $form.find('#slider-module-name').text($module.find('option:selected').val() ? $module.find('option:selected').text() : "{{ __('Select a module context') }}");
                $items.select2('close');
                $items.val(null).trigger('change');
                loadCategoryOptionsForModule($category, moduleId, {requireModule: true});
            }
            $module.on('change', syncModule);
            $form.find('#slider-module-name').text($module.find('option:selected').val() ? $module.find('option:selected').text() : "{{ __('Select a module context') }}");

            // Location: worldwide sends nothing; specific enables country → state → city.
            function fillSelect($select, url) {
                $select.html($('<option>', {value: '', text: "{{ __('Any') }}"}));
                if (!url) return;
                ajaxRequest('GET', url, null, null, function (response) {
                    $.each(response.data, function (key, value) {
                        $select.append($('<option>', {value: value.id, text: value.name}));
                    });
                });
            }
            $form.on('change', 'input[name="location_scope"]', function () {
                const specific = this.value === 'specific';
                $form.find('.slider-location').toggle(specific).find('select').prop('disabled', !specific);
            });
            $form.on('change', '#slider_country', function () {
                fillSelect($form.find('#slider_state'), this.value ? window.baseurl + 'states/search?country_id=' + this.value : null);
                fillSelect($form.find('#slider_city'), null);
            });
            $form.on('change', '#slider_state', function () {
                fillSelect($form.find('#slider_city'), this.value ? window.baseurl + 'cities/search?state_id=' + this.value : null);
            });

            // Status toggle: hidden value + helper text.
            $form.on('change', '#sliderStatusSwitch', function () {
                $form.find('#status').val(this.checked ? 1 : 0);
                const $text = $form.find('#slider-status-text');
                $text.text(this.checked ? $text.data('on') : $text.data('off'));
            });

            // Image dropzone: preview, drag & drop, remove.
            const $drop = $form.find('.cat-image-dropzone');
            const $file = $form.find('#image');
            function showImage() {
                const file = $file[0].files && $file[0].files[0];
                $drop.toggleClass('has-file', !!file);
                $drop.find('.cat-image-empty').toggle(!file);
                $drop.find('.cat-image-preview').toggle(!!file);
                if (file) {
                    $drop.find('img').attr('src', URL.createObjectURL(file));
                    $drop.find('.cat-image-name').text(file.name);
                }
            }
            $file.on('change', showImage);
            $drop.on('dragover', e => { e.preventDefault(); $drop.addClass('is-dragover'); })
                .on('dragleave drop', () => $drop.removeClass('is-dragover'))
                .on('drop', function (e) {
                    e.preventDefault();
                    const files = e.originalEvent.dataTransfer.files;
                    if (files.length && /^image\//.test(files[0].type)) {
                        const dt = new DataTransfer();
                        dt.items.add(files[0]);
                        $file[0].files = dt.files;
                        showImage();
                        $file.trigger('change');
                    }
                });
            $drop.on('click', '.cat-image-remove', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $file.val('');
                showImage();
            });

            // Cancel: close the drawer (full page: back to the list).
            $form.on('click', '.slider-cancel-btn', function (e) {
                if ($drawer.length && window.SideDrawer) {
                    e.preventDefault();
                    window.SideDrawer.close();
                }
            });

            // Before submit (form handler / drawer): one destination and one platform.
            window.sliderValidateForm = function () {
                const type = $form.find('input[name="destination_type"]:checked').val();
                const value = {item: $items.val(), category: $category.val(), link: $.trim($form.find('#link').val())}[type];
                const $error = $form.find('.invalid-form-error-message');
                if (!value) {
                    $error.text({
                        item: "{{ __('Please select a listing.') }}",
                        category: "{{ __('Please select a category.') }}",
                        link: "{{ __('Please enter the external URL.') }}",
                    }[type]);
                    return false;
                }
                if (!$form.find('.slider-platform:checked').length) {
                    showErrorToast("{{ __('Select at least one platform.') }}");
                    return false;
                }
                $error.text('');
                return true;
            };

            syncDestination();
        })();
    </script>
@endsection
