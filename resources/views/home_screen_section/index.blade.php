@extends('layouts.main')
@section('title')
    {{ __('Home Screen') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12">
                <h4>@yield('title')</h4>
                <p class="text-muted mb-0">{{ __('Manage homepage content and layout for each marketplace module.') }}</p>
            </div>
        </div>
    </div>
@endsection

@section('content')
    @php
        // Per section type: icon (Phosphor), description and the admin page that configures it.
        $sectionMeta = [
            'all_categories' => ['label' => __('All Categories'), 'icon' => 'ph-squares-four', 'description' => __('Category grid'),
                'route' => 'category.index', 'can' => ['category-list', 'category-create', 'category-update', 'category-delete']],
            'slider' => ['label' => __('Slider'), 'icon' => 'ph-images', 'description' => __('Promotional homepage carousel'),
                'route' => 'slider.index', 'can' => ['slider-list', 'slider-create', 'slider-update', 'slider-delete']],
            'popular_categories' => ['label' => __('Popular Categories'), 'icon' => 'ph-stack', 'description' => __('Highlighted categories'),
                'route' => 'home-screen-section.popular-categories.index', 'can' => ['home-screen-section-list', 'home-screen-section-update']],
            'featured_section' => ['label' => __('Feature Sections'), 'icon' => 'ph-sparkle', 'description' => __('Dynamic content sections'),
                'route' => 'feature-section.index', 'can' => ['feature-section-list', 'feature-section-create', 'feature-section-update', 'feature-section-delete']],
            'all_ads' => ['label' => __('All Advertisements'), 'icon' => 'ph-list-bullets', 'description' => __('Latest listings feed'),
                'route' => 'advertisement.index', 'can' => ['advertisement-list', 'advertisement-update']],
        ];
        $canUpdate = auth()->user()->can('home-screen-section-update');
        $moduleName = $modules->firstWhere('id', $sectionModuleId)?->name;
    @endphp
    <section class="section home-screen-page">
        @if ($modules->count() > 1)
            <div class="card hs-card">
                <div class="card-body">
                    <label for="section_module_filter" class="form-label fw-semibold">{{ __('Marketplace Module') }}</label>
                    <select id="section_module_filter" class="form-select hs-module-select" aria-describedby="section_module_hint">
                        @foreach ($modules as $module)
                            <option value="{{ $module->id }}" @selected($module->id === $sectionModuleId)>{{ $module->name }}</option>
                        @endforeach
                    </select>
                    <small id="section_module_hint" class="hs-hint"><i class="ph-fill ph-info"></i> {{ __('Configure the :module homepage.', ['module' => $moduleName]) }}</small>
                </div>
            </div>
        @endif

        <div class="card hs-card">
            <div class="hs-card-head">
                <h5 class="mb-1">{{ __('Homepage Sections') }}</h5>
                <p class="text-muted mb-0">{{ __('Sections in the order they appear on the homepage.') }}</p>
            </div>
            <ul class="hs-section-list list-unstyled mb-0" id="hs-section-list">
                @foreach ($sections as $section)
                    @php($meta = $sectionMeta[$section->section_type] ?? ['label' => $section->section_type, 'icon' => 'ph-square', 'description' => '', 'route' => null, 'can' => []])
                    <li class="hs-section-row" data-id="{{ $section->id }}" data-section-type="{{ $section->section_type }}">
                        <span class="hs-section-icon" aria-hidden="true"><i class="ph-fill {{ $meta['icon'] }}"></i></span>
                        <span class="hs-section-text">
                            <strong>{{ $meta['label'] }}</strong>
                            <small>{{ $meta['description'] }}</small>
                        </span>
                        <span class="hs-section-actions">
                            <span class="badge hs-status-badge {{ $section->is_active ? 'is-active' : '' }}" data-on="{{ __('Active') }}" data-off="{{ __('Hidden') }}">{{ $section->is_active ? __('Active') : __('Hidden') }}</span>
                            @if ($canUpdate)
                                <span class="form-check form-switch m-0">
                                    <input class="form-check-input hs-section-toggle" type="checkbox" role="switch" @checked($section->is_active)
                                           aria-label="{{ __('Show :section on the homepage', ['section' => $meta['label']]) }}">
                                </span>
                            @endif
                            @if ($meta['route'] && auth()->user()->canany($meta['can']))
                                <a href="{{ route($meta['route']) }}" class="btn btn-light border btn-sm hs-configure-btn"><i class="ph-fill ph-gear"></i> {{ __('Configure') }}</a>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endsection

@section('js')
    <script>
        (function () {
            const $list = $('#hs-section-list');

            // Module switch: reload with that module's sections.
            $('#section_module_filter').on('change', function () {
                window.location.href = '{{ route('home-screen-section.index') }}?module_id=' + encodeURIComponent($(this).val());
            });

            @if ($canUpdate)
                // Shown / hidden: saved as soon as the switch flips (common.status.change);
                // the badge follows on success, the switch reverts on failure.
                const setBadge = ($row, active) => {
                    const $badge = $row.find('.hs-status-badge');
                    $badge.toggleClass('is-active', active).text($badge.data(active ? 'on' : 'off'));
                };

                $list.on('change', '.hs-section-toggle', function () {
                    const $toggle = $(this).prop('disabled', true);
                    const $row = $toggle.closest('.hs-section-row');
                    const active = this.checked;
                    ajaxRequest('PUT', '{{ route('common.status.change') }}', {
                        id: $row.data('id'), table: 'home_screen_sections', column: 'is_active', status: active ? 1 : 0,
                    }, null, response => {
                        setBadge($row, active);
                        showSuccessToast(response.message);
                    }, error => {
                        $toggle.prop('checked', !active);
                        showErrorToast(error.message);
                    }, () => $toggle.prop('disabled', false));
                });
            @endif
        })();
    </script>
@endsection
