        // Module → Category (blog/module-category-fields): picking a module reloads the Category
        // list with that module's categories from the server. The category picked before is kept
        // only if it belongs to the new module.
        (function () {
            const $module = $form.find('#blog_module_id');
            const $category = $form.find('#blog_category_id');
            if (!$module.length) return;

            let latestRequest = 0;
            $module.on('change', function () {
                const requestId = ++latestRequest;
                const current = $category.val();
                $category.prop('disabled', true);
                $.get("{{ route('blog.categories') }}", {module_id: $module.val() || ''})
                    .done(function (response) {
                        if (requestId !== latestRequest) return; // a newer module choice is loading
                        $category.find('option[value!=""]').remove();
                        (response.data || []).forEach(function (category) {
                            $category.append(new Option(category.name, category.id));
                        });
                        const keep = current && $category.find('option[value="' + current + '"]').length;
                        $category.val(keep ? current : '').trigger('change.select2');
                    })
                    .fail(function () {
                        showErrorToast(window.trans('Something went wrong'));
                    })
                    .always(function () {
                        if (requestId === latestRequest) $category.prop('disabled', false);
                    });
            });
        })();
