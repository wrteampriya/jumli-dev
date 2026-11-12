@extends('layouts.main')

@section('title')
    {{ __('Add Report Reason') }}
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
            <a class="btn btn-primary" href="{{ route('report-reasons.index') }}">
                < {{ __('Back to Report Reasons') }} </a>
        </div>
        <div class="row">
            <form action="{{ route('report-reasons.store') }}" class="needs-validation create-form" method="post" data-parsley-validate enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="type" value="0">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">{{ __('Add Report Reason') }}</div>
                        <div class="card-body">
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
                                            <label>{{ __('Reason') }} ({{ $language->name }})</label>
                                            <textarea name="reason[{{ $language->id }}]" class="form-control"
                                                      rows="3" {{ $language->code === 'en' ? 'required' : '' }}></textarea>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="col-md-12 d-flex justify-content-end">
                        <button class="btn btn-primary" type="submit" name="submit">{{ __('Submit') }}</button>
                    </div>
                </div>
            </form>
        </div>
    </section>
@endsection
