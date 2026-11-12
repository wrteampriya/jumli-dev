@extends('layouts.main')
@section('title')
    {{ __('Add Popular Categories') }}
@endsection

@section('content')
    <section class="section">
        <div class="card">
            <div class="card-body">
                {{--
                    One accordion per module; a module's category tree loads from lookup.categories the
                    first time its accordion opens (with a single module there is no accordion head and
                    the tree loads straight away). Already-popular categories are checked + disabled and
                    have no name, so they aren't sent. Selections in every loaded module are saved together.
                --}}
                <form method="POST" action="{{ route('home-screen-section.popular-categories.store') }}" id="pc-create-form"
                      class="create-form pc-create-form" data-pre-submit-function="pcValidateForm"
                      data-lookup-url="{{ route('lookup.categories') }}" data-popular-ids="{{ json_encode($popularIds) }}">
                    @csrf
                    <p class="text-muted mb-3">{{ __('Select the categories and subcategories that should appear in Popular Categories.') }}</p>

                    @if ($modules->isNotEmpty())
                        <div class="pc-toolbar">
                            <input type="search" id="pc-search" class="form-control pc-search" autocomplete="off"
                                   placeholder="{{ $showModuleHeads ? __('Search in open modules') : __('Search categories') }}">
                            <button type="button" class="btn btn-link pc-expand-all" data-expand="1">{{ __('Expand all') }}</button>
                            <button type="button" class="btn btn-link pc-expand-all" data-expand="0">{{ __('Collapse all') }}</button>
                        </div>
                    @endif

                    @forelse ($modules as $module)
                        @php $isOpen = $loop->first || !$showModuleHeads; @endphp
                        <div class="pc-module {{ $showModuleHeads ? '' : 'pc-module-single' }}" data-module-id="{{ $module->id }}">
                            @if ($showModuleHeads)
                                <button type="button" class="pc-module-head" aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                                        aria-controls="pc-module-body-{{ $module->id }}">
                                    <span class="pc-module-icon" aria-hidden="true">
                                        @if ($module->icon)
                                            <img src="{{ $module->icon }}" alt="">
                                        @else
                                            <i class="ph-fill ph-squares-four"></i>
                                        @endif
                                    </span>
                                    <span class="pc-module-title">
                                        <strong>{{ $module->name }}</strong>
                                        <small>{{ trans_choice(':count category|:count categories', $module->category_count, ['count' => $module->category_count]) }}</small>
                                    </span>
                                    <span class="badge pc-module-selected" hidden></span>
                                    <i class="ph-bold ph-caret-right pc-module-caret" aria-hidden="true"></i>
                                </button>
                            @endif
                            <div class="pc-module-body" id="pc-module-body-{{ $module->id }}" @unless ($isOpen) hidden @endunless>
                                <div class="pc-module-status text-muted">{{ __('Loading categories…') }}</div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted">{{ __('No active categories found.') }}</p>
                    @endforelse

                    <div class="form-actions d-flex justify-content-end align-items-center gap-2 mt-4">
                        <span class="pc-selected-total text-muted me-auto"></span>
                        <a href="{{ route('home-screen-section.popular-categories.index') }}" class="btn btn-light border pc-cancel-btn">{{ __('Cancel') }}</a>
                        <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
@endsection

