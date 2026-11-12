@extends('layouts.main')

@section('title')
    {{ __("Blog Category") }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row align-items-center">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h4 class="mb-0">@yield('title')</h4>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first d-flex justify-content-end">
                @can('blog-create')
                    <a data-side-drawer class="btn btn-primary" href="{{ route('blog-category.create') }}">+ {{__("Add Blog Category")}}</a>
                @endcan
            </div>
        </div>
    </div>
@endsection

@section('content')
    <div class="row">
        <section class="section">
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-12">
                                    {{-- Module filter / column only once there is more than one module. --}}
                                    @if ($modules->count() > 1)
                                        <div class="row mb-2">
                                            <div class="col-12 col-md-4">
                                                <label for="filter_module_id" class="form-label">{{ __('Module') }}</label>
                                                <select id="filter_module_id" class="form-control">
                                                    <option value="">{{ __('All') }}</option>
                                                    @foreach ($modules as $module)
                                                        <option value="{{ $module->id }}">{{ $module->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                    @endif
                                    @php
                                        $cols = [
                                            ['field'=>'id','title'=>__('ID'),'sortable'=>true],
                                            ['field'=>'name','title'=>__('Name'),'sortable'=>true],
                                            ['field'=>'slug','title'=>__('Slug'),'sortable'=>true],
                                        ];
                                        if ($modules->count() > 1) {
                                            $cols[] = ['field'=>'module_name','title'=>__('Module'),'sortable'=>false];
                                        }
                                        if(auth()->user()->can('blog-update')) {
                                            $cols[] = ['field'=>'is_active','title'=>__('Active'),'width'=>100,'sortable'=>false,'formatter'=>'statusSwitchFormatter'];
                                        }
                                        if(auth()->user()->canany(['blog-update','blog-delete'])) {
                                            $cols[] = ['field'=>'operate','title'=>__('Action'),'width'=>100,'escape'=>false];
                                        }
                                    @endphp
                                    <x-data-table
                                        id="table_list"
                                        :url="route('blog-category.show', 1)"
                                        click-to-select
                                        fixed-columns
                                        show-export
                                        export-file-name="blog-categories-list"
                                        :extra="['data-query-params'=>'blogCategoryQueryParams', 'data-table'=>'blog_categories', 'data-status-column'=>'is_active']"
                                        :columns="$cols"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
@endsection

@section('script')
    <script>
        function blogCategoryQueryParams(p) {
            return {
                ...p,
                module_id: $('#filter_module_id').val() || '',
            };
        }

        $('#filter_module_id').on('change', function () {
            $('#table_list').bootstrapTable('refresh');
        });
    </script>
@endsection
