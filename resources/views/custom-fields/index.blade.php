@extends('layouts.main')
@section('title')
    {{__("Custom Fields")}}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row d-flex align-items-center">
            <div class="col-12 col-md-6">
                <h4 class="mb-0">@yield('title')</h4>
            </div>
            <div class="col-12 col-md-6 text-end mt-2 mt-md-0">
                <div class="d-flex flex-wrap gap-2 justify-content-end">
                    @can('custom-field-create')
                        <a data-side-drawer href="{{ route('custom-fields.create', ['id' => 0]) }}" class="btn btn-primary mb-0"
                            data-drawer-title="{{ __('Add Custom Field') }}" data-drawer-subtitle="{{ __('Update configuration and save your changes.') }}">
                            <span class="d-none d-sm-inline">+ {{__("Create Custom Field")}}</span>
                            <span class="d-sm-none">+ {{__("Create")}}</span>
                        </a>
                        <a href="{{ route('custom-fields.bulk-upload') }}" class="btn btn-success mb-0">
                            <i class="fas fa-upload"></i> <span class="d-none d-sm-inline">{{__("Bulk Upload")}}</span><span class="d-sm-none">{{__("Upload")}}</span>
                        </a>
                    @endcan
                    @can('custom-field-update')
                        <a href="{{ route('custom-fields.bulk-update') }}" class="btn btn-warning mb-0">
                            <i class="fas fa-edit"></i> <span class="d-none d-sm-inline">{{__("Bulk Update")}}</span><span class="d-sm-none">{{__("Update")}}</span>
                        </a>
                    @endcan
                </div>
            </div>
        </div>
    </div>
@endsection

@section('css')
    <style>
        /* select2 fixes its pixel width at init; let it follow the column instead. */
        #filters .select2-container {
            width: 100% !important;
        }
    </style>
@endsection

@section('content')
    <section class="section">
        <div class="card">
            <div class="card-body">
                <div class="row">
                    <div class="col-12">
                        <div id="filters">
                            <div class="row">
                                <x-module-filter :modules="$modules" class="col-md-4" />
                                {{-- filter-control reads every select in its container and needs a
                                     bootstrap-table-filter-control-* class on each, so it gets this
                                     wrapper rather than #filters. display: contents keeps the columns
                                     laid out by the row above. --}}
                                <div id="custom-field-filter-controls" style="display: contents">
                                <div class="col-12 col-md-4">
                                    <label for="filter">{{ __("Category") }}</label>
                                    <select name="category" class="form-control select2 bootstrap-table-filter-control-category_names" aria-label="category">
                                        <option value="">{{ __("All") }}</option>
                                        @include('category.dropdowntree', ['categories' => $categories])
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label for="type">{{ __("Type") }}</label>
                                    <select name="type" class="form-select form-control bootstrap-table-filter-control-type" id="type">
                                        <option value="">{{ __("All") }}</option>
                                        <option value="number">{{ __("Number Input") }}</option>
                                        <option value="textbox">{{ __("Text Input") }}</option>
                                        <option value="fileinput">{{ __("File Input") }}</option>
                                        <option value="radio">{{ __("Radio") }}</option>
                                        <option value="dropdown">{{ __("Dropdown") }}</option>
                                        <option value="checkbox">{{ __("Checkboxes") }}</option>
                                    </select>
                                </div>
                                </div>
                            </div>
                        </div>
                        @php
                            $cfCols = [
                                ['field'=>'state','title'=>'','checkbox'=>true],
                                ['field'=>'id','title'=>__('ID'),'align'=>'center','sortable'=>true],
                                ['field'=>'image','title'=>__('Image'),'align'=>'center','formatter'=>'imageFormatter'],
                                ['field'=>'name','title'=>__('Name'),'align'=>'center','escape'=>true,'sortable'=>true],
                                ['field'=>'category_names','title'=>__('Category'),'align'=>'center','filterName'=>'category_id','filterControl'=>'select','filterData'=>''],
                                ['field'=>'type','title'=>__('Type'),'align'=>'center','sortable'=>true,'filterName'=>'type','filterControl'=>'select','filterData'=>''],
                            ];
                            // Only shown once a second module exists — invisible today.
                            if(\App\Models\Module::count() > 1) {
                                $cfCols[] = ['field'=>'module_name','title'=>__('Module'),'align'=>'center','sortable'=>false];
                            }
                            if(auth()->user()->canany(['custom-field-update','custom-field-delete'])) {
                                $cfCols[] = ['field'=>'operate','title'=>__('Action'),'escape'=>false,'sortable'=>false];
                            }
                        @endphp
                        <x-data-table
                            id="table_list"
                            :url="route('custom-fields.show',1)"
                            toolbar-id="filters"
                            filter-control-container="#custom-field-filter-controls"
                            click-to-select
                            filter-control
                            fixed-columns
                            show-export
                            export-file-name="custom-field-list"
                            :extra="['data-search-align'=>'right','data-query-params'=>'moduleQueryParams']"
                            :columns="$cfCols"
                        />
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
