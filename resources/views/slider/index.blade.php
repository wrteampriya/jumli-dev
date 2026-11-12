@extends('layouts.main')

@section('title')
    {{ __('Slider') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-7 order-md-1 order-last">
                <h4>@yield('title')</h4>
                <p class="text-muted mb-0">{{ __('Create and manage promotional sliders used across your marketplace homepages.') }}</p>
            </div>
            <div class="col-12 col-md-5 order-md-2 order-first d-flex justify-content-end align-items-start">
                @can('slider-create')
                    <a data-side-drawer class="btn btn-primary" href="{{ route('slider.create') }}"
                        data-drawer-title="{{ __('Create Slider') }}" data-drawer-subtitle="{{ __('Update configuration and save your changes.') }}">+ {{ __('Create Slider') }}</a>
                @endcan
            </div>
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <div class="card">
            <div class="card-body">
                {{-- Filters (sliderQueryParams); .module-table-filter refreshes the table on change. --}}
                <div id="slider-toolbar" class="row g-2">
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
                    <div class="col-auto">
                        <select id="slider-platform-filter" class="form-select module-table-filter" data-table="#table_list" aria-label="{{ __('Platform') }}">
                            <option value="">{{ __('All Platforms') }}</option>
                            <option value="web">{{ __('Web Interface') }}</option>
                            <option value="app">{{ __('Mobile App') }}</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <select id="slider-status-filter" class="form-select module-table-filter" data-table="#table_list" aria-label="{{ __('Status') }}">
                            <option value="">{{ __('All Status') }}</option>
                            <option value="1">{{ __('Active') }}</option>
                            <option value="0">{{ __('Inactive') }}</option>
                        </select>
                    </div>
                </div>
                @php
                    $cols = [
                        // Hidden by default; switch it on from the table's column picker (handy when debugging).
                        ['field'=>'id','title'=>__('ID'),'sortable'=>true,'visible'=>false],
                        ['field'=>'image','title'=>__('Preview'),'sortable'=>false,'formatter'=>'imageFormatter'],
                        ['field'=>'name','title'=>__('Slider'),'sortable'=>true,'formatter'=>'sliderTitleFormatter','escape'=>false],
                    ];
                    if ($modules->count() > 1) {
                        $cols[] = ['field'=>'module_name','title'=>__('Module'),'sortable'=>false];
                    }
                    $cols = array_merge($cols, [
                        ['field'=>'destination_label','title'=>__('Destination'),'sortable'=>false,'formatter'=>'sliderDestinationFormatter','escape'=>false],
                        ['field'=>'location_label','title'=>__('Location'),'sortable'=>false],
                        // Toggle for staff who may update sliders; a read-only badge otherwise.
                        ['field'=>'status','title'=>__('Status'),'sortable'=>true,
                            'formatter'=>auth()->user()->can('slider-update') ? 'statusSwitchFormatter' : 'sliderStatusBadgeFormatter'],
                    ]);
                    if(auth()->user()->can('slider-delete')) {
                        $cols[] = ['field'=>'operate','title'=>__('Action'),'escape'=>false,'align'=>'center','sortable'=>false];
                    }
                @endphp
                <x-data-table
                    id="table_list"
                    :url="route('slider.show',1)"
                    click-to-select
                    fixed-columns
                    show-export
                    export-file-name="slider-list"
                    toolbar-id="slider-toolbar"
                    :extra="['data-query-params'=>'sliderQueryParams','data-id-field'=>'id','data-search-align'=>'right']"
                    table-name="sliders"
                    :columns="$cols"
                />
            </div>
        </div>
    </section>
@endsection


