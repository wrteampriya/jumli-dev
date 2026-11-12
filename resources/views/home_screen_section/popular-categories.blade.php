@extends('layouts.main')
@section('title')
    {{ __('Popular Categories') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-7 order-md-1 order-last">
                <h4>@yield('title')</h4>
                <p class="text-muted mb-0">{{ __('Manage categories highlighted across marketplace homepages.') }}</p>
            </div>
            <div class="col-12 col-md-5 order-md-2 order-first d-flex justify-content-end align-items-start gap-2">
                @can('home-screen-section-update')
                    @if ($popularCategoriesCount > 1)
                        <a href="{{ route('home-screen-section.popular-categories.order') }}" class="btn btn-light border"><i class="ph-bold ph-arrows-down-up"></i> {{ __('Change Order') }}</a>
                    @endif
                    <a data-side-drawer class="btn btn-primary" href="{{ route('home-screen-section.popular-categories.create') }}"
                       data-drawer-title="{{ __('Add Popular Categories') }}" data-drawer-subtitle="{{ __('Choose categories and subcategories from the tree view.') }}">+ {{ __('Add Categories') }}</a>
                @endcan
            </div>
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <div class="card">
            <div class="card-body">
                <div id="pc-toolbar" class="row g-2">
                    @if ($modules->count() > 1)
                        <div class="col-auto">
                            <select id="filter_module_id" class="form-select module-table-filter" data-table="#popular_categories_table" aria-label="{{ __('Module') }}">
                                <option value="">{{ __('All Modules') }}</option>
                                @foreach ($modules as $module)
                                    <option value="{{ $module->id }}">{{ $module->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="col-auto">
                        <select id="pc-status-filter" class="form-select module-table-filter" data-table="#popular_categories_table" aria-label="{{ __('Status') }}">
                            <option value="">{{ __('All Status') }}</option>
                            <option value="1">{{ __('Active') }}</option>
                            <option value="0">{{ __('Inactive') }}</option>
                        </select>
                    </div>
                </div>
                @php
                    $cols = [];
                    if ($modules->count() > 1) {
                        $cols[] = ['field'=>'module_name','title'=>__('Module')];
                    }
                    $cols = array_merge($cols, [
                        ['field'=>'category_name','title'=>__('Category'),'formatter'=>'boldFormatter'],
                        ['field'=>'subcategory_name','title'=>__('Subcategory'),'formatter'=>'dashFormatter'],
                        ['field'=>'used_on','title'=>__('Used On')],
                        ['field'=>'status','title'=>__('Status'),'sortable'=>true,
                            'formatter'=>auth()->user()->can('home-screen-section-update') ? 'statusSwitchFormatter' : 'sliderStatusBadgeFormatter'],
                    ]);
                    if (auth()->user()->can('home-screen-section-update')) {
                        $cols[] = ['field'=>'operate','title'=>__('Action'),'escape'=>false,'align'=>'center'];
                    }
                @endphp
                <x-data-table
                    id="popular_categories_table"
                    :url="route('home-screen-section.popular-categories.list')"
                    sort-name="sequence"
                    sort-order="asc"
                    toolbar-id="pc-toolbar"
                    table-name="popular_categories"
                    :extra="['data-query-params' => 'popularCategoriesQueryParams', 'data-id-field' => 'id']"
                    :columns="$cols"
                />
            </div>
        </div>
    </section>
@endsection
