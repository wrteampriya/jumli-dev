<script>
    // Shared by the create / edit pages. Handlers are delegated and scoped to
    // #feature-section-form so they also work when the page is loaded into the side drawer.
    // Approved item counts per category, shown in the filter-by-category list.
    var featureSectionCategoryItemCounts = @json($categoryItemCounts);

    function featureSectionCategoryLabel(categoryId, text) {
        const count = featureSectionCategoryItemCounts[categoryId];
        return count !== undefined ? text + ' (' + count + ')' : text;
    }

    $(function () {
        const $form = $('#feature-section-form');
        const $drawer = $form.closest('#sideDrawer');

        // Filter-by-category options: the category tree with approved item counts.
        const $filterCategory = $form.find('#item_filter_category');
        if ($filterCategory.length) {
            $form.find('#category_id option').clone().each(function () {
                $(this).text(featureSectionCategoryLabel($(this).val(), $(this).text()));
            }).appendTo($filterCategory);
        }

        $form.find('select.select-search').each(function () {
            const $select = $(this);
            if ($select.hasClass('select2-hidden-accessible')) $select.select2('destroy');
            const selected = $select.data('selected');
            if (Array.isArray(selected)) $select.val(selected.map(String));
            $select.select2({
                theme: 'bootstrap-5',
                width: '100%',
                allowClear: !$select.is('[data-locked]'),
                placeholder: $select.data('placeholder') || window.trans('All'),
                dropdownParent: $drawer.length ? $drawer : $(document.body),
            });
            // Locked on edit: keep the value submitting, only block changes.
            if ($select.is('[data-locked]')) {
                $select.next('.select2-container').css({'pointer-events': 'none', 'opacity': '0.65'});
            }
        });
    });

    // Category lists follow the chosen module, requested on demand from lookup.categories; the
    // filter-by-category list keeps its item counts. Locked edit selections are left as saved.
    $(document).on('change', '#feature-section-form #module_id', function () {
        loadCategoryOptionsForModule($('#feature-section-form').find('#category_id, #item_filter_category').not('[data-locked]'), $(this).val() || '', {
            label: (category, text, select) => select.id === 'item_filter_category' ? featureSectionCategoryLabel(category.id, text) : text,
        });
    });

    // Show only the chosen filter's inputs. Hidden ones are disabled so their `required`
    // doesn't block the browser's own validation (drawer forms aren't novalidate) and
    // they aren't submitted.
    // Category field: required list for Category Criteria, optional scope for the other
    // rules, not used by Item Selection (its own filter-by-category only loads items).
    function syncFeatureSectionCriteria() {
        const $form = $('#feature-section-form');
        const value = $form.find('#filter').val();
        $form.find('.fs-criteria').each(function () {
            const active = String($(this).data('filter')).split(' ').includes(value);
            $(this).toggle(active).find('input, select').prop('disabled', !active);
        });

        const required = value === 'category_criteria';
        const $field = $form.find('.fs-category-field');
        $field.toggle(value !== 'item_selection');
        $field.find('#category_id').prop('disabled', value === 'item_selection').attr('data-parsley-required', required ? 'true' : 'false');
        $field.find('.fs-category-required').toggle(required);
        ['.fs-category-label', '.fs-category-help'].forEach(function (selector) {
            const $el = $field.find(selector);
            $el.text($el.data(required ? 'required' : 'scope'));
        });
    }

    $(function () {
        syncFeatureSectionCriteria();
    });

    $(document).on('change', '#feature-section-form #filter', syncFeatureSectionCriteria);

    // Language pills: one hidden select drives the per-language title panes.
    $(document).on('change', '#feature-section-form #fs-language-selector', function () {
        const langId = String(this.value);
        $('#feature-section-form .fs-lang-pane').each(function () {
            $(this).toggle(String($(this).data('lang-id')) === langId);
        });
    });

    // Section status: hidden value + helper text.
    $(document).on('change', '#feature-section-form #fsStatusSwitch', function () {
        const $form = $('#feature-section-form');
        $form.find('#fs-status').val(this.checked ? 1 : 0);
        const $text = $form.find('#fs-status-text');
        $text.text(this.checked ? $text.data('on') : $text.data('off'));
    });

    // Cancel: close the drawer (full page: back to the list).
    $(document).on('click', '#feature-section-form .fs-cancel-btn', function (e) {
        if ($(this).closest('#sideDrawer').length && window.SideDrawer) {
            e.preventDefault();
            window.SideDrawer.close();
        }
    });

    // Show the language of the first invalid field.
    // Binding Parsley here also adds its own submit check, which runs before the drawer /
    // common.js handlers: exclude hidden fields like they do, or the hidden criteria panels'
    // required inputs (e.g. min/max price) silently block every save.
    $(function () {
        const $form = $('#feature-section-form');
        if (!$form.length || !$form.parsley) return;
        $form.parsley({
            excluded: 'input[type=button], input[type=submit], input[type=reset], :hidden'
        }).on('form:error', function () {
            this.fields.some(function (field) {
                if (field.isValid()) return false;
                const $pane = $(field.element).closest('.fs-lang-pane');
                if ($pane.length && !$pane.is(':visible')) {
                    $form.find('#fs-language-selector').val(String($pane.data('lang-id'))).trigger('change');
                }
                return true;
            });
        });
    });

    $(document).on('input', '#feature-section-form .feature-section-name', function () {
        $('#feature-section-form .feature-section-slug').val(generateSlug($(this).val()));
    });

    // Create: picking categories loads their items, keeping items already chosen.
    $(document).on('change', '#feature-section-form #item_filter_category', function () {
        const categoryIds = $(this).val() || [];
        const $items = $('#feature-section-form #item_id');
        const keep = $items.val() || [];
        $items.empty().trigger('change');
        if (!categoryIds.length) return;

        $('#item_loading_spinner').show();
        // Shared admin item lookup (AdminLookupController::items); jQuery sends the array as category_ids[].
        $.get("{{ route('lookup.items') }}", {category_ids: categoryIds}, function (res) {
            $.each(res.data || [], function (i, item) {
                const isSelected = keep.includes(String(item.id));
                $items.append(new Option(item.name, item.id, isSelected, isSelected));
            });
            $items.trigger('change');
        }).always(function () {
            $('#item_loading_spinner').hide();
        });
    });

    $(document).on('change', '#feature-section-form #item_id', function () {
        const count = ($(this).val() || []).length;
        $('#item_count_badge').text(count).toggle(count > 0);
    });
</script>
