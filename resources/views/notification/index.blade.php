@extends('layouts.main')

@section('title')
    {{ __('Send Notification') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h4>@yield('title')</h4>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first d-flex justify-content-end">
                @can('notification-create')
                    <a data-side-drawer class="btn btn-primary" href="{{ route('notification.create') }}">+ {{ __('Send Notification') }}</a>
                @endcan
            </div>
        </div>
    </div>
@endsection

@section('content')
    <div class="row">
        <section class="section">

            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-12">
                            <div id="toolbar">
                                @can('notification-delete')
                                    <a href="{{route('notification.batch.delete')}}" class="btn btn-danger btn-sm btn-icon text-white" id="delete_multiple" title="Delete Notification"><em class='fa fa-trash'></em></a>
                                @endcan
                            </div>
                            @php
                                $notifCols = [];
                                if(auth()->user()->can('notification-delete')) {
                                    $notifCols[] = ['field'=>'state','title'=>'','checkbox'=>true];
                                }
                                $notifCols[] = ['field'=>'id','title'=>__('ID'),'sortable'=>true];
                                $notifCols[] = ['field'=>'title','title'=>__('Title'),'sortable'=>true];
                                $notifCols[] = ['field'=>'message','title'=>__('Message'),'sortable'=>true];
                                $notifCols[] = ['field'=>'image','title'=>__('Image'),'formatter'=>'imageFormatter'];
                                $notifCols[] = ['field'=>'send_to','title'=>__('Send To'),'sortable'=>true];
                                if(auth()->user()->can('notification-delete')) {
                                    $notifCols[] = ['field'=>'operate','title'=>__('Action'),'escape'=>false];
                                }
                            @endphp
                            <x-data-table
                                id="table_list"
                                :url="route('notification.show',1)"
                                toolbar-id="toolbar"
                                click-to-select
                                fixed-columns
                                show-export
                                export-file-name="notification-list"
                                :columns="$notifCols"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
@endsection
