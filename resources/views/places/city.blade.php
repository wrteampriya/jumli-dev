@extends('layouts.main')

@section('title')
    {{ __('Cities') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h4>@yield('title')</h4>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first"></div>
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <div class="row m-3">
            <div class="col-12 text-end">
                @can('city-create')
                    <a data-side-drawer href="{{ route('cities.create') }}" class="btn btn-primary me-2">+ {{ __('Add City') }}</a>
                @endcan
                @can('city-update')
                    <a href="{{ route('cities.translation') }}" class="btn btn-primary">
                        <i class="fa fa-language me-2"></i> {{ __('Translate Cities') }}
                    </a>
                @endcan
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        @php
                            $filtersHtml = '<div class="row">
                                <div class="col-12 col-md-4">
                                    <label for="filter_country">'.__("Country").'</label>
                                    <select class="form-control bootstrap-table-filter-control-country_name" id="filter_country">
                                        <option value="">'.__("All").'</option>';
                            foreach($countries as $country) {
                                $filtersHtml .= '<option value="'.$country->id.'">'.$country->name.'</option>';
                            }
                            $filtersHtml .= '</select></div>
                                <div class="col-12 col-md-4">
                                    <label for="filter_state">'.__("State").'</label>
                                    <select class="form-control bootstrap-table-filter-control-state_name" id="filter_state">
                                        <option value="">'.__("All").'</option>
                                    </select></div></div>';
                        @endphp

                        <x-data-table
                            id="table_list"
                            :url="route('cities.show',1)"
                            toolbar-id="filters"
                            :toolbar-slot="$filtersHtml"
                            click-to-select
                            filter-control
                            fixed-columns
                            show-export
                            export-file-name="city-list"
                            table-name="cities"
                            status-column="status"
                            :columns="[
                                ['field'=>'id','title'=>__('ID'),'sortable'=>true],
                                ['field'=>'name','title'=>__('Name'),'sortable'=>true],
                                ['field'=>'country_name','title'=>__('Country Name'),'sortable'=>true,'filterName'=>'country_id','filterControl'=>'select','filterData'=>''],
                                ['field'=>'state_name','title'=>__('State Name'),'sortable'=>true,'filterName'=>'state_id','filterControl'=>'select','filterData'=>''],
                                ['field'=>'longitude','title'=>__('Longitude'),'sortable'=>false],
                                ['field'=>'latitude','title'=>__('Latitude'),'sortable'=>false],
                                ['field'=>'country.emoji','title'=>__('Flag')],
                                ['field'=>'status','title'=>__('Status'),'sortable'=>true,'escape'=>false,'formatter'=>'statusSwitchFormatter', 'align' => 'center'],
                                ['field'=>'operate','title'=>__('Action'),'sortable'=>false,'escape'=>false,'events'=>'cityEvents'],
                            ]"
                        />
                    </div>
                </div>
                @can('city-update')
                <!-- EDIT MODEL MODEL -->
                    <div id="editModal" class="modal fade modal-drawer" tabindex="-1" role="dialog" aria-labelledby="myModalLabel1" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="myModalLabel1">{{ __('Edit City') }}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <form id="edit-form" class="edit-form" action="" method="POST" enctype="multipart/form-data">
                                        @csrf
                                        <div class="row">
                                            <div class="col-md-6 form-group">
                                                <label for="country" class="mandatory form-label">{{__("Country")}}</label>
                                                <select name="country_id" id="edit_country" class="form-control country form-select" data-placeholder="{{__("Select Country")}}">
                                                    <option value="">Select Country</option>
                                                    @foreach ($countries as $country)
                                                        <option value="{{ $country->id }}">{{ $country->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>

                                            <div class="col-md-6 form-group">
                                                <label for="state" class="mandatory form-label">{{__("State")}}</label>
                                                <select name="state_id" id="edit_state" class="form-control form-select" data-placeholder="{{__("Select State")}}">
                                                    @foreach ($states as $state)
                                                        <option value="{{ $state->id }}">{{ $state->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                        <div class="row mt-3">
                                            <div class="form-group col-md-4 col-sm-12">
                                                <label for="name" class="mandatory form-label">City Name</label>
                                                <div class="d-flex mb-2">
                                                    <input type="text" name="name" class="form-control me-2" id="edit_name" placeholder="{{__("Enter City name")}}">
                                                </div>
                                            </div>
                                            <div class="form-group col-md-4 col-sm-12">
                                                <label for="latitude" class="mandatory form-label">Latitude</label>
                                                <div class="d-flex mb-2">
                                                    <input type="text" name="latitude" id="edit_latitude" class="form-control me-2" placeholder="{{__("Enter Latitude")}}">
                                                </div>
                                            </div>
                                            <div class="form-group col-md-4 col-sm-12">
                                                <label for="longitude" class="mandatory form-label">Longitude</label>
                                                <div class="d-flex mb-2">
                                                    <input type="text" name="longitude" id="edit_longitude" class="form-control me-2" placeholder="{{__("Enter Longitude")}}">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row mt-3">
                                            <div class="col-12">
                                                <div id="edit_map" style="height: 400px;"></div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary waves-effect" data-bs-dismiss="modal">{{ __('Close') }}</button>
                                            <button type="submit" class="btn btn-primary waves-effect waves-light">{{ __('Save') }}</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                @endcan
    </section>
@endsection
