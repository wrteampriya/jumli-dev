@extends('layouts.main')
@section('title')
    {{ __('Edit Category') }}
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
            <a class="btn btn-primary" href="{{ route('category.index') }}">
                < {{ __('Back to All Categories') }} </a>
        </div>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">{{ __('Edit Category') }}</div>
                    <div class="card-body mt-2">
                        @include('category.partials.form', ['category' => $category_data])
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