@section('script')
    <script>
        (function () {
            const $form = $('#pc-create-form');
            if (!$form.length) return;

            const lookupUrl = $form.data('lookup-url');
            const popularIds = new Set(($form.data('popular-ids') || []).map(Number));
            const T = {
                added: "{{ __('Added') }}",
                selectAll: "{{ __('Select all') }}",
                clearAll: "{{ __('Clear all') }}",
                selectAllTitle: "{{ __('Select all subcategories') }}",
                expand: "{{ __('Expand') }}",
                collapse: "{{ __('Collapse') }}",
                loadError: "{{ __('Could not load categories.') }}",
                retry: "{{ __('Retry') }}",
                empty: "{{ __('No active categories found.') }}",
                noMatch: "{{ __('No categories match your search.') }}",
                selected: "{{ __(':count selected') }}",
            };

            window.pcValidateForm = function () {
                if (!$form.find('input[name="category_ids[]"]:checked').length) {
                    showErrorToast("{{ __('Select at least one category.') }}");
                    return false;
                }
                return true;
            };

            $form.on('click', '.pc-cancel-btn', function (e) {
                if ($form.closest('.offcanvas').length && window.SideDrawer) {
                    e.preventDefault();
                    window.SideDrawer.close();
                }
            });

            // ---- Tree rendering (lookup rows: id, name, level, parent_category_id) ----

            function buildTree(rows) {
                const ids = new Set(rows.map(r => r.id));
                const children = new Map();
                // A parent outside the list (inactive) makes the row a root, as in CategoryTree::flatten.
                rows.forEach(row => {
                    const parent = ids.has(row.parent_category_id) ? row.parent_category_id : 0;
                    if (!children.has(parent)) children.set(parent, []);
                    children.get(parent).push(row);
                });
                const total = id => (children.get(id) || []).reduce((sum, row) => sum + 1 + total(row.id), 0);
                const render = parentId => {
                    const $ul = $('<ul class="pc-tree"></ul>');
                    (children.get(parentId) || []).forEach(row => $ul.append(renderNode(row, children.get(row.id) || [], total(row.id), render)));
                    return $ul;
                };
                return render(0);
            }

            function renderNode(row, kids, descendantCount, render) {
                const added = popularIds.has(row.id);
                const $li = $('<li class="pc-node"></li>').attr('data-node', row.id).attr('data-name', String(row.name).toLowerCase());
                const $row = $('<div class="pc-row"></div>').toggleClass('pc-row-branch', kids.length > 0).toggleClass('pc-row-added', added);

                if (kids.length) {
                    $row.append($('<button type="button" class="pc-twisty" aria-expanded="false"><i class="ph-bold ph-caret-right"></i></button>')
                        .attr('aria-label', T.expand + ' ' + row.name));
                } else {
                    $row.append('<span class="pc-twisty-spacer"></span>');
                }

                const $input = $('<input type="checkbox" class="form-check-input">');
                added ? $input.prop({checked: true, disabled: true}) : $input.attr({name: 'category_ids[]', value: row.id});
                const $label = $('<label class="pc-check"></label>').append($input, $('<span class="pc-name"></span>').text(row.name));
                if (kids.length) $label.append($('<span class="pc-count"></span>').text(descendantCount));
                if (added) $label.append($('<span class="badge pc-added"></span>').text(T.added));
                $row.append($label);

                if (kids.length) {
                    $row.append($('<button type="button" class="btn btn-link pc-select-all"></button>').text(T.selectAll).attr('title', T.selectAllTitle));
                }

                $li.append($row);
                if (kids.length) $li.append(render(row.id));
                return $li;
            }

            // ---- Module accordions: load once, on first open ----

            function loadModule($module) {
                const $body = $module.find('.pc-module-body');
                if ($module.data('pc-state') === 'loaded' || $module.data('pc-state') === 'loading') return;
                $module.data('pc-state', 'loading');
                $body.html($('<div class="pc-module-status text-muted"></div>').text("{{ __('Loading categories…') }}"));

                $.get(lookupUrl, {module_id: $module.data('module-id')})
                    .done(function (response) {
                        if (response.error || !Array.isArray(response.data)) return failed(response.message);
                        $module.data('pc-state', 'loaded');
                        $body.empty().append(response.data.length
                            ? buildTree(response.data)
                            : $('<div class="pc-module-status text-muted"></div>').text(T.empty));
                        $body.append($('<div class="pc-module-status pc-no-match text-muted" hidden></div>').text(T.noMatch));
                        applySearch();
                        refreshBranchButtons($body);
                    })
                    .fail(function (xhr) { failed(xhr.responseJSON?.message); });

                function failed(message) {
                    $module.data('pc-state', 'failed');
                    $body.empty().append($('<div class="pc-module-status text-danger"></div>').text(message || T.loadError)
                        .append(' ', $('<button type="button" class="btn btn-link p-0 pc-retry"></button>').text(T.retry)));
                }
            }

            $form.on('click', '.pc-module-head', function () {
                const $module = $(this).closest('.pc-module');
                const open = $(this).attr('aria-expanded') !== 'true';
                $(this).attr('aria-expanded', open);
                $module.find('.pc-module-body').prop('hidden', !open);
                if (open) $module.data('pc-state') === 'loaded' ? applySearch() : loadModule($module);
            });

            $form.on('click', '.pc-retry', function () {
                const $module = $(this).closest('.pc-module');
                $module.removeData('pc-state');
                loadModule($module);
            });

            const openModules = () => $form.find('.pc-module').filter((_, el) => !$(el).find('.pc-module-body').prop('hidden'));

            // ---- Branch expand / collapse ----

            function setOpen($node, open) {
                $node.toggleClass('is-open', open);
                $node.children('.pc-row').find('.pc-twisty').attr('aria-expanded', open);
            }

            $form.on('click', '.pc-twisty', function () {
                const $node = $(this).closest('.pc-node');
                setOpen($node, !$node.hasClass('is-open'));
            });

            $form.on('click', '.pc-expand-all', function () {
                const expand = $(this).data('expand') === 1;
                openModules().find('.pc-node').filter((_, el) => $(el).children('.pc-tree').length).each((_, el) => setOpen($(el), expand));
            });

            // ---- Selection ----

            // "Select all" picks every subcategory below the branch (not the branch itself);
            // when they are all picked already it clears them.
            $form.on('click', '.pc-select-all', function () {
                const $node = $(this).closest('.pc-node');
                const $inputs = $node.children('.pc-tree').find('input[name="category_ids[]"]');
                const allChecked = $inputs.length && $inputs.not(':checked').length === 0;
                $inputs.prop('checked', !allChecked);
                setOpen($node, true);
                $node.children('.pc-tree').find('.pc-node').filter((_, el) => $(el).children('.pc-tree').length).each((_, el) => setOpen($(el), true));
                updateCounts();
            });

            $form.on('change', 'input[name="category_ids[]"]', updateCounts);

            function refreshBranchButtons($scope) {
                $scope.find('.pc-select-all').each(function () {
                    const $inputs = $(this).closest('.pc-node').children('.pc-tree').find('input[name="category_ids[]"]');
                    $(this).prop('hidden', !$inputs.length)
                        .text($inputs.length && !$inputs.not(':checked').length ? T.clearAll : T.selectAll);
                });
            }

            function updateCounts() {
                let total = 0;
                $form.find('.pc-module').each(function () {
                    const count = $(this).find('input[name="category_ids[]"]:checked').length;
                    total += count;
                    $(this).find('.pc-module-selected').text(T.selected.replace(':count', count)).prop('hidden', !count);
                });
                $form.find('.pc-selected-total').text(total ? T.selected.replace(':count', total) : '');
                refreshBranchButtons($form);
            }

            // ---- Search: filters the loaded trees of open modules, opening branches with a match ----

            function escapeHtml(text) {
                return $('<div>').text(text).html();
            }

            function applySearch() {
                const query = ($('#pc-search').val() || '').trim().toLowerCase();

                openModules().each(function () {
                    const $root = $(this).find('.pc-module-body > .pc-tree');
                    if (!$root.length) return;

                    const visit = $li => {
                        const name = $li.data('name');
                        const selfMatch = !query || name.includes(query);
                        let childMatch = false;
                        $li.children('.pc-tree').children('.pc-node').each((_, child) => { childMatch = visit($(child)) || childMatch; });
                        const show = selfMatch || childMatch;
                        $li.toggleClass('pc-hidden', !show).toggleClass('pc-force-open', !!query && childMatch);

                        const $name = $li.children('.pc-row').find('.pc-name');
                        const original = $name.text();
                        const at = query ? original.toLowerCase().indexOf(query) : -1;
                        $name.html(at < 0 ? escapeHtml(original)
                            : escapeHtml(original.slice(0, at)) + '<mark>' + escapeHtml(original.slice(at, at + query.length)) + '</mark>' + escapeHtml(original.slice(at + query.length)));
                        return show;
                    };

                    let any = false;
                    $root.children('.pc-node').each((_, el) => { any = visit($(el)) || any; });
                    $(this).find('.pc-no-match').prop('hidden', any);
                });
            }

            let searchTimer;
            $('#pc-search').on('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(applySearch, 150);
            });
            // Enter in the search box must not submit the form.
            $('#pc-search').on('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });

            openModules().each((_, el) => loadModule($(el)));
            updateCounts();
        })();
    </script>
@endsection
