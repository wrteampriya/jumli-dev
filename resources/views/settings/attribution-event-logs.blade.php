@extends('layouts.main')

@section('title')
    {{ __('Attribution') . ' & ' . __('Analytics') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h4>@yield('title')</h4>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first"></div>
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <ul class="nav nav-pills mb-3">
            <li class="nav-item">
                <a class="nav-link" href="{{ route('settings.attribution-analytics.index') }}">{{ __('Providers') }}</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('settings.attribution-events.index') }}">{{ __('Events') }}</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="{{ route('settings.attribution-event-logs.index') }}">{{ __('Logs') }}</a>
            </li>
        </ul>

        <div class="card">
            <div class="card-body">
                <small class="text-muted d-block mb-3">{{ __('Diagnostic tail of the most recent event logs — only events with Log enabled are recorded here.') }}</small>
                <div class="table-responsive">
                    @php
                        $cols = [
                            ['field' => 'id', 'title' => __('ID'), 'align' => 'center', 'sortable' => true],
                            ['field' => 'event_key', 'title' => __('Event'), 'sortable' => true],
                            ['field' => 'provider', 'title' => __('Provider'), 'sortable' => true],
                            ['field' => 'status_badge', 'title' => __('Status'), 'escape' => false, 'sortable' => false],
                            ['field' => 'params_display', 'title' => __('Params'), 'sortable' => false],
                            ['field' => 'message', 'title' => __('Message'), 'sortable' => false],
                            ['field' => 'user_name', 'title' => __('User'), 'sortable' => false],
                            ['field' => 'created_at', 'title' => __('Time'), 'sortable' => true],
                        ];
                    @endphp
                    <x-data-table
                        id="attribution_event_logs_table"
                        :url="route('settings.attribution-event-logs.show')"
                        sort-name="created_at"
                        sort-order="desc"
                        table-name="attribution_event_logs"
                        :columns="$cols"
                    />
                </div>
            </div>
        </div>
    </section>
@endsection
