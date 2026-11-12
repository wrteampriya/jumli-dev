{{--
    Applies the category's module settings (ModuleService::settings(), sent as
    `module_settings` by /get-custom-fields) to the item create/edit form:
    pricing modes, gallery image limit, product video max size and types.
    The server re-checks all of it (ItemModuleValidator).

    NOTE: pricing_mode = range REUSES the min_salary / max_salary inputs as
    the price range (from / to) for every category, not only jobs. At least
    one bound is required, not both.
--}}
<script>
    window.itemMaxGalleryImages = 5;
    window.itemVideoMaxSizeMb = 50;
    window.itemIsJobCategory = false;

    const ITEM_PRICING_MODE_LABELS = @json(\App\Services\Module\ModuleService::pricingModes());

    // product_video is a boolean: allowed means one video, link or upload.
    const ITEM_VIDEO_TYPES = ['', 'youtube_link', 'vimeo_link', 'other_link', 'file'];

    const ITEM_REQUIRED_MARK = ' <span class="text-danger">*</span>';

    // fixed / negotiable -> price; range -> min_salary + max_salary;
    // free / price_on_request -> no price fields (free is saved as price 0). Hidden inputs are cleared so a
    // stale value is never submitted.
    function applyPricingMode(mode) {
        const usesPrice = mode === 'fixed' || mode === 'negotiable';
        const isRange = mode === 'range';

        $('#price-field').toggle(usesPrice);
        $('#price-input').prop('required', usesPrice);
        if (!usesPrice) $('#price-input').val('');

        $('#salary-fields').toggle(isRange);
        // Range needs at least one bound, not both — checked on submit.
        $('#min-salary-input, #max-salary-input').prop('required', false);
        if (!isRange) $('#min-salary-input, #max-salary-input').val('');
    }

    function applyItemModuleSettings(settings, isJobCategory) {
        if (!settings) return;

        // Pricing: only the module's modes; keep the current choice when the
        // new module supports it, else the module's default.
        const $select = $('#pricing-mode-select');
        const current = $select.val() || $select.data('current');
        const modes = settings.pricing_modes || [];
        $select.empty();
        modes.forEach(function (mode) {
            $select.append(new Option(ITEM_PRICING_MODE_LABELS[mode] || mode, mode));
        });
        if (current && !modes.includes(current)) {
            // e.g. a stored "range" the module no longer lists: say so rather
            // than silently switching (and clearing the stored range).
            showWarningToast("{{ __('This listing type no longer offers the current pricing; please choose a new one.') }}");
        }
        $select.val(modes.includes(current) ? current : (modes.includes(settings.default_pricing_mode) ? settings.default_pricing_mode : modes[0]));
        applyPricingMode($select.val());

        $('#price-label').html("{{ __('Price') }}" + ITEM_REQUIRED_MARK);
        $('#min-salary-label').html(isJobCategory ? "{{ __('Min Salary') }}" : "{{ __('Min Price') }}");
        $('#max-salary-label').html(isJobCategory ? "{{ __('Max Salary') }}" : "{{ __('Max Price') }}");

        // Media rules — max_images is the total (cover + gallery).
        window.itemMaxGalleryImages = parseInt(settings.max_images, 10) || 5;
        window.itemVideoMaxSizeMb = parseInt(settings.video_max_file_size_mb, 10) || 50;
        window.itemIsJobCategory = !!isJobCategory;
        $('.item-video-max-size').text(settings.video_max_file_size_mb);

        const allowedVideoTypes = settings.product_video ? ITEM_VIDEO_TYPES : [''];
        $('#product-video-section').toggle(!!settings.product_video);
        $('[data-video-type]').each(function () {
            $(this).closest('li').toggle(allowedVideoTypes.includes(String($(this).data('video-type'))));
        });
        if (!allowedVideoTypes.includes($('#video_type_hidden').val() || '')) {
            // Not silent: an existing video of a type this module no longer
            // allows is removed on save.
            showWarningToast("{{ __('This listing type no longer allows the current product video; it will be removed when you save.') }}");
            setVideoType('', "{{ __('None') }}", true);
        }
    }

    $(document).on('change', '#pricing-mode-select', function () {
        applyPricingMode(this.value);
    });

    // Product video file over the module's limit? (server re-checks)
    function itemVideoTooLarge(file) {
        return !!file && file.size > window.itemVideoMaxSizeMb * 1024 * 1024;
    }

    // "salary" for job categories, "price" otherwise (range mode reuses min/max_salary).
    function itemRangeLabel(kind) {
        const job = window.itemIsJobCategory;
        return {
            min: job ? "{{ __('Please enter a minimum salary.') }}" : "{{ __('Please enter a minimum price.') }}",
            max: job ? "{{ __('Please enter a maximum salary.') }}" : "{{ __('Please enter a maximum price.') }}",
            either: job ? "{{ __('Please enter a minimum or maximum salary.') }}" : "{{ __('Please enter a minimum or maximum price.') }}",
            order: job ? "{{ __('Max salary must be greater than min salary.') }}" : "{{ __('Max price must be greater than min price.') }}",
        }[kind];
    }
</script>
