@extends('layouts.main')

@section('title')
    {{ __('Add City') }}
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
            <a class="btn btn-primary" href="{{ route('cities.index') }}">
                < {{ __('Back to Cities') }} </a>
        </div>
        <div class="row">
            <form class="create-form" id="city-create-form" action="{{ route('city.create') }}" method="POST" data-parsley-validate enctype="multipart/form-data">
                @csrf
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">{{ __('Add City') }}</div>
                        <div class="card-body mt-3">
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label for="city_country" class="mandatory form-label">{{ __('Country') }}</label>
                                    <select name="country_id" id="city_country" class="form-control form-select" data-placeholder="{{ __('Select Country') }}">
                                        <option value="">{{ __('Select Country') }}</option>
                                        @foreach ($countries as $country)
                                            <option value="{{ $country->id }}">{{ $country->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="col-md-6 form-group">
                                    <label for="city_state" class="mandatory form-label">{{ __('State') }}</label>
                                    <select name="state_id" id="city_state" class="form-control form-select" data-placeholder="{{ __('Select State') }}">
                                        <option value="">{{ __('Select State') }}</option>
                                    </select>
                                </div>
                            </div>
                            {{-- Rows are added / removed by the .add-city-button / .remove-city-button handlers in custom.js. --}}
                            <div id="city-container">
                                <div class="row city-input-group">
                                    <div class="form-group col-md-4 col-sm-12">
                                        <label class="mandatory form-label mt-2">{{ __('City Name') }}</label><span class="text-danger">*</span>
                                        <div class="d-flex mb-2">
                                            <input type="text" name="name[]" class="form-control me-2" placeholder="{{ __('Enter City name') }}">
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
                                            <button type="button" class="btn btn-secondary add-city-button">+</button>
                                            <button type="button" class="btn btn-danger remove-city-button ms-2">-</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <div id="map" style="width: 100%; height: 400px;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-12 text-end">
                        <input type="submit" class="btn btn-primary" value="{{ __('Create') }}">
                    </div>
                </div>
            </form>
        </div>
    </section>
@endsection

@section('script')
    <script>
        $(function () {
            // A picked point fills the last city row.
            const map = window.mapUtils.initializeMap('map', 23.245131597411042, 69.66773986816408, 13, {
                onChange: function (lat, lng) {
                    const $row = $('#city-create-form .city-input-group').last();
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
        $(document).on('change', '#city-create-form #city_country', function () {
            const $state = $('#city_state');
            $state.html($('<option>', {value: '', text: window.trans('Select State')}));
            if (!$(this).val()) return;
            ajaxRequest('GET', window.baseurl + 'states/search?country_id=' + $(this).val(), null, null, function (response) {
                $.each(response.data, function (key, value) {
                    $state.append($('<option>', {value: value.id, text: value.name}));
                });
            });
        });
    </script>
@endsection
