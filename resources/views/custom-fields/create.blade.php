@extends('layouts.main')

@section('title')
    {{ __('Add Custom Field') }}
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
        <div class="card">
            <div class="card-header">{{ __('Add Custom Field') }}</div>
            <div class="card-body mt-2">
                @include('custom-fields.partials.form')
            </div>
        </div>
    </section>
@endsection
