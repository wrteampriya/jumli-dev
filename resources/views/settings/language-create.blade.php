@extends('layouts.main')

@section('title')
    {{ __('Add Language') }}
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
            <a class="btn btn-primary" href="{{ route('settings.language.index') }}">
                < {{ __('Back to Languages') }} </a>
        </div>
        <div class="row">
            {{-- New languages show up in the language switcher, so reload after saving. --}}
            <form method="POST" action="{{ route('language.store') }}" enctype="multipart/form-data" data-parsley-validate class="create-form" data-success-function="languageSaved" data-drawer-reload>
                @csrf
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">{{ __('Add Language') }}</div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 form-group mandatory">
                                    <label for="name" class="form-label">{{ __('Language Name') }}</label>
                                    <input type="text" name="name" id="name" class="form-control" placeholder="{{ __('Language Name') }}" data-parsley-required="true">
                                </div>

                                <div class="col-md-6 form-group mandatory">
                                    <label for="name_in_english" class="form-label">{{ __('Language Name') . ' (' . __('in English') . ')' }}</label>
                                    <input type="text" name="name_in_english" id="name_in_english" class="form-control" placeholder="{{ __('Language Name') . ' (' . __('in English') . ')' }}" data-parsley-required="true">
                                </div>

                                <div class="col-md-6 form-group mandatory">
                                    <label for="code" class="form-label">{{ __('Language Code') }}</label>
                                    <input type="text" name="code" id="code" class="form-control" placeholder="{{ __('Language Code') }}" data-parsley-required="true">
                                </div>

                                <div class="col-md-6 form-group mandatory">
                                    <label for="country_code" class="form-label">{{ __('Country ISO Code') }}</label>
                                    <input type="text" name="country_code" id="country_code" class="form-control" placeholder="{{ __('Country ISO Code') }}" data-parsley-required="true">
                                    <small class="form-text text-muted">
                                        {{ __('Provide the two-letter ISO country code for a country. Reference:') }}
                                        <a href="https://countrycode.org" target="_blank">CountryCode.org</a>.
                                    </small>
                                </div>

                                <div class="col-12 form-group mandatory">
                                    <label for="image" class="form-label">{{ __('Image') }}</label>
                                    <input class="filepond" type="file" name="image" id="image">
                                </div>

                                @if ($enLanguage)
                                    <div class="col-12 mt-2">
                                        <div class="divider">
                                            <div class="divider-text">
                                                <h6 class="mb-0">{{ __('Download Current Language Files') }}</h6>
                                            </div>
                                        </div>
                                    </div>
                                    @foreach (['panel' => __('Admin Panel'), 'app' => __('App'), 'web' => __('Web')] as $type => $label)
                                        <div class="col-sm-4 text-center mb-2">
                                            <a class="btn btn-outline-primary btn-sm w-100" href="{{ route('language.download.json', [$enLanguage->id, $type]) }}">
                                                <i class="bi bi-download me-1"></i> {{ $label }}
                                            </a>
                                        </div>
                                    @endforeach
                                @endif

                                <div class="col-lg-4 form-group mt-2">
                                    <label for="panel_file" class="form-label">{{ __('Translated File For Admin Panel') }}</label>
                                    <input type="file" name="panel_file" id="panel_file" class="form-control" accept=".json">
                                </div>

                                <div class="col-lg-4 form-group mt-2">
                                    <label for="app_file" class="form-label">{{ __('Translated File For App') }}</label>
                                    <input type="file" name="app_file" id="app_file" class="form-control" accept=".json">
                                </div>

                                <div class="col-lg-4 form-group mt-2">
                                    <label for="web_file" class="form-label">{{ __('Translated File For Web') }}</label>
                                    <input type="file" name="web_file" id="web_file" class="form-control" accept=".json">
                                </div>

                                <div class="col-12 form-group">
                                    <div class="form-check form-switch">
                                        <input type="checkbox" name="rtl" value="1" class="form-check-input" id="rtl">
                                        <label class="form-check-label" for="rtl">{{ __('RTL') }}</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                    </div>
                </div>
            </form>
        </div>
    </section>
@endsection

@section('script')
    <script>
        // Full-page submit (common.js): back to the list, which picks up the new language.
        function languageSaved() {
            setTimeout(function () {
                window.location.href = "{{ route('settings.language.index') }}";
            }, 500);
        }
    </script>
@endsection
