@extends('layouts.main')

@section('title')
    {{ __('Add Seo Setting') }}
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
            <a class="btn btn-primary" href="{{ route('settings.seo-settings.index') }}">
                < {{ __('Back to Seo Settings') }} </a>
        </div>
        <div class="row">
            <form action="{{ route('seo-setting.store') }}" method="POST" enctype="multipart/form-data" data-parsley-validate class="create-form">
                @csrf
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">{{ __('Add Seo Setting') }}</div>
                        <div class="card-body">
                            {{-- English-only fields --}}
                            <div class="form-group mandatory">
                                <label for="page" class="form-label">{{ __('Page') }}</label>
                                <select class="form-control" name="page" id="page" required>
                                    <option value="">{{ __('Select Page') }}</option>
                                    @foreach (\App\Models\SeoSetting::PAGES as $value => $label)
                                        <option value="{{ $value }}">{{ __($label) }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="image" class="form-label">{{ __('Image') }}</label>
                                <input class="filepond" type="file" name="image" id="image">
                            </div>

                            {{-- Language Tabs --}}
                            <ul class="nav nav-tabs mt-3" role="tablist">
                                @foreach ($languages as $lang)
                                    <li class="nav-item" role="presentation">
                                        <a class="nav-link {{ $loop->first ? 'active' : '' }}" data-bs-toggle="tab" href="#lang-{{ $lang->id }}" role="tab">
                                            {{ $lang->name }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>

                            <div class="tab-content mt-3">
                                @foreach ($languages as $lang)
                                    <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="lang-{{ $lang->id }}" role="tabpanel">
                                        <input type="hidden" name="languages[]" value="{{ $lang->id }}">

                                        <div class="form-group">
                                            <label>{{ __('Title') }} ({{ $lang->name }})</label>
                                            <input type="text" name="title[{{ $lang->id }}]" class="form-control" placeholder="{{ __('Enter Title') }}">
                                        </div>

                                        <div class="form-group">
                                            <label>{{ __('Description') }} ({{ $lang->name }})</label>
                                            <textarea name="description[{{ $lang->id }}]" class="form-control" rows="3" placeholder="{{ __('Enter Description') }}"></textarea>
                                        </div>

                                        <div class="form-group">
                                            <label>{{ __('Keywords') }} ({{ $lang->name }})</label>
                                            <textarea name="keywords[{{ $lang->id }}]" class="form-control" rows="2" placeholder="{{ __('Enter Keywords') }}"></textarea>
                                        </div>

                                        <div class="form-group">
                                            <label>{{ __('Schema') }} ({{ $lang->name }})</label>
                                            <textarea name="schema[{{ $lang->id }}]" class="form-control" rows="4" placeholder="{{ __('Enter Schema') }}"></textarea>
                                        </div>
                                    </div>
                                @endforeach
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
