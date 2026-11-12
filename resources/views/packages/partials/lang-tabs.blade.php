{{--
    Language pills for the package form (sections 1 and 5). Every pill group in the form
    switches together (packages/partials/form-script). Hidden with a single language.
--}}
@if ($languages->count() > 1)
    <div class="pkg-lang-tabs" role="tablist">
        @foreach ($languages as $lang)
            <button type="button" class="pkg-lang-tab {{ $loop->first ? 'active' : '' }}" data-lang="{{ $lang->id }}" role="tab">
                {{ $lang->name }}
                @if ($lang->id == 1) <span class="pkg-required-dot" title="{{ __('Required') }}"></span> @endif
            </button>
        @endforeach
    </div>
@endif
