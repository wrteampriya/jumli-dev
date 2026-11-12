{{--
    Add / Edit Category form — category/create and category/edit, full page and side
    drawer (loaded over AJAX). $category: the category being edited (null on create);
    edit also passes $translations, $seoTranslations and $excludedParentIds.
--}}
@php
    $category ??= null;
    $isEdit = $category !== null;
    $translations ??= [];
    $seoTranslations ??= [];
    $excludedParentIds ??= collect();
    $defaultLanguage = $languages->firstWhere('id', 1) ?? $languages->first();
    $langDir = fn ($lang) => $lang->rtl ? 'rtl' : 'ltr';
    $isSub = $isEdit ? ! empty($category->parent_category_id) : ! empty($parentId);
    $statusOn = $isEdit ? (int) $category->status === 1 : true;
    $jobOn = $isEdit && (int) $category->is_job_category === 1;
    $currentImage = $isEdit ? $category->image : null;
    $formId = $isEdit ? 'category-edit-form' : 'category-create-form';
@endphp
<form action="{{ $isEdit ? route('category.update', $category->id) : route('category.store') }}" method="POST" id="{{ $formId }}"
    class="category-form" data-parsley-validate enctype="multipart/form-data">
    @csrf
    @if ($isEdit)
        @method('PUT')
        <input type="hidden" name="edit_data" value="{{ $category->id }}">
    @endif
    @foreach ($languages as $lang)
        <input type="hidden" name="languages[]" value="{{ $lang->id }}">
    @endforeach

    {{-- Module: parent entity of categories (shown with 2+ modules) --}}
    @if ($modules->count() > 1)
        <div class="cat-module-box mb-4">
            <label for="module_id" class="form-label">{{ __('Module') }} <span class="text-danger">*</span></label>
            <select name="module_id" id="module_id" class="form-select" data-parsley-required="true">
                <option value="">{{ __('Select a Module') }}</option>
                @foreach ($modules as $module)
                    <option value="{{ $module->id }}" @selected($isEdit ? $module->id == $category->module_id : $module->isDefault())>{{ $module->name }}</option>
                @endforeach
            </select>
            <small class="cat-module-hint"><i class="ph-fill ph-info"></i> {{ __('This module controls the category structure and available listing rules.') }}</small>
        </div>
    @endif

    {{-- Category type: main (no parent) or subcategory (parent picked below) --}}
    <label class="form-label">{{ __('Category type') }}</label>
    <div class="cat-type-grid mb-4">
        <label class="cat-type-card">
            <input class="form-check-input" type="radio" name="category_type" value="main" @checked(! $isSub)>
            <span><strong>{{ __('Main category') }}</strong><small>{{ __('Top-level category') }}</small></span>
        </label>
        <label class="cat-type-card">
            <input class="form-check-input" type="radio" name="category_type" value="sub" @checked($isSub)>
            <span><strong>{{ __('Subcategory') }}</strong><small>{{ __('Nested under a parent') }}</small></span>
        </label>
    </div>
    @if ($isEdit && $isSub)
        <div id="parent_category_warning" class="alert alert-warning py-2 small mb-4" style="display: none;">
            <i class="ph-fill ph-warning me-1"></i>
            {{ __('Warning: Clearing the parent will make this a top-level (Root) category. All subcategories under it will keep their place but their stored paths will be recalculated.') }}
        </div>
    @endif

    <x-lang-pills class="mb-3" :languages="$languages" select="category-language-selector" :selected="$defaultLanguage->id" />

    <div class="row">
        <div class="col-md-6 mb-3" id="cat-name-col">
            @foreach ($languages as $lang)
                <div class="cat-lang-pane" data-lang-id="{{ $lang->id }}" @if ($lang->id != $defaultLanguage->id) style="display: none;" @endif>
                    <label class="form-label" for="cat-name-{{ $lang->id }}">{{ __('Name') }} ({{ $lang->name }})
                        @if ($lang->id == 1)<span class="text-danger">*</span>@endif
                    </label>
                    <input type="text" name="name[{{ $lang->id }}]" id="cat-name-{{ $lang->id }}" dir="{{ $langDir($lang) }}" class="form-control"
                        value="{{ $translations[$lang->id]['name'] ?? '' }}"
                        placeholder="{{ __('e.g. Luxury Cars') }}" maxlength="191" data-parsley-maxlength="191"
                        data-parsley-maxlength-message="{{ __('Name cannot exceed 191 characters.') }}"
                        @if ($lang->id == 1) data-parsley-required="true" @endif>
                </div>
            @endforeach
        </div>

        <div class="col-md-6 mb-3" id="cat-parent-col" @unless ($isSub) style="display: none;" @endunless>
            <label for="p_category" class="form-label">{{ __('Parent category') }} <span class="text-danger">*</span></label>
            <select name="parent_category_id" id="p_category" class="form-select" data-placeholder="{{ __('Select Category') }}">
                <option value="">{{ __('Select a Category') }}</option>
                @include('category.dropdowntree', ['categories' => $categories])
            </select>
        </div>
    </div>

    <div class="mb-4">
        <label for="category_slug" class="form-label">{{ __('Slug') }} <span class="ec-section-hint fw-normal small">{{ __('English only') }}</span></label>
        <input type="text" name="slug" id="category_slug" dir="ltr" class="form-control" data-parsley-pattern="^[a-zA-Z0-9\-_]+$"
            data-parsley-pattern-message="{{ __('Slug must be only English letters, numbers, hyphens (-) or underscores (_).') }}"
            value="{{ $isEdit ? $category->getRawOriginal('slug') : '' }}" placeholder="{{ __('auto-generated if blank') }}">
        <small class="text-muted d-block mt-1">{{ __('Use lowercase letters, numbers, and hyphens.') }}</small>
    </div>

    <div class="mb-4">
        <label class="form-label">{{ __('Category image') }}</label>
        <label class="ec-dropzone cat-image-dropzone {{ $currentImage ? 'has-file' : '' }}" for="image">
            <span class="cat-image-empty" @if ($currentImage) style="display: none;" @endif>
                <span class="ec-dropzone-icon cat-upload-icon"><i class="ph-fill ph-cloud-arrow-up"></i></span>
                <p><span class="ec-dropzone-link">{{ __('Upload an image') }}</span> {{ __('or drag and drop') }}</p>
                <p class="ec-dropzone-or">{{ __('PNG, JPG up to 7MB') }}</p>
            </span>
            <span class="cat-image-preview" @unless ($currentImage) style="display: none;" @endunless>
                <img src="{{ $currentImage }}" alt="">
                <span class="small text-muted cat-image-name">{{ $currentImage ? __('Current image — choose a file to replace it') : '' }}</span>
                <button type="button" class="btn btn-sm btn-outline-danger cat-image-remove" @if ($currentImage) style="display: none;" @endif>{{ __('Remove') }}</button>
            </span>
            <input type="file" name="image" id="image" class="d-none" accept=".jpg,.jpeg,.png">
        </label>
    </div>

    <div class="cat-toggle-card mb-3">
        <div>
            <strong>{{ __('Category status') }}</strong>
            <small>{{ __('Hidden categories stay saved but won\'t appear in the app.') }}</small>
        </div>
        <div class="form-check form-switch m-0">
            <input type="hidden" name="status" id="status" value="{{ $statusOn ? 1 : 0 }}">
            <input class="form-check-input status-switch" type="checkbox" role="switch" id="statusSwitch" @checked($statusOn) aria-label="{{ __('Active') }}">
        </div>
    </div>

    {{-- Shown only for modules that support range pricing (server enforces it too). --}}
    <div class="cat-toggle-card mb-3" data-category-toggle="is_job_category">
        <div>
            <strong>{{ __('Job category') }}</strong>
            <small>{{ __('Listings in this category use salary ranges.') }}</small>
            @if ($isEdit)
                <div id="job_category_warning" class="alert alert-warning py-2 small mt-2 mb-0" style="display: none;">
                    <i class="ph-fill ph-warning me-1"></i>
                    {{ __('Warning: Changing the Job Category setting will affect all items linked to this category. Please update their prices accordingly.') }}
                </div>
            @endif
        </div>
        <div class="form-check form-switch m-0">
            <input type="hidden" name="is_job_category" id="is_job_category" value="{{ $jobOn ? 1 : 0 }}">
            <input class="form-check-input status-switch" type="checkbox" role="switch" id="jobCategorySwitch" @checked($jobOn) aria-label="{{ __('Job category') }}">
        </div>
    </div>

    <hr class="my-4">

    <div class="cat-seo-head mb-3">
        <span class="ec-section-icon" aria-hidden="true"><i class="ph-bold ph-magnifying-glass"></i></span>
        <div>
            <h5 class="ec-section-title">{{ __('SEO details') }}</h5>
            <p class="ec-section-subtitle">{{ __('Improve how this category appears in search results.') }}</p>
        </div>
    </div>
    <x-lang-pills class="mb-3" :languages="$languages" select="category-language-selector" :selected="$defaultLanguage->id" :with-select="false" />

    @foreach ($languages as $lang)
        @php
            $seo = $seoTranslations[$lang->id] ?? [];
        @endphp
        <div class="cat-lang-pane" data-lang-id="{{ $lang->id }}" @if ($lang->id != $defaultLanguage->id) style="display: none;" @endif>
            <div class="mb-3">
                <label class="form-label">{{ __('Meta title') }} <span class="ec-section-hint fw-normal small">({{ $lang->name }})</span></label>
                <input type="text" name="meta_title[{{ $lang->id }}]" dir="{{ $langDir($lang) }}" class="form-control" value="{{ $seo['meta_title'] ?? '' }}" placeholder="{{ __('Category title for search engines') }}">
            </div>
            <div class="mb-3">
                <label class="form-label">{{ __('Meta description') }} <span class="ec-section-hint fw-normal small">({{ $lang->name }})</span></label>
                <textarea name="meta_description[{{ $lang->id }}]" dir="{{ $langDir($lang) }}" class="form-control" rows="2" placeholder="{{ __('A short description for search results') }}">{{ $seo['meta_description'] ?? '' }}</textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">{{ __('Meta keywords') }} <span class="ec-section-hint fw-normal small">({{ $lang->name }})</span></label>
                {{-- Tagify via .tagify-input (custom.js / side drawer), as before. --}}
                <input type="text" name="meta_keywords[{{ $lang->id }}]" dir="{{ $langDir($lang) }}" class="tagify-input form-control" value="{{ $seo['meta_keywords'] ?? '' }}" placeholder="{{ __('cars, luxury cars, vehicles') }}">
                <small class="text-muted d-block mt-1">{{ __('Separate keywords with commas.') }}</small>
            </div>
            <div class="mb-3">
                <label class="form-label">{{ __('Schema') }} <span class="ec-section-hint fw-normal small">JSON-LD</span></label>
                <textarea name="schema[{{ $lang->id }}]" dir="ltr" class="form-control font-monospace" rows="4" placeholder='{"@@context": "https://schema.org"}'>{{ $seo['schema'] ?? '' }}</textarea>
                <small class="text-muted d-block mt-1">{{ __('Optional structured data for search engines.') }}</small>
            </div>
        </div>
    @endforeach

    <div class="form-actions d-flex justify-content-end gap-2 mt-4">
        <a href="{{ route('category.index') }}" class="btn btn-light border cat-cancel-btn">{{ __('Cancel') }}</a>
        <button type="submit" class="btn btn-primary">{{ __('Save Changes') }}</button>
    </div>
