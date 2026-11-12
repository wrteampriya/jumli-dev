{{--
    Create / edit page (side drawer) for one package type. Extended by:
      - packages/ad/{create,edit}.blade.php
      - packages/featured/{create,edit}.blade.php
    Data from Packages\AdPackageController / FeaturedPackageController ::create()/edit(). Extending view passes:
    $title, $limitsTitle, $showVideoSection.
--}}
@extends('layouts.main')

@section('title')
    {{ $title }}
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
        @php $isEdit = isset($package); @endphp
        <form action="{{ $isEdit ? route($routePrefix.'.update', $package->id) : route($routePrefix.'.store') }}" method="POST"
              class="{{ $isEdit ? 'edit-form' : 'create-form' }} pkg-package-form" data-parsley-validate enctype="multipart/form-data"
              data-success-function="afterPackageSave">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            @include('packages.partials.form')

            <div class="text-end mt-3">
                <button type="submit" class="btn btn-primary">{{ __('Save Changes') }}</button>
            </div>
        </form>
    </section>
@endsection

@section('js')
    @include('packages.partials.form-script')
@endsection
