@extends('layouts.main')

@section('title')
    {{ __('Edit Language') }}
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
    @php
        // The default English language is the base template: only its name and image can change.
        $isDefaultLang = $language->code === 'en';
    @endphp
    <section class="section">
        <div class="buttons">
            <a class="btn btn-primary" href="{{ route('settings.language.index') }}">
                < {{ __('Back to Languages') }} </a>
        </div>
        <div class="row">
            {{-- Name, code and flag show in the language switcher, so reload after saving. --}}
            <form method="POST" action="{{ route('language.update', $language->id) }}" enctype="multipart/form-data" data-parsley-validate class="edit-form" data-success-function="languageSaved" data-drawer-reload>
                @csrf
                @method('PUT')
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">{{ __('Edit Language') }}</div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 form-group mandatory">
                                    <label for="edit_name" class="form-label">{{ __('Language Name') }}</label>
                                    <input type="text" name="name" id="edit_name" class="form-control" value="{{ $language->name }}" placeholder="{{ __('Name') }}" data-parsley-required="true">
                                </div>

                                @unless ($isDefaultLang)
                                    <div class="col-md-6 form-group mandatory">
                                        <label for="edit_name_in_english" class="form-label">{{ __('Language Name') }} ({{ __('in English') }})</label>
                                        <input type="text" name="name_in_english" id="edit_name_in_english" class="form-control" value="{{ $language->name_in_english }}" placeholder="{{ __('Name') }}" data-parsley-required="true">
                                    </div>

                                    <div class="col-md-6 form-group mandatory">
                                        <label for="edit_code" class="form-label">{{ __('Language Code') }}</label>
                                        <input type="text" name="code" id="edit_code" class="form-control" value="{{ $language->code }}" placeholder="{{ __('Language Code') }}" data-parsley-required="true">
                                    </div>

                                    <div class="col-md-6 form-group mandatory">
                                        <label for="edit_country_code" class="form-label">{{ __('Country Code') }}</label>
                                        <input type="text" name="country_code" id="edit_country_code" class="form-control" value="{{ $language->country_code }}" placeholder="{{ __('Country Code') }}" data-parsley-required="true">
                                    </div>
                                @endunless

                                <div class="col-12 form-group">
                                    <label for="edit_image" class="form-label">{{ __('Image') }}</label>
                                    <input class="filepond" type="file" name="image" id="edit_image">
                                    @if ($language->image)
                                        <div class="mt-2">
                                            <img src="{{ $language->image }}" class="img-thumbnail" style="max-height: 80px;" alt="{{ $language->name }}">
                                        </div>
                                    @endif
                                </div>

                                @unless ($isDefaultLang)
                                    @foreach (['panel' => __('File For Admin Panel'), 'app' => __('File For App'), 'web' => __('File For Web')] as $type => $label)
                                        <div class="col-lg-4 form-group">
                                            <label for="edit_{{ $type }}_file" class="form-label">{{ $label }}</label>
                                            <input type="file" name="{{ $type }}_file" id="edit_{{ $type }}_file" class="form-control" accept=".json">
                                            <a href="{{ route('language.download.json', [$language->id, $type]) }}" target="_blank" class="btn btn-sm btn-outline-primary mt-2">
                                                <i class="bi bi-download"></i> {{ __('Download Current') }}
                                            </a>
                                        </div>
                                    @endforeach

                                    <div class="col-12 form-group">
                                        <div class="form-check form-switch">
                                            <input type="hidden" name="rtl" id="edit_rtl" value="{{ $language->rtl ? 1 : 0 }}">
                                            <input type="checkbox" class="form-check-input status-switch" id="edit_rtl_switch" {{ $language->rtl ? 'checked' : '' }}>
                                            <label class="form-check-label" for="edit_rtl_switch">{{ __('RTL') }}</label>
                                        </div>
                                    </div>
                                @endunless
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
        // Full-page submit (common.js): back to the list, which picks up the change.
        function languageSaved() {
            setTimeout(function () {
                window.location.href = "{{ route('settings.language.index') }}";
            }, 500);
        }
    </script>
@endsection
