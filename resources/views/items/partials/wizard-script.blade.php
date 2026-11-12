{{--
    Shared Create / Edit Listing wizard logic, included inside each page's main
    $(document).ready closure (it uses the page's `languages`, `defaultLanguageId`,
    `selectedCategory*`, `hasCustomFields`, `refreshTabLocks`, `updateCustomFieldsStatus`):
    SEO keyword tags, the Category step, the custom-field renderer (pre-filled from
    saved values on edit) and the Review step.

    $wizardTabs: stepper selector ('#addItemTabs' / '#editItemTabs'); $wizardItemId: the
    listing being edited (null on create) — custom fields then load with its saved values.
--}}
            const WIZARD_ITEM_ID = @json($wizardItemId ?? null);
            const WIZARD_IS_EDIT = WIZARD_ITEM_ID !== null;

            // SEO keywords as tags. The input keeps a comma-separated string — the
            // format store() saves — instead of Tagify's default JSON.
            $('.seo-keywords-input').each(function() {
                if (typeof Tagify === 'undefined' || this._tagify) return;
                this._tagify = new Tagify(this, {
                    delimiters: ',',
                    duplicate: false,
                    editTags: true,
                    dropdown: {enabled: 0},
                    originalInputValueFormat: values => values.map(item => item.value).join(','),
                });
            });
            window.setSeoKeywords = function($input, value) {
                const input = $input[0];
                if (input && input._tagify) {
                    input._tagify.removeAllTags();
                    input._tagify.addTags(String(value || '').split(',').map(v => v.trim()).filter(Boolean));
                } else {
                    $input.val(value);
                }
            };

            // ── Category step ─────────────────────────────────────
            // Drill down to a leaf category: parents open their subcategories in the
            // same grid, only a leaf (no subcategories) can be selected. Each level is
            // cached, the breadcrumb jumps to any ancestor, and the selection survives
            // navigating levels / leaving the step. Changing the module resets it all.
            const CATEGORY_PAGE_SIZE = 24;
            const CATEGORY_PLACEHOLDER = @json($placeholderImage);
            let categoryTrail = [];          // ancestors of the level shown: [{id, name}]
            let categoryLevels = {};         // parentId|'root' → {items, page, hasMore}
            let selectedCategoryTrail = [];  // ancestors of the selected leaf
            let categoryLoading = false;
            let categoryRequestToken = 0;

            const PATH_SEPARATOR = document.documentElement.dir === 'rtl' ? ' ‹ ' : ' › ';
            const escapeHtml = (value) => $('<div>').text(value == null ? '' : String(value)).html();
            const levelKey = (parentId) => parentId === null ? 'root' : String(parentId);

            function moduleName() {
                return $('#module .module-option.selected').data('module-name') || '';
            }

            function categoryTileHtml(category) {
                const hasSubcategories = category.subcategories_count > 0;
                const selected = !hasSubcategories && String(category.id) === String(selectedCategoryId);
                const image = category.image || CATEGORY_PLACEHOLDER;
                return `
                    <button type="button" class="ec-option-card ec-option-tile category-card${selected ? ' selected' : ''}"
                            data-category-id="${escapeHtml(category.id)}" data-category-name="${escapeHtml(category.name)}"
                            data-has-subcategories="${hasSubcategories ? 1 : 0}">
                        <span class="ec-option-corner"><span class="ec-option-check" aria-hidden="true"><i class="ph-bold ph-check"></i></span></span>
                        <span class="ec-option-icon is-image" aria-hidden="true"><img src="${escapeHtml(image)}" alt="" data-custom-image="${escapeHtml(CATEGORY_PLACEHOLDER)}"></span>
                        <span class="ec-option-title">${escapeHtml(category.name)}</span>
                        ${hasSubcategories ? '<i class="ph-bold ph-caret-right ec-tile-chevron" aria-hidden="true"></i>' : ''}
                    </button>`;
            }

            function renderCategoryState(message, retry) {
                $('#categories-list').html(`<div class="ec-cat-state">${escapeHtml(message)}${retry
                    ? ` <button type="button" class="ec-link-btn ms-1" id="categories-retry-btn">{{ __('Retry') }}</button>` : ''}</div>`);
            }

            function renderCategoryNav() {
                const parentId = currentParentId;
                $('#category-step-scope').text(categoryTrail.length ? categoryTrail[categoryTrail.length - 1].name : moduleName());
                $('#breadcrumb-container').toggle(categoryTrail.length > 0);
                let html = `<li class="breadcrumb-item"><a href="#" class="category-crumb" data-depth="-1">{{ __('All categories') }}</a></li>`;
                categoryTrail.forEach(function (crumb, i) {
                    html += i === categoryTrail.length - 1
                        ? `<li class="breadcrumb-item active" aria-current="page">${escapeHtml(crumb.name)}</li>`
                        : `<li class="breadcrumb-item"><a href="#" class="category-crumb" data-depth="${i}">${escapeHtml(crumb.name)}</a></li>`;
                });
                $('#category-breadcrumb').html(html);
                const level = categoryLevels[levelKey(parentId)];
                $('#load-more-container').toggle(!!(level && level.hasMore));
            }

            function renderSelectedCategoryLine() {
                const path = selectedCategoryId ? selectedCategoryPath.join(PATH_SEPARATOR) : '';
                $('#selected-category-text').text(path);
                $('#selected-category-line').toggleClass('d-none', !path);
            }

            function renderCategoryLevel() {
                const level = categoryLevels[levelKey(currentParentId)];
                if (!level) return;
                if (!level.items.length) {
                    renderCategoryState(window.trans('No categories found.'));
                } else {
                    $('#categories-list').html(level.items.map(categoryTileHtml).join(''));
                }
                renderCategoryNav();
            }

            // Fetch one page of the current level and append it to its cache.
            function fetchCategoryPage(page) {
                const parentId = currentParentId;
                const key = levelKey(parentId);
                const token = ++categoryRequestToken;
                categoryLoading = true;
                if (page === 1) {
                    $('#categories-list').html('<div class="ec-tile-skeleton"></div>'.repeat(8));
                    $('#load-more-container').hide();
                }

                $.ajax({
                    url: parentId ? '{{ route('advertisement.get-subcategories') }}' : '{{ route('advertisement.get-parent-categories') }}',
                    type: 'GET',
                    data: {category_id: parentId, module_id: $('#module_id_input').val(), page: page, per_page: CATEGORY_PAGE_SIZE},
                    success: function (response) {
                        if (token !== categoryRequestToken) return; // a newer level was opened meanwhile
                        const level = categoryLevels[key] || {items: [], page: 0, hasMore: false};
                        level.items = page === 1 ? (response.data || []) : level.items.concat(response.data || []);
                        level.page = page;
                        level.hasMore = !!response.has_more && (response.data || []).length > 0;
                        categoryLevels[key] = level;
                        renderCategoryLevel();
                        // A short page leaves the end marker in view: keep filling the area.
                        setTimeout(function () {
                            const sentinel = document.getElementById('categories-sentinel');
                            const scroller = document.getElementById('categories-scroll');
                            if (level.hasMore && sentinel && scroller && $('#categories').hasClass('active')
                                && sentinel.getBoundingClientRect().top < scroller.getBoundingClientRect().bottom + 160) {
                                loadMoreCategories();
                            }
                        }, 0);
                    },
                    error: function () {
                        if (token !== categoryRequestToken) return;
                        if (page === 1) {
                            renderCategoryState(window.trans('Error loading categories.'), true);
                        } else {
                            showErrorToast(window.trans('Error loading categories.'));
                        }
                    },
                    complete: function () {
                        if (token === categoryRequestToken) categoryLoading = false;
                    }
                });
            }

            // Show the level below `trail` (root when empty), from cache when visited before.
            function openCategoryLevel(trail) {
                categoryTrail = trail.slice();
                currentParentId = categoryTrail.length ? categoryTrail[categoryTrail.length - 1].id : null;
                categoryRequestToken++;
                categoryLoading = false;
                const level = categoryLevels[levelKey(currentParentId)];
                if (level) {
                    renderCategoryLevel();
                } else {
                    renderCategoryNav();
                    fetchCategoryPage(1);
                }
                const scroller = document.getElementById('categories-scroll');
                if (scroller) scroller.scrollTop = 0;
            }
            window.loadCategories = function () { openCategoryLevel(categoryTrail); };

            function loadMoreCategories() {
                const level = categoryLevels[levelKey(currentParentId)];
                if (level && level.hasMore && !categoryLoading) {
                    fetchCategoryPage(level.page + 1);
                }
            }
            $('#load-more-categories').on('click', loadMoreCategories);

            // Auto-load the next page when the end of the grid scrolls into view.
            if ('IntersectionObserver' in window && document.getElementById('categories-sentinel')) {
                new IntersectionObserver(function (entries) {
                    if (entries.some(e => e.isIntersecting) && $('#categories').hasClass('active')) {
                        loadMoreCategories();
                    }
                }, {root: document.getElementById('categories-scroll'), rootMargin: '0px 0px 160px 0px'})
                    .observe(document.getElementById('categories-sentinel'));
            }

            function hasListingDetailValues() {
                return $('#custom').find('.custom-field-input, .custom-field-checkbox, .custom-field-input-translation').filter(function () {
                    if (this.type === 'checkbox' || this.type === 'radio') return this.checked;
                    if (this.type === 'file') return this.files && this.files.length > 0;
                    return $.trim($(this).val() || '') !== '';
                }).length > 0;
            }

            function selectCategory(categoryId, categoryName) {
                if (!categoryId) return;
                selectedCategoryId = categoryId;
                selectedCategoryName = categoryName;
                selectedCategoryTrail = categoryTrail.slice();
                selectedCategoryPath = [...categoryTrail.map(c => c.name), categoryName];
                $('#category_id').val(categoryId);
                updateSelectedCategoryDisplay(selectedCategoryPath.join(', '));
                renderSelectedCategoryLine();
                $('#categories-list .category-card').each(function () {
                    $(this).toggleClass('selected', String($(this).data('category-id')) === String(categoryId) && $(this).data('has-subcategories') == 0);
                });
                // Custom fields for step 5.
                loadCustomFields(categoryId);
                refreshTabLocks();
            }

            $(document).on('click', '#categories-list .category-card', function () {
                const categoryId = $(this).data('category-id');
                const categoryName = String($(this).data('category-name'));
                if ($(this).data('has-subcategories') == 1) {
                    openCategoryLevel([...categoryTrail, {id: categoryId, name: categoryName}]);
                    return;
                }
                if (String(categoryId) === String(selectedCategoryId)) return;
                if (selectedCategoryId && hasListingDetailValues() && typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: window.trans('Change category?'),
                        text: window.trans('The listing details you entered for the current category will be cleared.'),
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: window.trans('Change'),
                        cancelButtonText: window.trans('Cancel'),
                    }).then(function (result) {
                        if (result.isConfirmed) selectCategory(categoryId, categoryName);
                    });
                    return;
                }
                selectCategory(categoryId, categoryName);
            });

            $('#go-back-category-btn').on('click', function () {
                openCategoryLevel(categoryTrail.slice(0, -1));
            });
            $(document).on('click', '#category-breadcrumb .category-crumb', function (e) {
                e.preventDefault();
                openCategoryLevel(categoryTrail.slice(0, parseInt($(this).data('depth'), 10) + 1));
            });
            $('#category-change-btn').on('click', function () {
                openCategoryLevel([]);
            });
            $(document).on('click', '#categories-retry-btn', function () {
                fetchCategoryPage(1);
            });

            // Entering the step: reopen at the selected leaf's level (or load the root once).
            $('{{ $wizardTabs }} a[href="#categories"]').on('shown.bs.tab', function () {
                const selectedParent = selectedCategoryTrail.length ? selectedCategoryTrail[selectedCategoryTrail.length - 1].id : null;
                if (selectedCategoryId && (String(selectedParent) !== String(currentParentId) || !categoryLevels[levelKey(currentParentId)])) {
                    openCategoryLevel(selectedCategoryTrail);
                } else if (!categoryLevels[levelKey(currentParentId)]) {
                    openCategoryLevel(categoryTrail);
                } else {
                    renderCategoryLevel();
                }
            });
            if ($('#categories').hasClass('active')) {
                openCategoryLevel([]);
            }

            // Selected category path (AI description context).
            function updateSelectedCategoryDisplay(categoryPath) {
                $('#selected-category-breadcrumb').text(categoryPath);
            }

            function resetCategorySelection() {
                selectedCategoryId = null;
                selectedCategoryName = '';
                selectedCategoryPath = [];
                selectedCategoryTrail = [];
                categoryTrail = [];
                categoryLevels = {};
                currentParentId = null;
                categoryRequestToken++;
                $('#category_id').val('');
                updateSelectedCategoryDisplay('');
                renderSelectedCategoryLine();
                $('#categories-list').empty();
                $('#breadcrumb-container, #load-more-container').hide();
                loadCustomFields(null);
            }

            // ── Listing Details step: the category's custom fields ──────────
            // Required fields → "Core Information", optional → "Additional Attributes".
            // Other languages only translate textbox fields (custom_field_translations).
            // `pills`: language pills shown above the card's first field.
            function customFieldSection(icon, title, subtitle, pills, body) {
                return `<div class="ec-section">
                    <div class="ec-section-head">
                        <span class="ec-section-icon" aria-hidden="true"><i class="ph-fill ${icon}"></i></span>
                        <div class="ec-section-heading">
                            <h5 class="ec-section-title">${escapeHtml(title)}</h5>
                            <p class="ec-section-subtitle">${escapeHtml(subtitle)}</p>
                        </div>
                    </div>
                    <div class="ec-section-body">${pills ? pills.replace('class="ec-lang-pills"', 'class="ec-lang-pills mb-3"') : ''}<div class="row">${body}</div></div>
                </div>`;
            }

            // Saved value(s) of a field (get-custom-fields?item_id=): string, array or JSON string.
            function customFieldValues(field) {
                let value = field.value;
                if (value === null || value === undefined || value === '') return [];
                if (typeof value === 'string' && /^\s*\[/.test(value)) {
                    try { value = JSON.parse(value); } catch (e) { /* plain string */ }
                }
                return (Array.isArray(value) ? value : [value]).map(String);
            }
            function customFieldText(field) {
                const values = customFieldValues(field);
                return values.length ? values.join(', ') : '';
            }

            function customFieldOptions(field) {
                return Array.isArray(field.values) ? field.values : JSON.parse(field.values ?? '[]');
            }

            const langDir = (lang) => lang && lang.rtl ? 'rtl' : 'ltr';
            const defaultLangDir = langDir(languages.find(l => l.id == defaultLanguageId));

            function customFieldHtml(field) {
                const id = field.id;
                const isRequired = field.required ? 'required' : '';
                const wide = field.type === 'radio' || field.type === 'checkbox';
                let html = `<div class="${wide ? 'col-12' : 'col-md-6'} mb-3">`;
                html += `<label class="form-label">${escapeHtml(field.name)}${field.required ? ' <span class="text-danger">*</span>' : ''}</label>`;

                if (field.type === 'textbox') {
                    const maxLen = field.max_length ? parseInt(field.max_length) : null;
                    const minLen = field.min_length ? parseInt(field.min_length) : null;
                    const maxAttr = maxLen ? `maxlength="${maxLen}" data-max-length="${maxLen}"` : '';
                    const minAttr = minLen ? `minlength="${minLen}" data-min-length="${minLen}"` : '';
                    const placeholder = escapeHtml(window.trans('Enter') + ' ' + field.name);
                    if (maxLen && maxLen > 200) {
                        html += `<textarea name="custom_fields[${id}]" dir="${defaultLangDir}" class="form-control custom-field-input" rows="3" placeholder="${placeholder}" ${isRequired} ${maxAttr} ${minAttr} data-parsley-trigger="input">${escapeHtml(customFieldText(field))}</textarea>`;
                    } else {
                        html += `<input type="text" name="custom_fields[${id}]" dir="${defaultLangDir}" class="form-control custom-field-input" value="${escapeHtml(customFieldText(field))}" placeholder="${placeholder}" ${isRequired} ${maxAttr} ${minAttr} data-parsley-trigger="input">`;
                    }
                    if (maxLen || minLen) {
                        html += `<small class="form-text text-muted cf-char-counter d-block text-end"></small>`;
                    }
                } else if (field.type === 'number') {
                    const maxDigits = field.max_length ? parseInt(field.max_length) : null;
                    const minDigits = field.min_length ? parseInt(field.min_length) : null;
                    const maxAttr = maxDigits !== null ? `maxlength="${maxDigits}" data-max-length="${maxDigits}"` : '';
                    const minAttr = minDigits !== null ? `minlength="${minDigits}" data-min-length="${minDigits}"` : '';
                    const lengthMsgAttr = (maxDigits !== null && minDigits !== null)
                        ? `data-parsley-length-message="This value should be between ${minDigits} and ${maxDigits} digits long."`
                        : '';
                    html += `<input type="text" inputmode="numeric" pattern="[0-9]*" name="custom_fields[${id}]" class="form-control custom-field-input" value="${escapeHtml(customFieldText(field))}" placeholder="${escapeHtml(window.trans('Enter') + ' ' + field.name)}" ${isRequired} ${maxAttr} ${minAttr} ${lengthMsgAttr} data-parsley-trigger="input">`;
                } else if (field.type === 'fileinput') {
                    // An already uploaded file satisfies "required"; uploading replaces it.
                    const fileUrl = customFieldValues(field)[0] || '';
                    if (fileUrl) {
                        const ext = fileUrl.split('.').pop().split('?')[0].toLowerCase();
                        const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext);
                        html += `<div class="media-file-card mb-2">
                            ${isImage ? `<img src="${escapeHtml(fileUrl)}" alt="" width="48" height="48" style="object-fit:cover;border-radius:6px;">` : '<i class="ph-bold ph-file-text fs-3 text-secondary"></i>'}
                            <div class="flex-grow-1 ms-2 small text-muted cf-file-state">{{ __('Uploaded file') }}</div>
                            <a href="${escapeHtml(fileUrl)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">{{ __('View File') }}</a>
                            ${field.required ? '' : `<button type="button" class="btn btn-sm btn-outline-danger ms-2 cf-file-remove" data-field-id="${id}">{{ __('Remove') }}</button>`}
                        </div>`;
                    }
                    html += `<input type="file" name="custom_field_files[${id}]" class="form-control custom-field-input" ${fileUrl ? '' : isRequired} data-parsley-trigger="change">`;
                } else if (field.type === 'dropdown') {
                    html += `<select name="custom_fields[${id}]" class="form-select custom-field-input" ${isRequired} data-parsley-trigger="change">`;
                    html += `<option value="">${escapeHtml(window.trans('Select') + ' ' + field.name)}</option>`;
                    const selectedValues = customFieldValues(field);
                    customFieldOptions(field).forEach(option => {
                        html += `<option value="${escapeHtml(option)}"${selectedValues.includes(String(option)) ? ' selected' : ''}>${escapeHtml(option)}</option>`;
                    });
                    html += `</select>`;
                } else if (field.type === 'radio') {
                    html += `<div class="ec-pill-group">`;
                    const selectedValues = customFieldValues(field);
                    customFieldOptions(field).forEach((option, i) => {
                        html += `<input type="radio" class="btn-check custom-field-input" id="cf-${id}-${i}" name="custom_fields[${id}]" value="${escapeHtml(option)}"${selectedValues.includes(String(option)) ? ' checked' : ''} ${isRequired} data-parsley-trigger="change" data-parsley-errors-container="#cf-errors-${id}">`;
                        html += `<label class="ec-pill" for="cf-${id}-${i}">${escapeHtml(option)}</label>`;
                    });
                    html += `</div><div id="cf-errors-${id}"></div>`;
                } else if (field.type === 'checkbox') {
                    html += `<div class="ec-check-grid">`;
                    const checkedValues = customFieldValues(field);
                    customFieldOptions(field).forEach(option => {
                        html += `<label class="ec-check-tile">
                            <input class="form-check-input custom-field-checkbox" type="checkbox" name="custom_fields[${id}][]" value="${escapeHtml(option)}"${checkedValues.includes(String(option)) ? ' checked' : ''} ${isRequired} data-parsley-trigger="change" data-parsley-errors-container="#cf-errors-${id}">
                            <span>${escapeHtml(option)}</span>
                        </label>`;
                    });
                    html += `</div><div id="cf-errors-${id}"></div>`;
                }
                return html + `</div>`;
            }

            function customFieldTranslationHtml(field, lang) {
                const nameTranslation = Array.isArray(field.translations)
                    ? field.translations.find(t => t.language_id == lang.id && t.key === 'name' && t.value)
                    : null;
                const label = nameTranslation ? nameTranslation.value : field.name;
                const saved = field.translated_values ? field.translated_values[lang.id] : '';
                const savedText = Array.isArray(saved) ? (saved[0] ?? '') : (saved ?? '');
                // Translations are optional — only the default language is required.
                return `<div class="col-md-6 mb-3">
                    <label class="form-label">${escapeHtml(label)} (${escapeHtml(lang.name)})</label>
                    <input type="text" name="custom_field_translations[${lang.id}][${field.id}]" dir="${langDir(lang)}" class="form-control custom-field-input-translation" value="${escapeHtml(savedText)}">
                </div>`;
            }

            function loadCustomFields(categoryId) {
                if (!categoryId) {
                    $('#custom').html('<div class="text-muted">{{ __('Select a category to load custom fields.') }}</div>');
                    $('#custom-tab-item').hide();
                    hasCustomFields = false;
                    return;
                }

                $.ajax({
                    url: `/get-custom-fields/${categoryId}`,
                    type: 'GET',
                    // Saved values of fields the new category shares with the old one come back too.
                    data: WIZARD_ITEM_ID ? {item_id: WIZARD_ITEM_ID} : {},
                    success: function(response) {
                        if (String(categoryId) !== String($('#category_id').val())) return; // category changed meanwhile
                        const fields = response.fields || [];
                        const translatable = fields.filter(f => f.type === 'textbox');
                        const showPills = translatable.length > 0;
                        const pills = showPills
                            ? `<div class="ec-lang-pills" data-select="custom-fields-language-selector" role="tablist">${languages.map(lang =>
                                `<button type="button" class="ec-lang-pill${lang.id == defaultLanguageId ? ' active' : ''}" data-lang-id="${lang.id}" role="tab">${escapeHtml(lang.name)}</button>`).join('')}</div>`
                            : '';
                        const coreTitle = '{{ __('Core Information') }}', coreText = '{{ __('The essential attributes for this category.') }}';
                        const extraTitle = '{{ __('Additional Attributes') }}', extraText = '{{ __('Category-specific custom fields.') }}';

                        let html = `<div class="ec-step-head">
                            <h2>{{ __('Listing Details') }}</h2>
                            <p>{{ __('Add category-specific information to help buyers.') }}</p>
                        </div>`;
                        html += `<select class="d-none" id="custom-fields-language-selector" aria-hidden="true" tabindex="-1">${languages.map(lang =>
                            `<option value="${lang.id}" data-code="${escapeHtml(lang.code)}"${lang.id == defaultLanguageId ? ' selected' : ''}>${escapeHtml(lang.name)}</option>`).join('')}</select>`;

                        hasCustomFields = fields.length > 0;
                        $('#custom-tab-item').toggle(hasCustomFields);

                        if (hasCustomFields) {
                            const core = fields.filter(f => f.required);
                            const extra = fields.filter(f => !f.required);
                            // Default language: every field.
                            html += `<div class="custom-fields-language-section" data-language-id="${defaultLanguageId}">`;
                            // Language pills sit on the first card of each language section.
                            if (core.length) html += customFieldSection('ph-list-bullets', coreTitle, coreText, pills, core.map(customFieldHtml).join(''));
                            if (extra.length) html += customFieldSection('ph-squares-four', extraTitle, extraText, core.length ? '' : pills, extra.map(customFieldHtml).join(''));
                            html += `</div>`;

                            // Other languages: textbox translations only.
                            languages.forEach(function (lang) {
                                if (lang.id == defaultLanguageId) return;
                                const coreT = translatable.filter(f => f.required), extraT = translatable.filter(f => !f.required);
                                html += `<div class="custom-fields-language-section" data-language-id="${lang.id}" style="display: none;">`;
                                if (coreT.length) html += customFieldSection('ph-list-bullets', coreTitle, coreText, pills, coreT.map(f => customFieldTranslationHtml(f, lang)).join(''));
                                if (extraT.length) html += customFieldSection('ph-squares-four', extraTitle, extraText, coreT.length ? '' : pills, extraT.map(f => customFieldTranslationHtml(f, lang)).join(''));
                                html += `</div>`;
                            });
                        } else {
                            html += `<p class="text-muted">{{ __('No custom fields for this category.') }}</p>`;
                        }

                        html += `<div class="mt-4 d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-light border btn-prev-tab" data-prev-tab="listing"><i class="ph-bold ph-arrow-left me-1"></i> {{ __('Back') }}</button>
                            <button type="button" class="btn btn-primary btn-next-tab" data-next-tab="seo">{{ __('Continue') }} <i class="ph-bold ph-arrow-right ms-1"></i></button>
                        </div>`;

                        $('#custom').html(html);
                        updateCustomFieldsStatus();

                        // Bind Parsley explicitly to dynamic custom fields
                        $('#custom .custom-field-input, #custom .custom-field-checkbox').each(function() {
                            $(this).parsley();
                        });

                        // Init char counters for textbox custom fields
                        $('#custom .custom-field-input[data-max-length], #custom .custom-field-input[data-min-length]').trigger('input.cfcounter');

                        // Language switching for custom fields
                        $('#custom-fields-language-selector').off('change.cflang').on('change.cflang', function() {
                            const selectedLangId = $(this).val();

                            // Hide all language sections and remove required from hidden fields
                            $('.custom-fields-language-section').each(function() {
                                $(this).hide();
                                $(this).find('input[required], select[required], textarea[required]').each(function() {
                                    $(this).attr('data-was-required', 'true');
                                    $(this).removeAttr('required');
                                });
                            });

                            // Show selected language section and restore required attributes
                            const $selectedSection = $(`.custom-fields-language-section[data-language-id="${selectedLangId}"]`);
                            $selectedSection.show();
                            $selectedSection.find('input[data-was-required="true"], select[data-was-required="true"], textarea[data-was-required="true"]').each(function() {
                                $(this).attr('required', 'required');
                            });
                        });
                        $('#custom-fields-language-selector').trigger('change.cflang');

                        // Pricing modes, image/video limits and product video types of the category's module.
                        applyItemModuleSettings(response.module_settings, response.is_job_category);
                    },
                    error: function() {
                        $('#custom').html('<div class="text-danger">{{ __('Error loading custom fields.') }}</div>');
                        $('#custom-tab-item').hide();
                        hasCustomFields = false;
                    }
                });
            }

            // ── Review step: read-only summary of what will be saved ──────
            function renderListingReview() {
                const notProvided = window.trans('Not Provided');
                const $review = $('#review');
                const set = (key, value, missing = false) => {
                    const text = value === null || value === undefined || String(value).trim() === '' ? notProvided : String(value);
                    $review.find(`[data-review="${key}"]`).text(text).closest('.ec-summary-row').toggleClass('is-missing', missing);
                };
                const filled = (el) => {
                    if (el.type === 'checkbox' || el.type === 'radio') return el.checked;
                    if (el.type === 'file') return el.files && el.files.length > 0;
                    return $.trim($(el).val() || '') !== '';
                };
                const checks = [];
                const check = (ok) => { checks.push(!!ok); return !ok; };

                const moduleLabel = $('#module .module-option.selected').data('module-name');
                set('module', moduleLabel, check(moduleLabel));
                const formatLabel = $('#format .format-option.selected .ec-option-title').text();
                set('format', formatLabel, check(formatLabel));
                set('category', selectedCategoryPath.join(PATH_SEPARATOR), check(selectedCategoryId));

                const title = $.trim($('#name-input').val());
                set('title', title, check(title));
                const description = $.trim($('#description-input').val());
                set('description', description.length > 80 ? description.slice(0, 80) + '…' : description, check(description));
                const contact = $.trim($('#contact-input').val());
                const phoneShown = $('input[name="show_mobile_number"]:checked').val() !== '0';
                set('contact', contact
                    ? `${$('#country-code-input').val() || ''} ${contact}`.trim() + (phoneShown ? '' : ' (' + window.trans('hidden') + ')')
                    : '');

                set('pricing', $('#pricing-mode-select option:selected').text());
                const symbol = $('#currency option:selected').data('symbol') || '';
                const money = (v) => symbol + Number(v).toLocaleString();
                if ($('#price-field').css('display') !== 'none') {
                    const price = $('#price-input').val();
                    set('amount', price !== '' ? money(price) : '', check(!$('#price-input').attr('required') || price !== ''));
                } else if ($('#salary-fields').css('display') !== 'none') {
                    const min = $('#min-salary-input').val(), max = $('#max-salary-input').val();
                    set('amount', [min && money(min), max && money(max)].filter(Boolean).join(' – '), check(min || max));
                } else {
                    set('amount', '');
                }

                $review.find('[data-review-row="custom"]').toggle(hasCustomFields);
                if (hasCustomFields) {
                    const $defaultSection = $(`#custom .custom-fields-language-section[data-language-id="${defaultLanguageId}"]`);
                    const groups = {};
                    $defaultSection.find('.custom-field-input, .custom-field-checkbox').each(function () {
                        const name = this.name.replace(/\[\]$/, '');
                        groups[name] = groups[name] || {required: false, filled: false};
                        groups[name].required = groups[name].required || this.hasAttribute('required') || this.getAttribute('data-was-required') === 'true';
                        groups[name].filled = groups[name].filled || filled(this);
                    });
                    const list = Object.values(groups);
                    const core = list.filter(g => g.required), extra = list.filter(g => !g.required);
                    const coreSet = core.filter(g => g.filled).length;
                    set('core', core.length ? `${coreSet} / ${core.length} ${window.trans('Attributes Set')}` : window.trans('None'), check(coreSet === core.length));
                    const extraSet = extra.filter(g => g.filled).length;
                    set('additional', extra.length ? (extraSet ? `${extraSet} ${window.trans('Configured')}` : '') : window.trans('None'));
                }

                const keptImages = $('.delete-existing-img-cb').not(':checked').length;
                const newImages = ($('#gallery-images-input')[0]?.files || []).length;
                set('images', keptImages + newImages
                    ? `${keptImages + newImages} ${window.trans('images')}` + (newImages ? ` (${newImages} ${window.trans('new')})` : '')
                    : '', check(keptImages + newImages));
                const isReel = $('#item_type_input').val() === 'reel';
                $review.find('[data-review-row="reel"]').toggle(isReel);
                if (isReel) {
                    const newReel = !!(window.reelTrimmedBlob || ($('#reel-video-input')[0]?.files || []).length);
                    const keptReel = $('#reel-existing-wrap').length > 0 && $('#reel-delete-flag').val() !== '1';
                    set('reel', newReel ? ($('#reel-video-filename').text() || window.trans('Uploaded')) : (keptReel ? window.trans('Current Video Ad') : ''), check(newReel || keptReel));
                }
                const videoType = $('#video_type_hidden').val();
                set('video', videoType ? $('#videoTypeLabel').text() : window.trans('None'));
                const location = $('#address-hidden').val() || $('#selected-address-text').text();
                set('location', location, check($('#latitude-input').val() && $('#longitude-input').val()));
                const metaTitle = $.trim($(`[name="meta_title[${defaultLanguageId}]"]`).val());
                const metaDescription = $.trim($(`[name="meta_description[${defaultLanguageId}]"]`).val());
                set('seo', metaTitle && metaDescription ? window.trans('Ready') : (metaTitle || metaDescription ? window.trans('Partial') : ''));

                const done = checks.filter(Boolean).length;
                const percent = checks.length ? Math.round(done / checks.length * 100) : 100;
                const ready = percent === 100;
                $('#review-status').toggleClass('is-warning', !ready)
                    .find('.ec-status-banner-icon i').attr('class', ready ? 'ph-bold ph-check' : 'ph-bold ph-warning');
                $('#review-status-title').text(ready ? window.trans('Listing Ready') : window.trans('Almost there'));
                $('#review-status-text').text(ready
                    ? window.trans(WIZARD_IS_EDIT ? 'Your listing is complete and ready to be updated.' : 'Your listing is 100% complete and ready to be published.')
                    : window.trans('Your listing is') + ' ' + percent + '% ' + window.trans(WIZARD_IS_EDIT ? 'complete. Fill in the highlighted items before updating.' : 'complete. Fill in the highlighted items before publishing.'));
            }
            $('{{ $wizardTabs }} a[href="#review"]').on('shown.bs.tab', renderListingReview);

            // Remove / undo an optional file field's stored file (sent as delete_custom_field_files[];
            // the server deletes the file after saving). Picking a new file replaces it instead.
            $(document).on('click', '#custom .cf-file-remove', function () {
                const $btn = $(this);
                const id = $btn.data('field-id');
                const $card = $btn.closest('.media-file-card');
                const removed = !$card.hasClass('is-removed');
                $card.toggleClass('is-removed', removed);
                $card.find('.cf-file-state').text(removed ? '{{ __('Will be removed on update') }}' : '{{ __('Uploaded file') }}');
                $btn.text(removed ? '{{ __('Undo') }}' : '{{ __('Remove') }}');
                $card.find('input[name="delete_custom_field_files[]"]').remove();
                if (removed) $card.append(`<input type="hidden" name="delete_custom_field_files[]" value="${id}">`);
            });
            $(document).on('change', '#custom input[type="file"][name^="custom_field_files["]', function () {
                const $card = $(this).prevAll('.media-file-card').first();
                if (this.files.length && $card.hasClass('is-removed')) $card.find('.cf-file-remove').trigger('click');
            });

