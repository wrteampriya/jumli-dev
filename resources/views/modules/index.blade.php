@extends('layouts.main')

@section('title')
    {{ __('Modules') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row align-items-center">
            <div class="col-12 col-md-7">
                <h4 class="mb-1">@yield('title')</h4>
                <p class="text-muted small mb-0">{{ __('Manage marketplace modules and control which business areas are available on your platform.') }}</p>
            </div>
            <div class="col-12 col-md-5 d-flex justify-content-md-end mt-3 mt-md-0">
                {{-- Modules are install-only (see docs/module-plugin-contract.md) — this
                     links to Plugin Manager, which is where a module actually gets
                     installed once it ships as a licensed plugin. --}}
                @can('module-update')
                    <a href="{{ route('plugin-manager.index') }}" class="btn btn-primary">
                        <i class="ph-bold ph-plus me-1"></i>{{ __('Add Module') }}
                    </a>
                @endcan
            </div>
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            <ul class="nav underline-tabs" id="moduleTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-filter="all" type="button">
                        {{ __('All Modules') }} <span class="tab-count">({{ $moduleCounts['all'] }})</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-filter="active" type="button">
                        {{ __('Active') }} <span class="tab-count">({{ $moduleCounts['active'] }})</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-filter="inactive" type="button">
                        {{ __('Inactive') }} <span class="tab-count">({{ $moduleCounts['inactive'] }})</span>
                    </button>
                </li>
            </ul>
        </div>

        <div id="module-list" class="d-flex flex-column gap-3">
            @foreach ($modules as $module)
                @php
                    $isLastActive = $module->status && $activeModuleIds->count() === 1 && $activeModuleIds->contains($module->id);
                    $moduleStats = $stats[$module->id] ?? ['categories' => 0, 'fields' => 0, 'listings' => 0, 'packages' => 0];
                    $license = $module->plugin_slug ? ($licenses[$module->plugin_slug] ?? null) : null;
                @endphp
                <div class="card module-card shadow-sm mb-0" data-status="{{ $module->status ? 'active' : 'inactive' }}">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                            <div class="d-flex gap-3">
                                <div class="module-card-icon">
                                    <img src="{{ $module->icon }}" alt="{{ $module->name }}" onerror="onErrorImage(event)">
                                </div>
                                <div>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="fw-bold fs-6">{{ $module->name }}</span>
                                        @if ($module->status)
                                            <span class="badge rounded-pill text-bg-success-subtle text-success">{{ __('Active') }}</span>
                                        @else
                                            <span class="badge rounded-pill text-bg-secondary-subtle text-secondary">{{ __('Inactive') }}</span>
                                        @endif
                                        @if ($module->isDefault())
                                            <span class="badge rounded-pill text-bg-info-subtle text-info">{{ __('Default') }}</span>
                                        @endif
                                    </div>
                                    <p class="text-muted small mb-1">{{ $module->description }}</p>
                                    <div class="d-flex flex-wrap gap-3 small text-muted">
                                        <span><i class="ph ph-link me-1"></i>/{{ $module->path_prefix }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex align-items-center gap-3">
                                <div class="module-stat">
                                    <div class="module-stat-value">{{ number_format($moduleStats['categories']) }}</div>
                                    <div class="module-stat-label">{{ __('Categories') }}</div>
                                </div>
                                <div class="module-stat">
                                    <div class="module-stat-value">{{ number_format($moduleStats['fields']) }}</div>
                                    <div class="module-stat-label">{{ __('Fields') }}</div>
                                </div>
                                <div class="module-stat">
                                    <div class="module-stat-value text-primary">{{ number_format($moduleStats['listings']) }}</div>
                                    <div class="module-stat-label">{{ __('Listings') }}</div>
                                </div>
                                <div class="module-stat">
                                    <div class="module-stat-value">{{ number_format($moduleStats['packages']) }}</div>
                                    <div class="module-stat-label">{{ __('Packages') }}</div>
                                </div>

                                <div class="module-stats-divider d-none d-md-block"></div>

                                <div class="dropdown">
                                    <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="ph ph-dots-three-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        @can('module-update')
                                            <li>
                                                <a class="dropdown-item" data-side-drawer href="{{ route('modules.edit', $module->id) }}">
                                                    <i class="ph ph-pencil-simple me-2"></i>{{ __('Edit') }}
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item module-status-toggle {{ $isLastActive ? 'disabled' : '' }}"
                                                   href="#"
                                                   data-id="{{ $module->id }}"
                                                   data-status="{{ $module->status ? 1 : 0 }}"
                                                   @if ($isLastActive) title="{{ __('At least one module must stay active') }}" @endif>
                                                    @if ($module->status)
                                                        <i class="ph ph-pause-circle me-2"></i>{{ __('Deactivate') }}
                                                    @else
                                                        <i class="ph ph-play-circle me-2"></i>{{ __('Activate') }}
                                                    @endif
                                                </a>
                                            </li>
                                        @endcan
                                        @if ($license)
                                            <li>
                                                <a class="dropdown-item" href="{{ route('plugin-manager.index') }}">
                                                    <i class="ph ph-key me-2"></i>{{ __('Manage License') }}
                                                </a>
                                            </li>
                                        @endif
                                        @can('module-update')
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger module-delete {{ $isLastActive ? 'disabled' : '' }}"
                                                   href="#"
                                                   data-id="{{ $module->id }}"
                                                   data-name="{{ $module->name }}"
                                                   @if ($isLastActive) title="{{ __('At least one module must stay active') }}" @endif>
                                                    <i class="ph ph-trash me-2"></i>{{ __('Delete') }}
                                                </a>
                                            </li>
                                        @endcan
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Renders nothing at all while everything fits on one page (Module::count() <= 20 today) —
             this is what makes pagination a true no-op right now instead of a visual change. --}}
        <div class="mt-3">
            {{ $modules->links() }}
        </div>
    </section>
@endsection

@section('script')
    <script>
        // All / Active / Inactive tabs — pure client-side filtering, no server round trip.
        document.querySelectorAll('#moduleTabs .nav-link').forEach(function (tab) {
            tab.addEventListener('click', function () {
                document.querySelectorAll('#moduleTabs .nav-link').forEach(t => t.classList.remove('active'));
                this.classList.add('active');

                const filter = this.getAttribute('data-filter');
                document.querySelectorAll('#module-list > [data-status]').forEach(function (card) {
                    card.style.display = (filter === 'all' || card.getAttribute('data-status') === filter) ? '' : 'none';
                });
            });
        });

        $(document).on('click', '.module-status-toggle', function (e) {
            e.preventDefault();
            if ($(this).hasClass('disabled')) {
                return;
            }

            const id = $(this).data('id');
            const willActivate = $(this).data('status') == 0;

            Swal.fire({
                title: "{{ __('Are you sure?') }}",
                text: willActivate
                    ? "{{ __('This will activate the module.') }}"
                    : "{{ __('This will deactivate the module.') }}",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: "{{ __('Yes') }}",
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }

                ajaxRequest('PUT', window.baseurl + 'common/change-status', {
                    id: id,
                    table: 'modules',
                    status: willActivate ? 1 : 0,
                }, null, function (response) {
                    showSuccessToast(response.message);
                    location.reload();
                }, function (error) {
                    showErrorToast(error.message);
                });
            });
        });

        $(document).on('click', '.module-delete', function (e) {
            e.preventDefault();
            if ($(this).hasClass('disabled')) {
                return;
            }

            const id = $(this).data('id');
            const name = $(this).data('name');

            Swal.fire({
                title: "{{ __('Are you sure?') }}",
                text: "{{ __('This will permanently delete') }} " + name + ".",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: "{{ __('Yes, delete it!') }}",
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }

                ajaxRequest('DELETE', window.baseurl + 'modules/' + id, {}, null, function (response) {
                    showSuccessToast(response.message);
                    location.reload();
                }, function (error) {
                    showErrorToast(error.message);
                });
            });
        });
    </script>
@endsection
