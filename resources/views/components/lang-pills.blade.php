{{--
    Language pills that drive a (hidden) <select id="$select">. Page scripts keep
    listening to the select's `change`, so existing per-language show/hide code
    works unchanged; custom.js syncs pill ⇄ select. Several pill groups may
    drive the same select. Shown even with a single language (like the old
    language dropdown), so the form always shows which language is being edited.
--}}
@props(['languages', 'select', 'selected' => null, 'withSelect' => true])

@php
    $selected ??= $languages->first()?->id;
@endphp

<div {{ $attributes->merge(['class' => 'ec-lang-pills', 'role' => 'tablist', 'data-select' => $select]) }}>
    @foreach ($languages as $lang)
        <button type="button" class="ec-lang-pill {{ $lang->id == $selected ? 'active' : '' }}" data-lang-id="{{ $lang->id }}" role="tab">{{ $lang->name }}</button>
    @endforeach
</div>
@if ($withSelect)
    <select class="d-none ec-lang-select" id="{{ $select }}" aria-hidden="true" tabindex="-1">
        @foreach ($languages as $lang)
            <option value="{{ $lang->id }}" data-code="{{ $lang->code }}" @selected($lang->id == $selected)>{{ $lang->name }}</option>
        @endforeach
    </select>
@endif
