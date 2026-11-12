@extends('layouts.main')

@section('title')
    {{ __('Languages') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h4>@yield('title')</h4>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first d-flex justify-content-end">
                <a data-side-drawer class="btn btn-primary" href="{{ route('language.create') }}">
                    <i class="bi bi-plus-lg me-1"></i> {{ __('Add New Language') }}
                </a>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-12">
                                @php
                                    $cols = [
                                        ['field'=>'id','title'=>__('ID'),'sortable'=>true],
                                        ['field'=>'name','title'=>__('Name'),'sortable'=>false],
                                        ['field'=>'name_in_english','title'=>__('Name') . ' (' . __('in English') . ')','sortable'=>false],
                                        ['field'=>'code','title'=>__('Language Code'),'sortable'=>true],
                                        ['field'=>'country_code','title'=>__('Country Code'),'sortable'=>true],
                                        ['field'=>'image','title'=>__('Image'),'sortable'=>false,'formatter'=>'imageFormatter'],
                                        ['field'=>'status','title'=>__('Active'),'sortable'=>true,'formatter'=>'statusSwitchFormatter'],
                                        ['field'=>'operate','title'=>__('Action'),'escape'=>false,'sortable'=>false,'events'=>'languageEvents'],
                                    ];
                                @endphp
                                <x-data-table
                                    id="table_list"
                                    :url="route('language.show', 1)"
                                    table-name="languages"
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

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.addEventListener('change', function (e) {
                if (e.target.matches('#table_list .update-status')) {
                    setTimeout(function () {
                        window.location.reload();
                    }, 500);
                }
            });
        });
    </script>
@endsection
