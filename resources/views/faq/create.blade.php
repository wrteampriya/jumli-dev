@extends('layouts.main')
@section('title')
    {{ __('Add FAQ') }}
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
            <a class="btn btn-primary" href="{{ route('faq.index') }}">
                < {{ __('Back to FAQ') }} </a>
        </div>
        <div class="row">
            <form class="create-form" action="{{ route('faq.store') }}" method="POST" data-parsley-validate enctype="multipart/form-data">
                @csrf
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">{{ __('Add FAQ') }}</div>

                        <div class="card-body mt-3">
                            <ul class="nav nav-tabs" role="tablist">
                                @foreach ($languages as $index => $language)
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link @if ($index === 0) active @endif" data-bs-toggle="tab"
                                                data-bs-target="#lang-{{ $language->id }}" type="button" role="tab">
                                            {{ $language->name }}
                                        </button>
                                    </li>
                                @endforeach
                            </ul>

                            <div class="tab-content mt-3">
                                @foreach ($languages as $index => $language)
                                    <div class="tab-pane fade @if ($index === 0) show active @endif"
                                         id="lang-{{ $language->id }}" role="tabpanel">
                                        <div class="form-group">
                                            <label>{{ __('Question') }} ({{ $language->name }}) <span class="text-danger">*</span></label>
                                            <input type="text" name="question[{{ $language->id }}]" class="form-control"
                                                {{ $language->code === 'en' ? 'required' : '' }}>
                                        </div>
                                        <div class="form-group mt-2">
                                            <label>{{ __('Answer') }} ({{ $language->name }}) <span class="text-danger">*</span></label>
                                            <textarea name="answer[{{ $language->id }}]" class="form-control" rows="4"
                                                {{ $language->code === 'en' ? 'required' : '' }}></textarea>
                                        </div>
                                    </div>
                                @endforeach
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
