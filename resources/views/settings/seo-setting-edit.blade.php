@extends('layouts.main')

@section('title')
    {{ __('Edit Seo Setting') }}
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
            <form action="{{ route('seo-setting.update', $seoSetting->id) }}" method="POST" enctype="multipart/form-data" data-parsley-validate class="edit-form">
                @csrf
                @method('PUT')
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">{{ __('Edit Seo Setting') }}</div>
                        <div class="card-body">
                            <div class="form-group mandatory">
                                <label for="edit_page" class="form-label">{{ __('Page') }}</label>
                                <input type="text" id="edit_page" class="form-control" value="{{ $seoSetting->page }}" disabled>
                            </div>

                            <div class="form-group">
                                <label for="edit_image" class="form-label">{{ __('Image') }}</label>
                                <input class="filepond" type="file" name="image" id="edit_image">
                                @if ($seoSetting->image)
                                    <div class="mt-2">
                                        <img src="{{ $seoSetting->image }}" class="img-thumbnail" style="max-height: 150px;" alt="{{ $seoSetting->page }}">
                                    </div>
                                    {{-- A new image above replaces the current one either way. --}}
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" name="remove_image" value="1" id="remove_image">
                                        <label class="form-check-label" for="remove_image">{{ __('Remove current image') }}</label>
                                    </div>
                                @endif
                            </div>

                            <ul class="nav nav-tabs mt-3" role="tablist">
                                @foreach ($languages as $lang)
                                    <li class="nav-item" role="presentation">
                                        <a class="nav-link {{ $loop->first ? 'active' : '' }}" data-bs-toggle="tab" href="#edit-lang-{{ $lang->id }}" role="tab">
                                            {{ $lang->name }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>

                            <div class="tab-content mt-3">
                                @foreach ($languages as $lang)
                                    <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="edit-lang-{{ $lang->id }}" role="tabpanel">
                                        <input type="hidden" name="languages[]" value="{{ $lang->id }}">

                                        <div class="form-group">
                                            <label>{{ __('Title') }} ({{ $lang->name }})</label>
                                            <input type="text" name="title[{{ $lang->id }}]" class="form-control" placeholder="{{ __('Enter Title') }}"
                                                   value="{{ $translations[$lang->id]['title'] ?? '' }}">
                                        </div>

                                        <div class="form-group">
                                            <label>{{ __('Description') }} ({{ $lang->name }})</label>
                                            <textarea name="description[{{ $lang->id }}]" class="form-control" rows="3" placeholder="{{ __('Enter Description') }}">{{ $translations[$lang->id]['description'] ?? '' }}</textarea>
                                        </div>

                                        <div class="form-group">
                                            <label>{{ __('Keywords') }} ({{ $lang->name }})</label>
                                            <textarea name="keywords[{{ $lang->id }}]" class="form-control" rows="2" placeholder="{{ __('Enter Keywords') }}">{{ $translations[$lang->id]['keywords'] ?? '' }}</textarea>
                                        </div>

                                        <div class="form-group">
                                            <label>{{ __('Schema') }} ({{ $lang->name }})</label>
                                            <textarea name="schema[{{ $lang->id }}]" class="form-control" rows="4" placeholder="{{ __('Enter Schema') }}">{{ $translations[$lang->id]['schema'] ?? '' }}</textarea>
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
