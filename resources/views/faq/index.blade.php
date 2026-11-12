@extends('layouts.main')
@section('title')
    {{__("FAQ")}}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h4>@yield('title')</h4>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first d-flex justify-content-end">
                @can('faq-create')
                    <a data-side-drawer class="btn btn-primary" href="{{ route('faq.create') }}">+ {{ __('Add FAQ') }}</a>
                @endcan
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
                                        ['field'=>'id','title'=>__('ID'),'sortable'=>true],
                                        ['field'=>'question','title'=>__('Questions'),'sortable'=>false],
                                        ['field'=>'answer','title'=>__('Answers'),'sortable'=>false,'formatter'=>'descriptionFormatter'],
                                        ['field'=>'operate','title'=>__('Action'),'sortable'=>false,'escape'=>false],
                                    ];
                                @endphp
                                <x-data-table
                                    id="table_list"
                                    :url="route('faq.show',1)"
                                    click-to-select
                                    fixed-columns
                                    :extra="['data-query-params'=>'queryParams']"
                                    :columns="$cols"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