</form>
<script>
    (function () {
        var form = document.getElementById(@json($formId));
        if (!form) return;

        // On the full page, jQuery/select2 can finish loading after this script
        // runs — poll for them. Inside the side drawer they're already present.
        (function waitForJQuery(tries) {
            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2 && window.jQuery.fn.parsley) {
                init(window.jQuery);
            } else if (tries < 100) {
                setTimeout(function () { waitForJQuery(tries + 1); }, 100);
            }
        })(0);

        function init($) {
            var $form = $(form);
            var $drawer = $form.closest('.offcanvas');
            var $pCategory = $form.find('#p_category');
            var $module = $form.find('#module_id');
            var parentModules = @json($parentModules);
            var isEdit = @json($isEdit);
            // Edit: the category itself and its own subcategories can't be its parent.
            var excludedParents = @json($excludedParentIds).map(String);
            if (excludedParents.length) {
                $pCategory.find('option').filter(function () {
                    return excludedParents.indexOf(String(this.value)) !== -1;
                }).remove();
            }

            // Language pills: one hidden select drives the Name and SEO panes.
            $form.find('#category-language-selector').on('change', function () {
                var langId = String(this.value);
                $form.find('.cat-lang-pane').each(function () {
                    $(this).toggle(String($(this).data('lang-id')) === langId);
                });
            });

            if ($pCategory.hasClass('select2-hidden-accessible')) {
                $pCategory.select2('destroy');
            }
            $pCategory.select2({
                allowClear: true,
                width: '100%',
                placeholder: "{{ __('Select Category') }}",
                // Inside the drawer the dropdown must live in the offcanvas,
                // or its focus trap blocks typing in the search box.
                dropdownParent: $drawer.length ? $drawer : $(document.body)
            });

            // Category type: a main category has no parent; a subcategory needs one.
            var syncType = function () {
                var isSub = $form.find('input[name="category_type"]:checked').val() === 'sub';
                $form.find('#cat-parent-col').toggle(isSub);
                $pCategory.attr('data-parsley-required', isSub ? 'true' : 'false');
                if (!isSub && $pCategory.val()) {
                    $pCategory.val('').trigger('change');
                }
                // Edit: an existing subcategory turned into a main category.
                $form.find('#parent_category_warning').toggle(!isSub);
            };
            $form.on('change', 'input[name="category_type"]', syncType);

            // The module is the parent entity of categories: the Parent category list
            // only shows categories of the selected module. (A subcategory still
            // inherits its parent's module server-side via Category::booted().)
            if ($module.length) {
                var $allParentOptions = $pCategory.find('option').clone();
                var filterParentsByModule = function () {
                    var moduleId = String($module.val() || '');
                    var selected = $pCategory.val();
                    $pCategory.empty().append($allParentOptions.filter(function () {
                        return !this.value || String(parentModules[this.value] ?? '') === moduleId;
                    }).clone());
                    $pCategory.val($pCategory.find('option[value="' + selected + '"]').length ? selected : '')
                        .trigger('change');
                };

                @if (! $isEdit && ! empty($parentId))
                    // Opened from a category's subcategory list: start on that parent's module.
                    $module.val(String(parentModules[{{ (int) $parentId }}] ?? $module.val()));
                @endif
                filterParentsByModule();
                $module.on('change', filterParentsByModule);
            }
            @if (! empty($parentId))
                $pCategory.val('{{ (int) $parentId }}').trigger('change');
            @endif
            syncType();

            // Job category only shows when the category's module supports range
            // pricing; a hidden toggle is switched off. On edit, one the category already
            // has on stays while it keeps its module. The server enforces the same rule.
            var categoryToggles = @json($moduleCategoryToggles);
            var savedToggles = @json(['is_job_category' => $jobOn]);
            var savedModuleId = String(@json($defaultModuleId));
            var syncCategoryToggles = function () {
                var parentId = $pCategory.val();
                var moduleId = String(parentId ? parentModules[parentId] : ($module.val() || savedModuleId));
                var allowed = categoryToggles[moduleId] || {};
                $form.find('[data-category-toggle]').each(function () {
                    var $toggle = $(this);
                    var key = $toggle.data('category-toggle');
                    var isAllowed = !!allowed[key] || (isEdit && moduleId === savedModuleId && savedToggles[key]);
                    $toggle.toggleClass('d-none', !isAllowed);
                    if (!isAllowed) {
                        $toggle.find('input[type="checkbox"]').prop('checked', false);
                        $toggle.find('input[type="hidden"]').val(0);
                    }
                });
            };
            syncCategoryToggles();
            $pCategory.on('change', syncCategoryToggles);
            $module.on('change', syncCategoryToggles);

            // Status / Job toggles: keep the hidden value in sync (the drawer also
            // has its own .status-switch handler — both set the same value).
            $form.on('change', '.status-switch', function () {
                $(this).siblings('input[type="hidden"]').val(this.checked ? 1 : 0);
            });
            $form.on('change', '#jobCategorySwitch', function () {
                $form.find('#job_category_warning').show();
            });

            // Image dropzone: preview, drag & drop, remove a newly picked file
            // (on edit the current image stays unless a new one is chosen).
            var $drop = $form.find('.cat-image-dropzone');
            var $file = $form.find('#image');
            var currentImage = @json($currentImage);
            var showImage = function () {
                var file = $file[0].files && $file[0].files[0];
                var hasImage = !!file || !!currentImage;
                $drop.toggleClass('has-file', hasImage);
                $drop.find('.cat-image-empty').toggle(!hasImage);
                $drop.find('.cat-image-preview').toggle(hasImage);
                $drop.find('.cat-image-remove').toggle(!!file);
                if (file) {
                    $drop.find('img').attr('src', URL.createObjectURL(file));
                    $drop.find('.cat-image-name').text(file.name);
                } else if (currentImage) {
                    $drop.find('img').attr('src', currentImage);
                    $drop.find('.cat-image-name').text("{{ __('Current image — choose a file to replace it') }}");
                }
            };
            $file.on('change', showImage);
            $drop.on('dragover', function (e) { e.preventDefault(); $drop.addClass('is-dragover'); })
                .on('dragleave drop', function () { $drop.removeClass('is-dragover'); })
                .on('drop', function (e) {
                    e.preventDefault();
                    var files = e.originalEvent.dataTransfer.files;
                    if (files.length && /^image\//.test(files[0].type)) {
                        var dt = new DataTransfer();
                        dt.items.add(files[0]);
                        $file[0].files = dt.files;
                        showImage();
                    }
                });
            $drop.on('click', '.cat-image-remove', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $file.val('');
                showImage();
            });

            // Cancel: close the drawer (full page: back to the list).
            $form.on('click', '.cat-cancel-btn', function (e) {
                if ($drawer.length && window.SideDrawer) {
                    e.preventDefault();
                    window.SideDrawer.close();
                }
            });

            // Full page only: plain POST, so disable the button once valid.
            // The drawer submits over AJAX and handles its own button state.
            if (!$drawer.length) {
                $form.on('submit', function () {
                    if ($form.parsley().isValid()) {
                        $form.find(':submit').prop('disabled', true).text('{{ __('Saving...') }}');
                    }
                });
            }

            // Show the language of the first invalid field.
            $form.parsley().on('form:error', function () {
                this.fields.some(function (field) {
                    if (field.isValid()) return false;
                    var $pane = $(field.element).closest('.cat-lang-pane');
                    if ($pane.length && !$pane.is(':visible')) {
                        $form.find('#category-language-selector').val(String($pane.data('lang-id'))).trigger('change');
                    }
                    return true;
                });
            });
        }
    })();
</script>
