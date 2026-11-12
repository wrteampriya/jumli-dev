{{--
    Add Custom Field form — custom-fields/create, full page and side drawer (loaded
    over AJAX). Same building blocks as the category and listing forms (ec-* / cat-*).
--}}
@php
    $defaultLanguage = $languages->firstWhere('id', 1) ?? $languages->first();
    $langDir = fn ($lang) => $lang->rtl ? 'rtl' : 'ltr';
    $fieldTypes = [
        'textbox' => ['icon' => 'ph-text-aa', 'label' => __('Text Input'), 'text' => __('Short or long free text')],
        'number' => ['icon' => 'ph-hash', 'label' => __('Number Input'), 'text' => __('Digits only')],
        'dropdown' => ['icon' => 'ph-caret-circle-down', 'label' => __('Dropdown'), 'text' => __('Pick one from a list')],
        'radio' => ['icon' => 'ph-radio-button', 'label' => __('Radio'), 'text' => __('Pick one, shown as pills')],
        'checkbox' => ['icon' => 'ph-check-square', 'label' => __('Checkboxes'), 'text' => __('Pick any number')],
        'fileinput' => ['icon' => 'ph-paperclip', 'label' => __('File Input'), 'text' => __('Upload an image or document')],
    ];
@endphp
<form action="{{ route('custom-fields.store') }}" method="POST" id="custom-field-create-form" class="create-form category-form custom-field-form"
    data-success-function="afterCustomFieldCreationSuccess" data-parsley-validate enctype="multipart/form-data">
    @csrf
    @foreach ($languages as $lang)
        <input type="hidden" name="languages[]" value="{{ $lang->id }}">
    @endforeach

    {{-- Module (shown with 2+ modules) --}}
    @if ($modules->count() > 1)
        <div class="cat-module-box mb-4">
            <label for="module_id" class="form-label">{{ __('Module') }} <span class="text-danger">*</span></label>
            <select name="module_id" id="module_id" class="form-select" data-parsley-required="true">
                <option value="">{{ __('Select a Module') }}</option>
                @foreach ($modules as $module)
                    <option value="{{ $module->id }}" @selected($module->id == $categoryModuleId)>{{ $module->name }}</option>
                @endforeach
            </select>
            <small class="cat-module-hint"><i class="ph-fill ph-info"></i> {{ __('The field is offered on listings of this module.') }}</small>
        </div>
    @endif

    {{-- Field details --}}
    <x-lang-pills class="mb-3" :languages="$languages" select="custom-field-language-selector" :selected="$defaultLanguage->id" />

    @foreach ($languages as $lang)
        <div class="cat-lang-pane mb-4" data-lang-id="{{ $lang->id }}" @if ($lang->id != $defaultLanguage->id) style="display: none;" @endif>
            <label class="form-label" for="cf-name-{{ $lang->id }}">{{ __('Field name') }} ({{ $lang->name }})
                @if ($lang->id == 1)<span class="text-danger">*</span>@endif
            </label>
            <input type="text" name="name[{{ $lang->id }}]" id="cf-name-{{ $lang->id }}" dir="{{ $langDir($lang) }}" class="form-control"
                placeholder="{{ __('e.g. Brand') }}" maxlength="255" @if ($lang->id == 1) data-parsley-required="true" @endif>
        </div>
    @endforeach

    <label class="form-label">{{ __('Field type') }} <span class="text-danger">*</span></label>
    <div class="cf-type-grid mb-4">
        @foreach ($fieldTypes as $type => $meta)
            <label class="cat-type-card cf-type-card">
                <input class="form-check-input" type="radio" name="type" value="{{ $type }}" @checked($loop->first) required>
                <span class="cf-type-icon" aria-hidden="true"><i class="ph-bold {{ $meta['icon'] }}"></i></span>
                <span><strong>{{ $meta['label'] }}</strong><small>{{ $meta['text'] }}</small></span>
            </label>
        @endforeach
    </div>

    {{-- Options: dropdown / radio / checkboxes. Other languages translate them one-to-one. --}}
    <div class="cf-options-section mb-4" data-cf-for="dropdown radio checkbox">
        <label class="form-label">{{ __('Options') }} <span class="text-danger">*</span></label>
        @foreach ($languages as $lang)
            <div class="cat-lang-pane" data-lang-id="{{ $lang->id }}" @if ($lang->id != $defaultLanguage->id) style="display: none;" @endif>
                <select name="values[{{ $lang->id }}][]" dir="{{ $langDir($lang) }}" data-tags="true" data-width="100%" data-allow-clear="true"
                    data-token-separators="[',']" data-placeholder="{{ __('Type an option and press Enter') }}"
                    class="select2 cf-values-select" multiple="multiple" @if ($lang->id == 1) data-cf-required-values="true" @endif></select>
                <small class="text-muted d-block mt-1">
                    @if ($lang->id == 1)
                        {{ __('Press Enter or comma after each option.') }}
                    @else
                        {{ __('Optional: translate the options in the same order (:language).', ['language' => $lang->name]) }}
                    @endif
                </small>
            </div>
        @endforeach
    </div>

    {{-- Length limits: text (characters) / number (digits) --}}
    <div class="row mb-2" data-cf-for="textbox number">
        <div class="col-md-6 mb-3">
            <label class="form-label" for="cf-min-length">{{ __('Minimum length') }}</label>
            <input type="number" name="min_length" id="cf-min-length" class="form-control" min="0" placeholder="{{ __('No minimum') }}">
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label" for="cf-max-length">{{ __('Maximum length') }}</label>
            <input type="number" name="max_length" id="cf-max-length" class="form-control" min="0" placeholder="{{ __('No maximum') }}">
        </div>
        <div class="col-12 mb-3 mt-n2">
            <small class="text-muted cf-length-hint" data-text="{{ __('Counted in characters.') }}" data-number="{{ __('Counted in digits.') }}"></small>
        </div>
    </div>

    <div class="mb-4">
        <label class="form-label">{{ __('Field icon') }} <span class="ec-section-hint fw-normal small">SVG</span></label>
        <label class="ec-dropzone cat-image-dropzone" for="cf-image">
            <span class="cat-image-empty">
                <span class="ec-dropzone-icon cat-upload-icon"><i class="ph-fill ph-cloud-arrow-up"></i></span>
                <p><span class="ec-dropzone-link">{{ __('Upload an icon') }}</span> {{ __('or drag and drop') }}</p>
                <p class="ec-dropzone-or">{{ __('SVG, 256 × 256 recommended') }}</p>
            </span>
            <span class="cat-image-preview" style="display: none;">
                <img src="" alt="" data-no-auto-error>
                <span class="small text-muted cat-image-name"></span>
                <button type="button" class="btn btn-sm btn-outline-danger cat-image-remove">{{ __('Remove') }}</button>
            </span>
            <input type="file" name="image" id="cf-image" class="d-none" accept=".svg,image/svg+xml">
        </label>
    </div>

    {{-- Settings --}}
    <div class="cat-toggle-card mb-3">
        <div>
            <strong>{{ __('Required') }}</strong>
            <small>{{ __('Sellers must fill this field to post a listing.') }}</small>
        </div>
        <div class="form-check form-switch m-0">
            <input type="hidden" name="required" id="required" value="0">
            <input class="form-check-input status-switch" type="checkbox" role="switch" id="cfRequiredSwitch" aria-label="{{ __('Required') }}">
        </div>
    </div>
    <div class="cat-toggle-card mb-4">
        <div>
            <strong>{{ __('Field status') }}</strong>
            <small>{{ __('Inactive fields stay saved but aren\'t shown on listings.') }}</small>
        </div>
        <div class="form-check form-switch m-0">
            <input type="hidden" name="status" id="status" value="1">
            <input class="form-check-input status-switch" type="checkbox" role="switch" id="cfStatusSwitch" checked aria-label="{{ __('Active') }}">
        </div>
    </div>

    {{-- Categories the field applies to (fixed when opened from one category) --}}
    @if ($cat_id == 0)
        @include('category.category-scope', [
            'categories' => $categories,
            'selected_categories' => $selected_categories ?? [],
            'selected_all_categories' => $selected_all_categories ?? [],
            'wrapper_id' => 'category_selection_scope',
            'title' => __('Applies to categories'),
            'subtitle' => __('Select the categories where this custom field should be applied.'),
        ])
    @else
        <input type="hidden" name="selected_categories[]" value="{{ $cat_id }}">
    @endif

    <div class="form-actions d-flex justify-content-end gap-2 mt-4">
        <a href="{{ route('custom-fields.index') }}" class="btn btn-light border cf-cancel-btn">{{ __('Cancel') }}</a>
        <button type="submit" class="btn btn-primary">{{ __('Save Changes') }}</button>
    </div>
