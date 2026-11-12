@extends('layouts.main')

@section('title')
    {{ __('Create Feature Section') }}
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
            <a class="btn btn-primary" href="{{ route('feature-section.index') }}">
                < {{ __('Back to Feature Sections') }} </a>
        </div>
        <div class="card">
            <div class="card-body">
                <form action="{{ route('feature-section.store') }}" id="feature-section-form" class="create-form category-form feature-section-form" method="POST" enctype="multipart/form-data" data-parsley-validate>
                    @csrf
                    @include('feature_section.form', ['translations' => []])
                </form>
            </div>
        </div>
    </section>
@endsection

@section('script')
    @include('feature_section.form-script')
@endsection
