{{--
    Behaviour for packages/partials/form.blade.php (Ad / Featured package create + edit).
    Delegated on document and scoped to .pkg-package-form so it also works inside the side drawer.
--}}
<script>
    (function () {
        const FORM = '.pkg-package-form';

        // Runs on every load (each drawer open brings a fresh form).
        function initForms() {
            $(FORM).each(function () {
                const $form = $(this);
                $form.find('input[type=radio][data-toggles]:checked').each(function () {
                    syncToggle(this);
                });
            });
        }

        // The delegated $(document) handlers below are bound on every load: the side drawer unbinds the
        // ones a page added each time it reloads/closes (side-drawer.js removePageListeners), so a
        // bind-once guard left them dead from the second open on (language tabs stuck on English).

        // Language pills: every pill group in the form switches together.
        function showLanguage($form, langId) {
            $form.find('.pkg-lang-tab').removeClass('active').filter('[data-lang="' + langId + '"]').addClass('active');
            $form.find('.pkg-lang-pane').removeClass('active').filter('[data-lang="' + langId + '"]').addClass('active');
        }

        $(document).on('click', FORM + ' .pkg-lang-tab', function () {
            showLanguage($(this).closest(FORM), $(this).data('lang'));
        });

        // A required name left empty on another tab: open that tab and flag its pill.
        // Parsley's own event bus isn't tracked by the drawer, so these stay bound once.
        if (window.Parsley && !window.pkgPackageParsleyBound) {
            window.pkgPackageParsleyBound = true;
            window.Parsley.on('field:error', function () {
                const $pane = this.$element.closest(FORM + ' .pkg-lang-pane');
                if (!$pane.length) return;
                const $form = $pane.closest(FORM);
                showLanguage($form, $pane.data('lang'));
                $form.find('.pkg-lang-tab[data-lang="' + $pane.data('lang') + '"]').addClass('has-error');
            });
            window.Parsley.on('field:success', function () {
                const $pane = this.$element.closest(FORM + ' .pkg-lang-pane');
                if ($pane.length) $pane.closest(FORM).find('.pkg-lang-tab[data-lang="' + $pane.data('lang') + '"]').removeClass('has-error');
            });
        }

        // Radios that show an input for one value (duration days, item limit, custom listing days).
        function syncToggle(radio) {
            const $radio = $(radio);
            const $group = $radio.closest(FORM).find('input[type=radio][data-toggles="' + $radio.data('toggles') + '"]:checked');
            $($radio.data('toggles'), $radio.closest(FORM)).toggle($group.val() === String($radio.data('toggle-on')));
        }

        $(document).on('change', FORM + ' input[type=radio][data-toggles]', function () {
            syncToggle(this);
        });

        // Final price follows price and discount (display only — the server recalculates it).
        $(document).on('input', FORM + ' .pkg-price, ' + FORM + ' .pkg-discount', function () {
            const $form = $(this).closest(FORM);
            const price = parseFloat($form.find('.pkg-price').val()) || 0;
            const discount = Math.min(Math.max(parseFloat($form.find('.pkg-discount').val()) || 0, 0), 100);
            $form.find('#final_price').val((price - price * discount / 100).toFixed(2));
        });

        // Key points: the first row is fixed (CSS hides its remove button); added rows can be removed.
        $(document).on('click', FORM + ' .pkg-key-point-add', function () {
            const $list = $(this).siblings('.pkg-key-points');
            const $row = $list.children('.pkg-key-point').first().clone();
            $row.find('input').val('').attr('placeholder', $(this).data('placeholder'));
            $list.append($row);
            $row.find('input').trigger('focus');
        });

        $(document).on('click', FORM + ' .pkg-key-point-remove', function () {
            const $row = $(this).closest('.pkg-key-point');
            if (!$row.is(':first-child')) $row.remove();
        });

        // Icon drop zone: preview the picked file.
        $(document).on('change', FORM + ' .pkg-dropzone-input', function () {
            const file = this.files && this.files[0];
            const $zone = $(this).closest('.pkg-dropzone');
            if (!file) return;
            const reader = new FileReader();
            reader.onload = function (e) {
                $zone.find('.pkg-dropzone-preview img').attr('src', e.target.result);
                $zone.find('.pkg-dropzone-name').text(file.name);
                $zone.addClass('has-file');
            };
            reader.readAsDataURL(file);
        });
        $(document).on('dragenter dragover', FORM + ' .pkg-dropzone-input', function () {
            $(this).closest('.pkg-dropzone').addClass('dragover');
        });
        $(document).on('dragleave drop', FORM + ' .pkg-dropzone-input', function () {
            $(this).closest('.pkg-dropzone').removeClass('dragover');
        });

        // Module change (create): reload the category scope with that module's categories from the
        // shared lookup (AdminLookupController::categories) — only the first module is rendered server-side.
        $(document).on('change', FORM + ' #module_id', function () {
            const root = $(this).closest(FORM).find('.cs-scope-root')[0];
            if (!root || !window.CategoryScope) return;
            $.get("{{ route('lookup.categories') }}", {module_id: $(this).val()}, function (response) {
                window.CategoryScope.render(root, response.data || []);
            });
        });

        $(initForms);
    })();

    // Full-page (non-drawer) submit: back to the list. The drawer closes and refreshes the list itself.
    function afterPackageSave() {
        setTimeout(function () {
            window.location.href = "{{ route($routePrefix.'.index') }}";
        }, 1000);
    }
</script>
