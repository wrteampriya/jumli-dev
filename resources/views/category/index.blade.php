@extends('layouts.main')
@section('title')
    {{__("Categories")}}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row align-items-center">
            <div class="col-12 col-md-6">
                <h4 class="mb-0">@yield('title')</h4>
                <p class="text-muted mb-0">{{ __('Manage categories and subcategories for active modules.') }}</p>
            </div>
            <div class="col-12 col-md-6 d-flex justify-content-end">
                @if (!empty($category))
                    <a class="btn btn-primary me-2" href="{{ route('category.index') }}">< {{__("Back to All Categories")}} </a>
                    @can('category-create')
                        <a class="btn btn-primary me-2" href="{{ route('category.create', ['id' => $category->id]) }}" data-side-drawer data-drawer-title="{{ __('Add Subcategory') }}" data-drawer-subtitle="{{ __('Fill in the details and save your changes.') }}">+ {{__("Add Subcategory")}} - /{{ $category->name }} </a>
                    @endcanany
                @else
                    <div class="d-flex flex-wrap gap-2">
                        @can('category-create')
                            <a class="btn btn-primary" href="{{ route('category.create') }}" data-side-drawer data-drawer-title="{{ __('Add Category') }}" data-drawer-subtitle="{{ __('Fill in the details and save your changes.') }}">+ {{__("Add Category")}} </a>
                            <a href="{{ route('category.bulk-upload') }}" class="btn btn-light border">
                                <i class="ph-bold ph-upload-simple"></i> <span class="d-none d-sm-inline">{{__("Bulk Upload")}}</span><span class="d-sm-none">{{__("Upload")}}</span>
                            </a>
                        @endcan
                        @can('category-update')
                            <a href="{{ route('category.bulk-update') }}" class="btn btn-light border">
                                <i class="ph-bold ph-note-pencil"></i> <span class="d-none d-sm-inline">{{__("Bulk Update")}}</span><span class="d-sm-none">{{__("Update")}}</span>
                            </a>
                        @endcan
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection

