@extends('layouts.main')

@section('title')
    {{ __('Seo-settings') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h4>@yield('title')</h4>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first d-flex justify-content-end">
                <a data-side-drawer class="btn btn-primary" href="{{ route('seo-setting.create') }}">+
                    {{ __('Add Seo Setting') }}</a>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-12">
                                @php
                                    $cols = [
                                        ['field' => 'id', 'title' => __('ID'), 'sortable' => true],
                                        ['field' => 'page', 'title' => __('Page'), 'sortable' => false],
                                        ['field' => 'title', 'title' => __('Title'), 'sortable' => false],
                                        ['field' => 'description', 'title' => __('Description'), 'sortable' => true],
                                        ['field' => 'keywords', 'title' => __('Keywords'), 'sortable' => true],
                                        ['field' => 'schema', 'title' => __('Schema'), 'sortable' => true],
                                        [
                                            'field' => 'image',
                                            'title' => __('Image'),
                                            'sortable' => false,
                                            'formatter' => 'imageFormatter',
                                        ],
                                        [
                                            'field' => 'operate',
                                            'title' => __('Action'),
                                            'escape' => false,
                                            'sortable' => false,
                                        ],
                                    ];
                                @endphp
                                <x-data-table id="table_list" :url="route('seo-setting.show', 1)" click-to-select fixed-columns
                                    :extra="['data-query-params' => 'queryParams']" :columns="$cols" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </section>
@endsection
