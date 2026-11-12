@extends('layouts.main')
@section('title')
    {{ __('Feature Section') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-7 order-md-1 order-last">
                <h4>@yield('title')</h4>
                <p class="text-muted mb-0">{{ __('Create and manage reusable dynamic sections for your marketplace.') }}</p>
            </div>
            <div class="col-12 col-md-5 order-md-2 order-first d-flex justify-content-end align-items-start">
                @can('feature-section-create')
                    <a data-side-drawer class="btn btn-primary" href="{{ route('feature-section.create') }}"
                        data-drawer-title="{{ __('Create Feature Section') }}" data-drawer-subtitle="{{ __('Configure a reusable dynamic section for your marketplace.') }}">+ {{ __('Create Section') }}</a>
                @endcan
            </div>
        </div>
    </div>
@endsection
@section('content')
    <section class="section">
        <div class="card">
            <div class="card-body">
                {{-- Filters (featureSectionQueryParams); .module-table-filter refreshes the table on change. --}}
                <div id="fs-toolbar" class="row g-2">
                    @if ($modules->count() > 1)
                        <div class="col-auto">
                            <select id="filter_module_id" class="form-select module-table-filter" data-table="#table_list" aria-label="{{ __('Module') }}">
                                <option value="">{{ __('All Modules') }}</option>
                                @foreach ($modules as $module)
                                    <option value="{{ $module->id }}">{{ $module->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="col-auto fs-category-filter-col">
                        <select id="fs-category-filter" class="form-select module-table-filter" data-table="#table_list" data-category-module="{{ $categoryModuleId }}" aria-label="{{ __('Category') }}">
                            <option value="">{{ __('All Categories') }}</option>
                            @include('category.dropdowntree', ['categories' => $categories])
                        </select>
                    </div>
                    <div class="col-auto">
                        <select id="fs-status-filter" class="form-select module-table-filter" data-table="#table_list" aria-label="{{ __('Status') }}">
                            <option value="">{{ __('All Status') }}</option>
                            <option value="1">{{ __('Active') }}</option>
                            <option value="0">{{ __('Inactive') }}</option>
                        </select>
                    </div>
                </div>
                <small class="text-muted d-block mt-2">* {{ __("To change the order, Drag the Table column Up & Down") }}</small>
                @php
                    $cols = [
                        ['field'=>'style','title'=>__('Preview'),'formatter'=>'styleImageFormatter'],
                        ['field'=>'title','title'=>__('Section'),'sortable'=>true,'formatter'=>'featureSectionTitleFormatter','escape'=>false],
                    ];
                    if ($modules->count() > 1) {
                        $cols[] = ['field'=>'module_name','title'=>__('Module'),'sortable'=>false];
                    }
                    $cols = array_merge($cols, [
                        ['field'=>'style_name','title'=>__('Style'),'formatter'=>'featureSectionStyleFormatter'],
                        ['field'=>'filter','title'=>__('Rule'),'sortable'=>true,'formatter'=>'featureSectionRuleFormatter','escape'=>false],
                        ['field'=>'status','title'=>__('Status'),'sortable'=>true,
                            'formatter'=>auth()->user()->can('feature-section-update') ? 'statusSwitchFormatter' : 'sliderStatusBadgeFormatter'],
                        ['field'=>'used_on','title'=>__('Used On'),'sortable'=>false],
                    ]);
                    if(auth()->user()->canany(['feature-section-update','feature-section-delete'])) {
                        $cols[] = ['field'=>'operate','title'=>__('Action'),'escape'=>false,'sortable'=>false];
                    }
                @endphp
                <x-data-table
                    id="table_list"
                    :url="route('feature-section.show',1)"
                    click-to-select
                    fixed-columns
                    table-name="feature_sections"
                    show-export
                    sort-name="sequence"
                    sort-order="asc"
                    export-file-name="featured-section-list"
                    toolbar-id="fs-toolbar"
                    :extra="['data-search-align'=>'right','data-query-params'=>'featureSectionQueryParams','data-id-field'=>'id','data-reorderable-rows'=>'true','data-use-row-attr-func'=>'true']"
                    :columns="$cols"
                />
            </div>
        </div>
    </section>
@endsection

@section('script')
    <script>
        // Category filter follows the module filter (lookup.categories); the table refresh comes from .module-table-filter.
        $('#filter_module_id').on('change', function () {
            loadCategoryOptionsForModule($('#fs-category-filter'), $(this).val() || '');
        });
    </script>
@endsection
