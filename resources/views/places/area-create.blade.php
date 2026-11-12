@extends('layouts.main')
@section('title')
    {{ __('Add Area') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h4>@yield('title')</h4>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <div class="buttons">
            <a class="btn btn-primary" href="{{ route('area.index') }}">
                < {{ __('Back to Area') }} </a>
        </div>
        <div class="row">
            <form class="create-form" id="area-create-form" action="{{ route('area.create') }}" method="POST" data-parsley-validate enctype="multipart/form-data">
                @csrf
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">{{ __('Add Area') }}</div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="area_country" class="mandatory form-label">{{ __('Country') }}</label>
                                        <select class="form-control select2" id="area_country" name="country_id" required>
                                            <option value="">{{ __('--Select Country--') }}</option>
                                            @foreach ($countries as $country)
                                                <option value="{{ $country->id }}">{{ $country->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="area_state" class="mandatory form-label">{{ __('State') }}</label>
                                        <select class="form-control select2" id="area_state" name="state_id" required>
                                            <option value="">{{ __('--Select State--') }}</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="area_city" class="mandatory form-label">{{ __('City') }}</label>
                                        <select class="form-control select2" id="area_city" name="city_id" required>
                                            <option value="">{{ __('--Select City--') }}</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            {{-- Rows are added / removed by the .add-area-button / .remove-area-button handlers in custom.js. --}}
                            <div id="areas-container">
                                <div class="row area-input-group mb-3">
                                    <div class="col-md-4 form-group">
                                        <label class="mandatory form-label mt-2">{{ __('Area Name') }}</label>
                                        <div class="d-flex">
                                            <input type="text" name="name[]" class="form-control me-2" placeholder="{{ __('Enter Area name') }}" required>
                                        </div>
                                    </div>
                                    <div class="form-group col-md-4 col-sm-12">
                                        <label class="mandatory form-label mt-2">{{ __('Latitude') }}</label>
                                        <div class="d-flex mb-2">
                                            <input type="text" name="latitude[]" class="form-control me-2" placeholder="{{ __('Enter Latitude') }}">
                                        </div>
                                    </div>
                                    <div class="form-group col-md-4 col-sm-12">
                                        <label class="mandatory form-label mt-2">{{ __('Longitude') }}</label>
                                        <div class="d-flex mb-2">
                                            <input type="text" name="longitude[]" class="form-control me-2" placeholder="{{ __('Enter Longitude') }}">
                                            <button type="button" class="btn btn-danger remove-area-button ms-2">-</button>
                                            <button type="button" class="btn btn-secondary add-area-button ms-2">+</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="alert alert-warning" role="alert">
                                        {{ __('Please select the correct location on the map. Do not place the marker for any other location.') }}
                                    </div>
                                    <div id="map" style="width: 100%; height: 400px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" class="btn btn-primary">{{ __('Submit') }}</button>
                    </div>
                </div>
            </form>
        </div>
    </section>
@endsection

@section('script')
    <script>
        $(function () {
            // A picked point fills the last area row.
            const map = window.mapUtils.initializeMap('map', 23.245131597411042, 69.66773986816408, 13, {
                onChange: function (lat, lng) {
                    const $row = $('#area-create-form .area-input-group').last();
                    $row.find('input[name="latitude[]"]').val(lat);
                    $row.find('input[name="longitude[]"]').val(lng);
                }
            });
            // In the side drawer the map is created while the drawer slides in.
            setTimeout(function () {
                if (map && typeof map.invalidateSize === 'function') map.invalidateSize();
            }, 400);
        });

        // Delegated so it also works when this page is loaded into the side drawer.
        function areaFillSelect($select, placeholder, url) {
            $select.html($('<option>', {value: '', text: window.trans(placeholder)})).trigger('change.select2');
            if (!url) return;
            ajaxRequest('GET', url, null, null, function (response) {
                $.each(response.data, function (key, value) {
                    $select.append($('<option>', {value: value.id, text: value.name}));
                });
            });
        }

        $(document).on('change', '#area-create-form #area_country', function () {
            const countryId = $(this).val();
            areaFillSelect($('#area_state'), '--Select State--', countryId ? window.baseurl + 'states/search?country_id=' + countryId : null);
            areaFillSelect($('#area_city'), '--Select City--', null);
        });

        $(document).on('change', '#area-create-form #area_state', function () {
            const stateId = $(this).val();
            areaFillSelect($('#area_city'), '--Select City--', stateId ? window.baseurl + 'cities/search?state_id=' + stateId : null);
        });
    </script>
@endsection
