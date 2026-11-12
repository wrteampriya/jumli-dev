{{--
    Shared list page for one package type. Extended by:
      - packages/ad/index.blade.php       (Ad Packages, routes ad-packages.*)
      - packages/featured/index.blade.php (Featured Packages, routes featured-packages.*)
    Data from Packages\AdPackageController / FeaturedPackageController ::index(): $routePrefix, $permissionPrefix,
    $supportsCategoryScope, $modules, $canCreateForAnyModule.
    Extending view passes: $title, $createLabel, $exportName, $showVideoColumn.
--}}
@extends('layouts.main')

@section('title')
    {{ $title }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row d-flex align-items-center">
            <div class="col-12 col-md-6">
                <h4 class="mb-0">@yield('title')</h4>
            </div>
            <div class="col-12 col-md-6 text-end">
                @can($permissionPrefix.'-create')
                    @if ($canCreateForAnyModule)
                        <a data-side-drawer class="btn btn-primary me-2" href="{{ route($routePrefix.'.create') }}">{{ $createLabel }}</a>
                    @else
                        {{-- Every module has this package kind switched off in Modules → edit. --}}
                        <span class="d-inline-block" tabindex="0" data-bs-toggle="tooltip"
                              title="{{ __('Enable this package type for at least one module (Modules → Edit) to create packages.') }}">
                            <button type="button" class="btn btn-primary me-2" disabled>{{ $createLabel }}</button>
                        </span>
                    @endif
                @endcan
            </div>
        </div>
    </div>
@endsection

@section('js')
@can('user-package-list')
<script>
var currentSubscriberPackageId = null;

function subscribersQueryParams(p) {
    return $.extend({}, p, { package_id: currentSubscriberPackageId, status: 'active' });
}

function loadSubscribers(el) {
    currentSubscriberPackageId = $(el).data('package-id');
    $('#subscribersModalLabel').text('{{ __('Active Subscribers') }} — ' + $(el).data('package-name'));
    $('#subscribers_table').bootstrapTable('refresh', { silent: false });
}

$('#subscribersModal').on('hidden.bs.modal', function () {
    currentSubscriberPackageId = null;
    $('#subscribers_table').bootstrapTable('load', { total: 0, rows: [] });
});
</script>
@endcan

<script>
var currentPackageView = 'active'; // 'all' | 'active' | 'inactive' | 'discontinued'

function packageQueryParams(p) {
    var params = $.extend({}, p, { module_id: moduleFilterValue() });
    params.status = null;
    if (currentPackageView === 'active') {
        params.is_discontinued = 0;
    } else if (currentPackageView === 'discontinued') {
        params.is_discontinued = 1;
    } else if (currentPackageView === 'inactive') {
        params.is_discontinued = 0;
        params.status = 0;
    }
    return params;
}

$(function () {
    var views = {all: '#btn-all-packages', active: '#btn-active-packages', inactive: '#btn-inactive-packages', discontinued: '#btn-discontinued-packages'};

    function applyColumnVisibility(view) {
        var $table = $('#table_list');
        var showDiscontinued = view === 'discontinued' || view === 'all';
        var showRevenue = view !== 'discontinued';
        $table.bootstrapTable(showDiscontinued ? 'showColumn' : 'hideColumn', 'discontinued_at');
        $table.bootstrapTable(showDiscontinued ? 'showColumn' : 'hideColumn', 'active_subscribers');
        $table.bootstrapTable(showRevenue ? 'showColumn' : 'hideColumn', 'total_revenue');
        $table.bootstrapTable(showRevenue ? 'showColumn' : 'hideColumn', 'total_subscribers');
    }

    function showView(view) {
        currentPackageView = view;
        $(Object.values(views).join(',')).removeClass('active');
        $(views[view]).addClass('active');
        applyColumnVisibility(view);
    }

    showView('active');
    $.each(views, function (view, button) {
        $(button).on('click', function () {
            showView(view);
            $('#table_list').bootstrapTable('refresh');
        });
    });
});

function packageStatusFormatter(value, row) {
    if (value == 1) {
        return '<span class="badge bg-danger">{{ __('Discontinued') }}</span>';
    }
    if (row.status == 0) {
        return '<span class="badge bg-warning">{{ __('Inactive') }}</span>';
    }
    return '<span class="badge bg-success">{{ __('Active') }}</span>';
}

function activeSubscribersBadgeFormatter(value) {
    if (value > 0) {
        return '<span class="badge bg-warning text-dark">' + value + ' {{ __('active') }}</span>';
    }
    return '<span class="badge bg-secondary">{{ __('None') }}</span>';
}

$(document).on('click', '.discontinue-btn', function () {
    var btn = $(this);
    var subscribers = parseInt(btn.data('subscribers')) || 0;
    var msg = '{{ __("This package will stop appearing for new purchases. Existing subscribers will keep access until their subscription expires. This action cannot be undone.") }}';
    if (subscribers > 0) {
        msg += ' <strong>{{ __("There are currently") }} ' + subscribers + ' {{ __("active subscriber(s).") }}</strong>';
    }
    $('#discontinuePackageName').text(btn.data('name'));
    $('#discontinueWarningMsg').html(msg);
    $('#confirmDiscontinueBtn').data('url', btn.data('url'));
    $('#discontinueModal').modal('show');
});

$('#confirmDiscontinueBtn').on('click', function () {
    ajaxRequest('PUT', $(this).data('url'), {}, null, function (response) {
        showSuccessToast(response.message || '{{ __("Package discontinued successfully.") }}');
    }, function (response) {
        showErrorToast(response.message || '{{ __("Something went wrong.") }}');
    }, function () {
        $('#discontinueModal').modal('hide');
        $('#table_list').bootstrapTable('refresh');
    });
});
</script>
@endsection

@section('content')
    @can($permissionPrefix.'-update')
    <div class="modal fade" id="discontinueModal" tabindex="-1" aria-labelledby="discontinueModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="discontinueModalLabel">
                        <i class="ph ph-warning me-1"></i> {{ __('Discontinue Package') }}
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="fw-semibold mb-2">{{ __('You are about to discontinue:') }} <span id="discontinuePackageName" class="text-danger"></span></p>
                    <div class="alert alert-warning" id="discontinueWarningMsg"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="button" class="btn btn-danger" id="confirmDiscontinueBtn">{{ __('Yes, Discontinue') }}</button>
                </div>
            </div>
        </div>
    </div>
    @endcan

    @can('user-package-list')
    <div class="modal fade" id="subscribersModal" tabindex="-1" aria-labelledby="subscribersModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="subscribersModalLabel">{{ __('Active Subscribers') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @php
                        $subCols = [
                            ['field'=>'id','title'=>__('ID'),'align'=>'center','sortable'=>true],
                            ['field'=>'user.name','title'=>__('User Name'),'align'=>'center'],
                            ['field'=>'start_date','title'=>__('Start Date'),'align'=>'center','sortable'=>true],
                            ['field'=>'end_date','title'=>__('End Date'),'align'=>'center','formatter'=>'unlimitedBadgeFormatter','sortable'=>true],
                            ['field'=>'status','title'=>__('Status'),'align'=>'center','formatter'=>'userPackageStatusBadgeFormatter','escape'=>false],
                            ['field'=>'total_limit','title'=>__('Total Limit'),'align'=>'center','formatter'=>'unlimitedBadgeFormatter','sortable'=>true],
                            ['field'=>'used_limit','title'=>__('Used Limit'),'align'=>'center','sortable'=>true],
                        ];
                    @endphp
                    <x-data-table
                        id="subscribers_table"
                        :url="route('package.users.show')"
                        :columns="$subCols"
                        table-name="subscribers"
                        :show-columns="false"
                        :show-refresh="false"
                        :show-export="false"
                        :extra="['data-query-params'=>'subscribersQueryParams']"
                    />
                </div>
            </div>
        </div>
    </div>
    @endcan

    <section class="section">
        <div class="card">
            <div class="card-body">
                <div class="row g-2 mb-3 align-items-center">
                    <div class="col-12 col-md-auto">
                        <button type="button" class="btn btn-outline-primary all w-100" id="btn-all-packages">{{ __('All Packages') }}</button>
                    </div>
                    <div class="col-12 col-md-auto">
                        <button type="button" class="btn btn-outline-success w-100" id="btn-active-packages">{{ __('Active Packages') }}</button>
                    </div>
                    <div class="col-12 col-md-auto">
                        <button type="button" class="btn btn-outline-warning w-100" id="btn-inactive-packages">{{ __('Inactive Packages') }}</button>
                    </div>
                    <div class="col-12 col-md-auto">
                        <button type="button" class="btn btn-outline-danger w-100" id="btn-discontinued-packages">{{ __('Discontinued Packages') }}</button>
                    </div>
                </div>

                <div id="filters" class="row">
                    <x-module-filter :modules="$modules" class="col-12 col-md-6" />
                </div>
                @php
                    $pkgCols = [
                        ['field'=>'id','title'=>__('ID'),'align'=>'center','sortable'=>true],
                        ['field'=>'icon','title'=>__('Image'),'align'=>'center','formatter'=>'imageFormatter'],
                        ['field'=>'name','title'=>__('Name'),'align'=>'center','escape'=>true],
                    ];
                    if ($modules->count() > 1) {
                        $pkgCols[] = ['field'=>'module_name','title'=>__('Module'),'align'=>'center','sortable'=>false];
                    }
                    if ($supportsCategoryScope) {
                        $pkgCols[] = ['field'=>'category_names','title'=>__('Categories'),'align'=>'center','formatter'=>'categoryNamesFormatter'];
                    }
                    array_push($pkgCols,
                        ['field'=>'price','title'=>__('Price'),'align'=>'center','sortable'=>true],
                        ['field'=>'discount_in_percentage','title'=>__('Discount (%)'),'align'=>'center','sortable'=>true],
                        ['field'=>'final_price','title'=>__('Final Price'),'align'=>'center','sortable'=>true],
                        ['field'=>'duration','title'=>__('Package Duration'),'align'=>'center','sortable'=>true],
                        ['field'=>'total_revenue','title'=>__('Total Revenue'),'align'=>'center','sortable'=>true,'visible'=>false],
                        ['field'=>'total_subscribers','title'=>__('Active Subscribers'),'align'=>'center','sortable'=>true,'visible'=>false],
                        ['field'=>'active_subscribers','title'=>__('Remaining Subscribers'),'align'=>'center','sortable'=>true,'visible'=>false,'formatter'=>'activeSubscribersBadgeFormatter','escape'=>false],
                        ['field'=>'discontinued_at','title'=>__('Discontinued On'),'align'=>'center','sortable'=>true,'visible'=>false],
                        ['field'=>'is_discontinued','title'=>__('Package Status'),'align'=>'center','sortable'=>false,'formatter'=>'packageStatusFormatter','escape'=>false],
                    );
                    if ($showVideoColumn) {
                        $pkgCols[] = ['field'=>'is_reel_allowed','title'=>__('Is Video Ads Allowed ?'),'align'=>'center','visible'=>false,'sortable'=>true,'formatter'=>'yesNoFormatter'];
                    }
                    if (auth()->user()->can($permissionPrefix.'-update')) {
                        $pkgCols[] = ['field'=>'status','title'=>__('Status'),'align'=>'center','sortable'=>true,'formatter'=>'statusSwitchFormatter','escape'=>false];
                    }
                    if (auth()->user()->canany([$permissionPrefix.'-update', $permissionPrefix.'-delete', 'user-package-list'])) {
                        $pkgCols[] = ['field'=>'operate','title'=>__('Action'),'align'=>'center','escape'=>false,'sortable'=>false];
                    }
                @endphp
                <x-data-table
                    id="table_list"
                    :url="route($routePrefix.'.show', 1)"
                    toolbar-id="filters"
                    fixed-columns
                    show-export
                    :export-file-name="$exportName"
                    table-name="packages"
                    :extra="['data-search-align'=>'right','data-query-params'=>'packageQueryParams']"
                    :columns="$pkgCols"
                />
            </div>
        </div>
    </section>
@endsection
