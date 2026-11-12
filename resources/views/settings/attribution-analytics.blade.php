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
                <a class="nav-link active" href="{{ route('settings.attribution-analytics.index') }}">{{ __('Providers') }}</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('settings.attribution-events.index') }}">{{ __('Events') }}</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('settings.attribution-event-logs.index') }}">{{ __('Logs') }}</a>
            </li>
        </ul>

        <form class="create-form-without-reset" action="{{ route('settings.attribution-analytics.store') }}" method="post">
            @csrf
            <div class="row d-flex mb-3">
                @foreach ($providers as $key => $provider)
                    <div class="col-md-6 mt-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="divider pt-3">
                                    <h6 class="divider-text">{{ $provider->name }}</h6>
                                </div>

                                <div class="form-group row mt-3">
                                    <label class="col-sm-12 form-check-label mt-2">{{ __('Status') }}</label>
                                    <div class="col-sm-2 col-md-12 col-xs-12 mt-2">
                                        <div class="form-check form-switch">
                                            <input type="hidden" name="provider[{{ $key }}][status]" id="{{ $key }}_status" value="{{ $provider->status }}">
                                            <input class="form-check-input switch-input status-switch" type="checkbox" role="switch"
                                                {{ $provider->status == '1' ? 'checked' : '' }}
                                                id="switch_{{ $key }}_status" aria-label="switch_{{ $key }}_status">
                                        </div>
                                    </div>

                                    <label class="col-sm-12 form-check-label mt-3">{{ __('Configuration') }} <small class="text-muted">({{ __('View Only') }})</small></label>
                                    <div class="col-sm-12 mt-2">
                                        @if ($provider->settings->isNotEmpty())
                                            <table class="table table-sm mb-0">
                                                <tbody>
                                                    @foreach ($provider->settings as $configItem)
                                                        <tr>
                                                            <td class="text-muted">{{ $configItem->key }}</td>
                                                            <td>{{ $configItem->value }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        @else
                                            <p class="text-muted mb-0">{{ __('No configuration added for this provider yet.') }}</p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="col-12 d-flex justify-content-end">
                <button type="submit" class="btn btn-primary me-1 mb-3">{{ __('Save') }}</button>
            </div>
        </form>
    </section>
@endsection
