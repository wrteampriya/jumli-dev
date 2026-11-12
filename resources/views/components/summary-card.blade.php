{{--
    Read-only summary card (wizard review step): title, an Edit link that opens
    step $edit (a tab pane id), and key/value rows in the slot
    (<div class="ec-summary-row"><span>Label</span><strong>Value</strong></div>).
--}}
@props(['title', 'edit' => null])

<div {{ $attributes->merge(['class' => 'ec-summary-card']) }}>
    <div class="ec-summary-head">
        <h5 class="ec-summary-title">{{ $title }}</h5>
        @if ($edit)
            <button type="button" class="ec-summary-edit" data-goto-step="{{ $edit }}">{{ __('Edit') }}</button>
        @endif
    </div>
    <div class="ec-summary-rows">
        {{ $slot }}
    </div>
</div>
