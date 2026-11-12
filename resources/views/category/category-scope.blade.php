@php
    $title = $title ?? __('Category Scope');
    $subtitle = $subtitle ?? __('Select the categories where this should be applied');
    $checkbox_name = $checkbox_name ?? 'selected_categories[]';
    $show_global = $show_global ?? false;
    $global_checked = $global_checked ?? false;
    $global_field_name = $global_field_name ?? 'is_global';
    $global_label = $global_label ?? __('Global (Apply to All Categories)');
    $disabled = $disabled ?? false;
    $show_header = $show_header ?? true;
    $show_selected_chips = $show_selected_chips ?? false;
    $auto_expand = $auto_expand ?? false;
@endphp

<div class="cs-card cs-scope-root" id="{{ $wrapper_id ?? 'category_scope' }}"
     data-checkbox-name="{{ $checkbox_name }}" data-disabled="{{ $disabled ? 1 : 0 }}">
    @if ($show_header)
        <div class="cs-header">
            <p class="cs-title">{{ $title }}</p>
            <p class="cs-subtitle">{{ $subtitle }}</p>
        </div>
    @endif

    <div class="cs-controls">
        <div class="cs-search">
            <i class="ph ph-magnifying-glass"></i>
            <input type="text" class="cs-search-input" placeholder="{{ __('Search something...') }}">
        </div>

        @if ($show_selected_chips)
            <div class="cs-selected-block">
                <span class="cs-selected-label">{{ __('Selected Category') }}</span>
                <div class="cs-chips" data-chips>
                    <span class="cs-chips-empty" data-chips-empty>{{ __('No category selected') }}</span>
                </div>
            </div>
        @endif
    </div>

    <div class="cs-list">
        @if ($show_global)
            <div class="cs-row cs-global-row" style="padding-left: 31px;">
                <label class="cs-check">
                    <input type="checkbox" name="{{ $global_field_name }}" value="1" class="cs-global-checkbox"
                           {{ isset($global_id) ? 'id=' . $global_id : '' }}
                           {{ $global_checked ? 'checked' : '' }} {{ $disabled ? 'disabled' : '' }}>
                    <span class="cs-check-box"><i class="ph-bold ph-check cs-check-icon"></i></span>
                </label>
                <span class="cs-cat-icon"><i class="ph ph-globe"></i></span>
                <span class="cs-cat-name">{{ $global_label }}</span>
            </div>
        @endif

        <div id="category_selection" style="display: {{ $global_checked ? 'none' : 'block' }};">
        @include('category.treeview', [
            'categories' => $categories,
            'selected_categories' => $selected_categories,
            'selected_all_categories' => $selected_all_categories,
            'checkbox_name' => $checkbox_name,
            'disabled' => $disabled,
            'auto_expand' => $auto_expand,
        ])
        </div>
    </div>
</div>

