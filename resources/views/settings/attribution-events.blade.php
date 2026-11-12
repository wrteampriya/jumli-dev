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
                <a class="nav-link active" href="{{ route('settings.attribution-events.index') }}">{{ __('Events') }}</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('settings.attribution-event-logs.index') }}">{{ __('Logs') }}</a>
            </li>
        </ul>

        <form class="create-form-without-reset" action="{{ route('settings.attribution-events.store') }}" method="post">
            @csrf

            <ul class="nav nav-tabs mb-3" id="providerEventsTab" role="tablist">
                @foreach ($providers as $index => $provider)
                    <li class="nav-item" role="presentation">
                        <button class="nav-link {{ $loop->first ? 'active' : '' }}"
                                id="provider-tab-{{ $provider->key }}"
                                data-bs-toggle="tab"
                                data-bs-target="#provider-content-{{ $provider->key }}"
                                type="button"
                                role="tab"
                                aria-controls="provider-content-{{ $provider->key }}"
                                aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                            {{ $provider->name }}
                            @if ($provider->status)
                                <span class="badge bg-success ms-1">{{ __('Active') }}</span>
                            @else
                                <span class="badge bg-secondary ms-1">{{ __('Disabled') }}</span>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>

            <div class="tab-content" id="providerEventsTabContent">
                @foreach ($providers as $index => $provider)
                    @php
                        $currentSettings = $providerEvents->get($provider->id);
                    @endphp
                    <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}"
                         id="provider-content-{{ $provider->key }}"
                         role="tabpanel"
                         aria-labelledby="provider-tab-{{ $provider->key }}">
                        <div class="card">
                            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">{{ $provider->name }} {{ __('Event Settings') }}</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table align-middle">
                                        <thead>
                                            <tr>
                                                <th>{{ __('Event') }}</th>
                                                <th>{{ __('Enable') }}</th>
                                                <th>{{ __('Log') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($events as $event)
                                                @php
                                                    $setting = $currentSettings?->get($event->id);
                                                    $isEnabled = $setting ? (bool)$setting->enable : (bool)$event->enable;
                                                    $isLogged = $setting ? (bool)$setting->log : (bool)$event->log;
                                                @endphp
                                                <tr>
                                                    <td><code>{{ $event->key }}</code></td>
                                                    <td>
                                                        @if ($event->is_configurable)
                                                            <div class="form-check form-switch">
                                                                <input type="hidden" name="events[{{ $provider->id }}][{{ $event->id }}][enable]" id="{{ $provider->key }}_{{ $event->key }}_enable" value="{{ $isEnabled ? 1 : 0 }}">
                                                                <input class="form-check-input switch-input status-switch" type="checkbox" role="switch"
                                                                    {{ $isEnabled ? 'checked' : '' }}
                                                                    id="switch_{{ $provider->key }}_{{ $event->key }}_enable" aria-label="switch_{{ $provider->key }}_{{ $event->key }}_enable">
                                                            </div>
                                                        @else
                                                            <span class="badge bg-secondary">{{ __('Always On') }}</span>
                                                            <input type="hidden" name="events[{{ $provider->id }}][{{ $event->id }}][enable]" value="1">
                                                        @endif
                                                    </td>
                                                    <td>
                                                        <div class="form-check form-switch">
                                                            <input type="hidden" name="events[{{ $provider->id }}][{{ $event->id }}][log]" id="{{ $provider->key }}_{{ $event->key }}_log" value="{{ $isLogged ? 1 : 0 }}">
                                                            <input class="form-check-input switch-input status-switch" type="checkbox" role="switch"
                                                                {{ $isLogged ? 'checked' : '' }}
                                                                id="switch_{{ $provider->key }}_{{ $event->key }}_log" aria-label="switch_{{ $provider->key }}_{{ $event->key }}_log">
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="col-12 d-flex justify-content-end mt-3">
                <button type="submit" class="btn btn-primary me-1 mb-3">{{ __('Save') }}</button>
            </div>
        </form>
    </section>
@endsection