</form>
<script>
    (function () {
        var form = document.getElementById('custom-field-create-form');
        if (!form) return;

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

            // Language pills: one hidden select drives the name and option panes.
            $form.find('#custom-field-language-selector').on('change', function () {
                var langId = String(this.value);
                $form.find('.cat-lang-pane').each(function () {
                    $(this).toggle(String($(this).data('lang-id')) === langId);
                });
            });

            // Option tag inputs (the drawer initialises .select2 itself).
            if (!$drawer.length) {
                $form.find('.cf-values-select').each(function () {
                    if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({width: '100%'});
                });
            }

            // Field type: options for dropdown / radio / checkboxes, length limits for
            // text / number, nothing extra for files. Hidden inputs are disabled so
            // they are not sent.
            var syncType = function () {
                var type = $form.find('input[name="type"]:checked').val();
                $form.find('[data-cf-for]').each(function () {
                    var show = String($(this).data('cf-for')).split(' ').indexOf(type) !== -1;
                    $(this).toggle(show).find('input, select').prop('disabled', !show);
                });
                $form.find('[data-cf-required-values]').attr('data-parsley-required', ['dropdown', 'radio', 'checkbox'].indexOf(type) !== -1 ? 'true' : 'false');
                var $hint = $form.find('.cf-length-hint');
                $hint.text(type === 'number' ? $hint.data('number') : $hint.data('text'));
            };
            $form.on('change', 'input[name="type"]', syncType);
            syncType();

            // Required / Active toggles: keep the hidden value in sync.
            $form.on('change', '.status-switch', function () {
                $(this).siblings('input[type="hidden"]').val(this.checked ? 1 : 0);
            });

            // Icon dropzone: preview, drag & drop, remove.
            var $drop = $form.find('.cat-image-dropzone');
            var $file = $form.find('#cf-image');
            var showImage = function () {
                var file = $file[0].files && $file[0].files[0];
                $drop.toggleClass('has-file', !!file);
                $drop.find('.cat-image-empty').toggle(!file);
                $drop.find('.cat-image-preview').toggle(!!file);
                if (file) {
                    $drop.find('img').attr('src', URL.createObjectURL(file));
                    $drop.find('.cat-image-name').text(file.name);
                }
            };
            $file.on('change', showImage);
            $drop.on('dragover', function (e) { e.preventDefault(); $drop.addClass('is-dragover'); })
                .on('dragleave drop', function () { $drop.removeClass('is-dragover'); })
                .on('drop', function (e) {
                    e.preventDefault();
                    var files = e.originalEvent.dataTransfer.files;
                    if (files.length && /svg/.test(files[0].type)) {
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
            $form.on('click', '.cf-cancel-btn', function (e) {
                if ($drawer.length && window.SideDrawer) {
                    e.preventDefault();
                    window.SideDrawer.close();
                }
            });

            // Module change: reload the category scope with that module's categories from the
            // shared lookup — only one module is rendered server-side.
            $form.on('change', '#module_id', function () {
                var root = $form.find('.cs-scope-root')[0];
                if (!root || !window.CategoryScope || !this.value) return;
                $.get("{{ route('lookup.categories') }}", {module_id: this.value}, function (response) {
                    window.CategoryScope.render(root, response.data || []);
                });
            });

            // Category tree: a checkbox click inside a header label must not toggle the row.
            $form.on('click', '.category-header label', function (e) {
                if ($(e.target).is('input[type="checkbox"]')) e.stopPropagation();
            });

            // Show the language of the first invalid field.
            $form.parsley().on('form:error', function () {
                this.fields.some(function (field) {
                    if (field.isValid()) return false;
                    var $pane = $(field.element).closest('.cat-lang-pane');
                    if ($pane.length && !$pane.is(':visible')) {
                        $form.find('#custom-field-language-selector').val(String($pane.data('lang-id'))).trigger('change');
                    }
                    return true;
                });
            });
        }
    })();

    // Full page: back to the list after saving (the drawer refreshes the list itself).
    function afterCustomFieldCreationSuccess() {
        setTimeout(function () {
            window.location.href = "{{ route('custom-fields.index') }}";
        }, 1000);
    }
</script>