@section('content')
    @php
        // Module icon: the uploaded icon, else a generic one.
        $moduleIcon = fn ($module) => $module?->icon
            ? '<img src="'.e($module->icon).'" alt="" data-no-auto-error>'
            : '<i class="ph-fill ph-squares-four"></i>';
        $statLine = fn ($stat) => __(':categories Categories', ['categories' => $stat['categories']]).' · '
            .__(':subcategories Subcategories', ['subcategories' => $stat['subcategories']]).' · '
            .__(':listings Active Listings', ['listings' => number_format($stat['listings'])]);
    @endphp
    <section class="section category-index-page">
        @if ($modules->count() > 1)
            {{-- Module tabs: All Modules overview or one module's table (?module_id=). --}}
            <nav class="cat-module-tabs" role="tablist">
                <a href="{{ route('category.index') }}" class="cat-module-tab {{ $selectedModule ? '' : 'active' }}"><i class="ph-fill ph-stack"></i> {{ __('All Modules') }}</a>
                @foreach ($modules as $module)
                    <a href="{{ route('category.index', ['module_id' => $module->id]) }}" class="cat-module-tab {{ $selectedModule?->id === $module->id ? 'active' : '' }}">
                        <span class="cat-module-tab-icon">{!! $moduleIcon($module) !!}</span> {{ $module->name }}
                    </a>
                @endforeach
            </nav>
        @endif

        @if (! $selectedModule)
            {{-- ALL MODULES: one card per module with its first categories. --}}
            @foreach ($overview as $group)
                <div class="card cat-overview-card">
                    <a class="cat-module-head" href="{{ route('category.index', ['module_id' => $group['module']->id]) }}">
                        <span class="cat-module-icon">{!! $moduleIcon($group['module']) !!}</span>
                        <span class="flex-grow-1">
                            <strong class="d-block">{{ $group['module']->name }}</strong>
                            <small class="text-muted cat-stat-categories">{{ $statLine($group['stats']) }}</small>
                        </span>
                        <span class="badge cat-count-badge">{{ __(':count Categories', ['count' => $group['stats']['categories']]) }}</span>
                        <i class="ph-bold ph-caret-right cat-chevron"></i>
                    </a>
                    @forelse ($group['categories'] as $category)
                        <div class="cat-overview-row">
                            <span class="cat-overview-name">{{ $category->name }}</span>
                            <a href="{{ url('category/'.$category->id.'/custom-fields') }}" class="cat-overview-fields"><i class="ph-bold ph-list-bullets"></i> {{ __(':count Custom Fields', ['count' => $category->custom_fields_count]) }}</a>
                            <span class="cat-overview-ads">{{ __(':count Ads', ['count' => number_format($category->all_items_count)]) }}</span>
                        </div>
                    @empty
                        <div class="cat-overview-row text-muted">{{ __('No categories yet.') }}</div>
                    @endforelse
                    @if ($group['stats']['categories'] > 0)
                        <a class="cat-overview-more" href="{{ route('category.index', ['module_id' => $group['module']->id]) }}">
                            {{ __('View all :count categories', ['count' => $group['stats']['categories']]) }} <i class="ph-bold ph-arrow-right"></i>
                        </a>
                    @endif
                </div>
            @endforeach
        @else
            {{-- ONE MODULE: header with stats + the category table (module sent by moduleQueryParams). --}}
            <div class="card cat-table-card">
                <div class="cat-module-head">
                    <span class="cat-module-icon">{!! $moduleIcon($selectedModule) !!}</span>
                    <span class="flex-grow-1">
                        <strong class="d-block">{{ $selectedModule->name }}</strong>
                        <small class="text-muted cat-stat-categories">{{ $statLine($stats[$selectedModule->id] ?? ['categories' => 0, 'subcategories' => 0, 'listings' => 0]) }}</small>
                    </span>
                    <a href="{{ route('category.order') }}" class="btn btn-light border btn-sm"><i class="ph-bold ph-arrows-down-up"></i> {{ __('Set Order of Categories') }}</a>
                </div>
                <div class="card-body">
                    <input type="hidden" id="filter_module_id" value="{{ $modules->count() > 1 ? $selectedModule->id : '' }}">
                    @php
                        $cols = [
                            // Hidden by default (column picker).
                            ['field'=>'id','title'=>__('ID'),'align'=>'center','sortable'=>true,'visible'=>false],
                            ['field'=>'name','title'=>__('Category'),'sortable'=>true,'formatter'=>'categoryTreeNameFormatter','escape'=>false],
                            ['field'=>'image','title'=>__('Image'),'align'=>'center','formatter'=>'imageFormatter'],
                            ['field'=>'subcategories_count','title'=>__('Subcategories'),'sortable'=>false,'formatter'=>'categoryCountFormatter'],
                            ['field'=>'custom_fields_count','title'=>__('Fields'),'sortable'=>false,'formatter'=>'categoryFieldsFormatter','escape'=>false],
                            ['field'=>'advertisements_count','title'=>__('Listings'),'sortable'=>true,'formatter'=>'categoryCountFormatter'],
                        ];
                        if(auth()->user()->can('category-update')) {
                            $cols[] = ['field'=>'status','title'=>__('Status'),'sortable'=>true,'formatter'=>'statusSwitchFormatter'];
                        }
                        if(auth()->user()->canany(['category-update','category-delete'])) {
                            $cols[] = ['field'=>'operate','title'=>__('Actions'),'escape'=>false,'sortable'=>false];
                        }
                    @endphp
                    <x-data-table
                        id="table_list"
                        :url="route('category.show', 0)"
                        click-to-select
                        sort-name="sequence"
                        sort-order="asc"
                        table-name="categories"
                        :mobile-responsive="false"
                        show-export
                        export-file-name="category-list"
                        :extra="['data-search-align'=>'right','data-query-params'=>'moduleQueryParams','data-use-row-attr-func'=>'true']"
                        :columns="$cols"
                    />
                </div>
            </div>
        @endif
    </section>
@endsection