@once
    <script>
        (function () {
            function initCategoryScope(root) {
                if (root.dataset.csReady) return;
                root.dataset.csReady = '1';
                var list = root.querySelector('.cs-list');
                var treeWrapper = root.querySelector('#category_selection');
                var chipsContainer = root.querySelector('[data-chips]');
                var chipsEmpty = root.querySelector('[data-chips-empty]');
                var searchInput = root.querySelector('.cs-search-input');

                function updateBadges() {
                    root.querySelectorAll('.cs-category').forEach(function (cat) {
                        var badge = cat.querySelector(':scope > .cs-row > .cs-badge');
                        if (!badge) return;
                        var count = cat.querySelectorAll(':scope > .cs-subcategories .category-checkbox:checked').length;
                        if (count > 0) {
                            badge.textContent = count + ' {{ __('Selected') }}';
                            badge.classList.add('has-selection');
                        } else {
                            badge.classList.remove('has-selection');
                        }
                    });
                }

                function updateChips() {
                    if (!chipsContainer) return;
                    var checked = root.querySelectorAll('.category-checkbox:checked');
                    chipsContainer.querySelectorAll('.cs-chip').forEach(function (chip) { chip.remove(); });
                    if (!checked.length) {
                        chipsEmpty.style.display = '';
                        return;
                    }
                    chipsEmpty.style.display = 'none';
                    checked.forEach(function (input) {
                        var name = input.closest('.cs-row').querySelector('.cs-cat-name').textContent;
                        var chip = document.createElement('span');
                        chip.className = 'cs-chip';
                        chip.innerHTML = '<span></span><button type="button" aria-label="{{ __('Remove') }}"><i class="ph ph-x"></i></button>';
                        chip.querySelector('span').textContent = name;
                        chip.querySelector('button').addEventListener('click', function () {
                            input.checked = false;
                            input.dispatchEvent(new Event('change', { bubbles: true }));
                        });
                        chipsContainer.appendChild(chip);
                    });
                }

                function refresh() {
                    updateBadges();
                    updateChips();
                }

                list.addEventListener('click', function (e) {
                    var toggle = e.target.closest('.cs-toggle');
                    if (toggle) {
                        var category = toggle.closest('.cs-category');
                        var sub = category.querySelector(':scope > .cs-subcategories');
                        if (!sub) return;
                        var isOpen = toggle.classList.toggle('open');
                        category.classList.toggle('open', isOpen);
                        sub.style.display = isOpen ? 'block' : 'none';
                        return;
                    }

                    var nameOrIcon = e.target.closest('.cs-cat-name, .cs-cat-icon');
                    if (!nameOrIcon) return;
                    var row = nameOrIcon.closest('.cs-row');
                    var input = row && row.querySelector(':scope > .cs-check > input');
                    if (!input || input.disabled) return;
                    input.checked = !input.checked;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                });

                list.addEventListener('change', function (e) {
                    if (!e.target.classList.contains('category-checkbox')) return;
                    var input = e.target;
                    var category = input.closest('.cs-category');
                    var isChecked = input.checked;

                    category.querySelectorAll('.subcategories .category-checkbox').forEach(function (child) {
                        child.checked = isChecked;
                    });

                    var parent = category.parentElement.closest('.cs-category');
                    while (parent) {
                        var siblingChecks = parent.querySelectorAll(':scope > .cs-subcategories > .cs-category > .cs-row > .cs-check > input');
                        var allChecked = Array.prototype.every.call(siblingChecks, function (c) { return c.checked; });
                        var parentInput = parent.querySelector(':scope > .cs-row > .cs-check > input');
                        parentInput.checked = allChecked;
                        parent = parent.parentElement.closest('.cs-category');
                    }

                    refresh();
                });

                var moreGroups = [];

                if (searchInput) {
                    function highlight(nameEl, term) {
                        var text = nameEl.getAttribute('data-original-text') || nameEl.textContent;
                        nameEl.setAttribute('data-original-text', text);
                        if (!term) {
                            nameEl.textContent = text;
                            return;
                        }
                        var idx = text.toLowerCase().indexOf(term);
                        if (idx === -1) {
                            nameEl.textContent = text;
                            return;
                        }
                        nameEl.innerHTML = '';
                        nameEl.appendChild(document.createTextNode(text.slice(0, idx)));
                        var mark = document.createElement('mark');
                        mark.className = 'cs-highlight';
                        mark.textContent = text.slice(idx, idx + term.length);
                        nameEl.appendChild(mark);
                        nameEl.appendChild(document.createTextNode(text.slice(idx + term.length)));
                    }

                    searchInput.addEventListener('input', function () {
                        var term = searchInput.value.trim().toLowerCase();

                        var cats = Array.prototype.slice.call(root.querySelectorAll('.cs-category'));

                        cats.forEach(function (cat) {
                            var nameEl = cat.querySelector(':scope > .cs-row > .cs-cat-name');
                            var name = (nameEl.getAttribute('data-original-text') || nameEl.textContent).toLowerCase();
                            var sub = cat.querySelector(':scope > .cs-subcategories');
                            cat._selfMatch = !!term && name.indexOf(term) !== -1;
                            cat._descMatch = !!term && !!sub && Array.prototype.some.call(sub.querySelectorAll('.cs-cat-name'), function (el) {
                                return (el.getAttribute('data-original-text') || el.textContent).toLowerCase().indexOf(term) !== -1;
                            });
                            highlight(nameEl, cat._selfMatch ? term : '');
                        });

                        cats.forEach(function (cat) {
                            var show = !term || cat._selfMatch || cat._descMatch;
                            if (term && !show) {
                                var p = cat.parentElement.closest('.cs-category');
                                while (p) {
                                    if (p._selfMatch) { show = true; break; }
                                    p = p.parentElement.closest('.cs-category');
                                }
                            }
                            cat.setAttribute('data-hidden', show ? 'false' : 'true');

                            var sub = cat.querySelector(':scope > .cs-subcategories');
                            var toggle = cat.querySelector(':scope > .cs-row > .cs-toggle');
                            if (!sub) return;
                            if (term && cat._descMatch) {
                                sub.style.display = 'block';
                                if (toggle) toggle.classList.add('open');
                            } else {
                                sub.style.display = 'none';
                                if (toggle) toggle.classList.remove('open');
                            }
                        });

                        moreGroups.forEach(function (group) {
                            if (term) {
                                group.hidden.forEach(function (c) { c.style.display = ''; });
                                group.moreBtn.style.display = 'none';
                            } else {
                                var hasCheckedHidden = group.hidden.some(function (c) {
                                    return c.querySelector(':scope > .cs-row > .cs-check > input').checked;
                                });
                                group.moreBtn.style.display = '';
                                if (!hasCheckedHidden) {
                                    group.hidden.forEach(function (c) { c.style.display = 'none'; });
                                    group.moreBtn.textContent = '+' + group.hidden.length + ' {{ __('more') }}';
                                }
                            }
                        });
                    });
                }

                var globalCheckbox = root.querySelector('.cs-global-checkbox');
                if (globalCheckbox) {
                    globalCheckbox.addEventListener('change', function () {
                        if (treeWrapper) {
                            treeWrapper.style.display = globalCheckbox.checked ? 'none' : 'block';
                        }
                    });
                }

                // Checked parents check their children; long child lists collapse behind "+N more".
                // Runs on init and again after CategoryScope.render() swaps the tree.
                function setupTree() {
                    if (!list) return;
                    moreGroups.forEach(function (group) { group.moreBtn.remove(); });
                    moreGroups = [];

                    list.querySelectorAll('.category-checkbox:checked').forEach(function (input) {
                        var category = input.closest('.cs-category');
                        category.querySelectorAll('.subcategories .category-checkbox').forEach(function (child) {
                            child.checked = true;
                        });
                    });

                    list.querySelectorAll('.cs-subcategories').forEach(function (sub) {
                        var children = Array.prototype.filter.call(sub.children, function (el) {
                            return el.classList.contains('cs-category');
                        });
                        if (children.length <= 2) return;

                        var hidden = children.slice(2);
                        var hasCheckedHidden = hidden.some(function (c) {
                            return c.querySelector(':scope > .cs-row > .cs-check > input').checked;
                        });
                        if (hasCheckedHidden) return;

                        hidden.forEach(function (c) { c.style.display = 'none'; });

                        var moreBtn = document.createElement('button');
                        moreBtn.type = 'button';
                        moreBtn.className = 'cs-more-btn';
                        moreBtn.textContent = '+' + hidden.length + ' {{ __('more') }}';
                        moreBtn.addEventListener('click', function () {
                            var isHidden = hidden[0].style.display === 'none';
                            hidden.forEach(function (c) { c.style.display = isHidden ? '' : 'none'; });
                            moreBtn.textContent = isHidden ? '{{ __('Show less') }}' : ('+' + hidden.length + ' {{ __('more') }}');
                        });
                        sub.appendChild(moreBtn);
                        moreGroups.push({ hidden: hidden, moreBtn: moreBtn });
                    });
                }

                setupTree();
                refresh();

                root.csAfterRender = function () {
                    if (searchInput) searchInput.value = '';
                    setupTree();
                    refresh();
                };

                if (treeWrapper) {
                    requestAnimationFrame(function () {
                        var firstSelected = treeWrapper.querySelector('.category-checkbox:checked');
                        if (firstSelected) {
                            firstSelected.scrollIntoView({ block: 'center' });
                        }
                    });
                }
            }

            function escapeHtml(text) {
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            // Same markup as category/treeview.blade.php, built from a flat list
            // ({id, name, parent_category_id} — e.g. lookup.categories data).
            function treeHtml(categories, checkboxName, disabled) {
                var ids = {};
                categories.forEach(function (c) { ids[c.id] = true; });
                var byParent = {};
                categories.forEach(function (c) {
                    var parent = ids[c.parent_category_id] ? c.parent_category_id : 0;
                    (byParent[parent] = byParent[parent] || []).push(c);
                });

                function level(parentId, depth) {
                    return (byParent[parentId] || []).map(function (c) {
                        var hasChildren = !!byParent[c.id];
                        var icon = hasChildren ? (depth === 0 ? 'ph-folder-open' : 'ph-folder') : 'ph-tag';
                        return '<div class="category cs-category" data-depth="' + depth + '">' +
                            // Indent per level = --cs-indent (28px by default; smaller on phones, see custom.css).
                            '<div class="category-header cs-row" style="padding-left: calc(10px + ' + depth + ' * var(--cs-indent, 28px));">' +
                            (hasChildren
                                ? '<button type="button" class="cs-toggle" aria-label="{{ __('Toggle') }}"><i class="ph ph-caret-down"></i></button>'
                                : '<span class="cs-toggle-spacer"></span>') +
                            '<label class="cs-check"><input type="checkbox" name="' + escapeHtml(checkboxName) + '" value="' + c.id + '" class="category-checkbox"' + (disabled ? ' disabled' : '') + '>' +
                            '<span class="cs-check-box"><i class="ph-bold ph-check cs-check-icon"></i></span></label>' +
                            '<span class="cs-cat-icon"><i class="ph ' + icon + '"></i></span>' +
                            '<span class="cs-cat-name">' + escapeHtml(c.name) + '</span>' +
                            (hasChildren ? '<span class="cs-badge" data-selected-badge>0 {{ __('Selected') }}</span>' : '') +
                            '</div>' +
                            (hasChildren
                                ? '<div class="subcategories cs-subcategories" style="display: none; --line-left: calc(22px + ' + depth + ' * var(--cs-indent, 28px));">' + level(c.id, depth + 1) + '</div>'
                                : '') +
                            '</div>';
                    }).join('');
                }

                return level(0, 0);
            }

            /**
             * Swap a category scope's tree for another set of categories (e.g. after a Module
             * change), keeping search, badges, chips and "+N more" working. Picks are cleared.
             * Used by: packages/partials/form-script.blade.php (Ad Packages).
             */
            window.CategoryScope = window.CategoryScope || {};
            window.CategoryScope.render = function (root, categories) {
                var wrapper = root && root.querySelector('#category_selection');
                if (!wrapper) return;
                wrapper.innerHTML = treeHtml(categories || [], root.dataset.checkboxName || 'selected_categories[]', root.dataset.disabled === '1');
                if (root.csAfterRender) root.csAfterRender();
            };

            document.querySelectorAll('.cs-scope-root').forEach(function (root) {
                try {
                    initCategoryScope(root);
                } catch (err) {
                    console.error('category-scope init failed', err);
                }
            });
        })();
    </script>
@endonce
