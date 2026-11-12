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
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <div class="buttons">
            <a class="btn btn-primary" href="{{ route('notification.index') }}">
                < {{ __('Back to Notifications') }} </a>
        </div>
        <div class="row">
            <form action="{{ route('notification.store') }}" id="notification-form" class="create-form needs-validation" method="post" data-parsley-validate enctype="multipart/form-data">
                @csrf
                {{-- Comma separated ids of the users ticked in the table below. --}}
                <textarea id="notif_user_id" name="user_id" style="visibility: hidden; position: absolute;" aria-label="user_id"></textarea>
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">{{ __('Send Notification') }}</div>
                        <div class="card-body">
                            @if ($modules->count() > 1)
                                <div class="form-group">
                                    <label for="module_id" class="form-label">{{ __('Module') }} <span class="text-danger">*</span></label>
                                    <select name="module_id" id="module_id" class="form-select form-control select2" data-parsley-required="true">
                                        <option value="">{{ __('Select a Module') }}</option>
                                        @foreach ($modules as $module)
                                            <option value="{{ $module->id }}" @selected($module->isDefault())>{{ $module->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            <div class="form-group">
                                <label for="send_to" class="form-label">{{ __('Select User') }}</label> <span class="text-danger">*</span>
                                <select id="send_to" name="send_to" class="form-control w-100" required>
                                    <option value="all">{{ __('All') }}</option>
                                    <option value="selected">{{ __('Selected Only') }}</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="title" class="form-label">{{ __('Title') }}</label> <span class="text-danger">*</span>
                                <input name="title" id="title" type="text" class="form-control" placeholder="{{ __('Title') }}" required>
                            </div>

                            <div class="form-group">
                                <label for="message" class="form-label">{{ __('Message') }}</label> <span class="text-danger">*</span>
                                <textarea id="message" name="message" class="form-control" placeholder="{{ __('Message') }}" required></textarea>
                            </div>

                            <div class="form-group">
                                <div class="form-check">
                                    <input id="notif_include_image" name="include_image" type="checkbox" class="form-check-input">
                                    <label for="notif_include_image" class="form-check-label">{{ __('Include Image') }}</label>
                                </div>
                            </div>

                            <div class="form-group" id="notif_show_image" style="display: none">
                                <label for="notif_file" class="form-label">{{ __('Image') }}</label>
                                <input id="notif_file" name="file" type="file" accept="image/*" class="form-control">
                            </div>

                            <div class="form-group">
                                <label for="item_id" class="form-label">{{ __('Advertisement') }}</label>
                                <select name="item_id" class="select2 form-select form-control-sm" id="item_id">
                                    <option value="">{{ __('Select Advertisement') }}</option>
                                    @foreach ($item_list as $row)
                                        <option value="{{ $row->id }}">{{ $row->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    {{-- Recipients, only needed for "Selected Only". --}}
                    <div class="card" id="notif_users_section" style="display: none">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">{{ __('Select Users') }}</h6>
                                <span class="text-muted small"><span id="notif_selected_count">0</span> {{ __('selected') }}</span>
                            </div>
                            @php
                                $userCols = [
                                    ['field'=>'state','title'=>'','checkbox'=>true],
                                    ['field'=>'id','title'=>__('ID'),'sortable'=>true],
                                    ['field'=>'name','title'=>__('Name'),'sortable'=>true],
                                    ['field'=>'mobile','title'=>__('Number'),'sortable'=>true,'formatter'=>'ltrFormatter'],
                                ];
                            @endphp
                            <x-data-table
                                id="user_notification_list"
                                :url="route('customer.show',1)"
                                click-to-select
                                :extra="['data-query-params'=>'notificationUserList']"
                                :columns="$userCols"
                            />
                        </div>
                    </div>

                    <div class="col-12 d-flex justify-content-end">
                        <button class="btn btn-primary" type="submit" name="submit">{{ __('Submit') }}</button>
                    </div>
                </div>
            </form>
        </div>
    </section>
@endsection

@section('script')
    <script>
        // Delegated so it also works when this page is loaded into the side drawer.
        // Advertisements follow the Module select (first render = default module).
        // Namespaced + off(): this script runs again each time the drawer opens.
        $(document).off('change.notifModule').on('change.notifModule', '#notification-form #module_id', function () {
            const $item = $('#notification-form #item_id');
            const placeholder = new Option("{{ __('Select Advertisement') }}", '');
            $item.empty().append(placeholder).val('').trigger('change');
            const moduleId = $(this).val();
            if (!moduleId) return;

            $.get("{{ route('lookup.items') }}", {module_id: moduleId}, function (response) {
                // The module was switched again while this request was running.
                if ($('#notification-form #module_id').val() !== moduleId) return;
                if (response.error) {
                    showErrorToast(response.message);
                    return;
                }
                (response.data || []).forEach(item => $item.append(new Option(item.name, item.id)));
                $item.trigger('change');
            });
        });

        $(document).on('change', '#notification-form #send_to', function () {
            const selected = $(this).val() === 'selected';
            $('#notif_users_section').toggle(selected);
            // The table was laid out while hidden.
            if (selected) $('#user_notification_list').bootstrapTable('resetView');
        });

        $(document).on('change', '#notification-form #notif_include_image', function () {
            const $file = $('#notif_file');
            if (this.checked) {
                $('#notif_show_image').show('fast');
                $file.attr('required', 'required');
            } else {
                $file.val('').removeAttr('required');
                $('#notif_show_image').hide('fast');
            }
        });

        // The users table pages on the server, so bootstrap-table only knows the ticks on the
        // page it shows. Keep every ticked user id here instead, and re-tick them on each page.
        // (window property: this script runs again each time the drawer opens.)
        window.notifSelectedUserIds = new Set();

        function syncNotificationUsers() {
            const ids = Array.from(window.notifSelectedUserIds);
            $('#notif_user_id').val(ids.join(','));
            $('#notif_selected_count').text(ids.length);
        }

        $(document).on('check.bs.table uncheck.bs.table', '#user_notification_list', function (e, row) {
            if (!row || !row.id) return;
            e.type === 'check' ? window.notifSelectedUserIds.add(String(row.id)) : window.notifSelectedUserIds.delete(String(row.id));
            syncNotificationUsers();
        });

        // Header checkbox: ticks / unticks the rows of the current page only.
        $(document).on('check-all.bs.table uncheck-all.bs.table', '#user_notification_list', function (e) {
            $(this).bootstrapTable('getData', {useCurrentPage: true}).forEach(function (row) {
                if (!row.id) return;
                e.type === 'check-all' ? window.notifSelectedUserIds.add(String(row.id)) : window.notifSelectedUserIds.delete(String(row.id));
            });
            syncNotificationUsers();
        });

        // A new page (or search result) is shown: tick the users chosen earlier.
        $(document).on('post-body.bs.table', '#user_notification_list', function () {
            const ids = Array.from(window.notifSelectedUserIds);
            if (ids.length) {
                $(this).bootstrapTable('checkBy', {field: 'id', values: ids.concat(ids.map(Number))});
            }
        });
    </script>
@endsection
