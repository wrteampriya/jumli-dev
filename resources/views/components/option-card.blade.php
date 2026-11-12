{{--
    Selectable card (single choice), e.g. listing module / format. A button, so
    it is keyboard-usable; the page script toggles `.selected` and reads its
    data-* attributes. `tile` = compact centred variant (category grid).
    $icon: Phosphor class ("ph-play-circle"); $image: icon URL, used when no $icon;
    $placeholder: image shown when $image fails to load.
--}}
@props([
    'title',
    'description' => null,
    'icon' => null,
    'image' => null,
    'placeholder' => null,
    'badge' => null,
    'selected' => false,
    'tile' => false,
])

<button type="button" {{ $attributes->class(['ec-option-card', 'ec-option-tile' => $tile, 'selected' => $selected]) }}>
    <span class="ec-option-corner">
        @if ($badge)
            <span class="ec-option-badge">{{ $badge }}</span>
        @endif
        <span class="ec-option-check" aria-hidden="true"><i class="ph-bold ph-check"></i></span>
    </span>
    <span @class(["ec-option-icon", "is-image" => !$icon && $image]) aria-hidden="true">
        @if ($icon)
            <i class="ph-fill {{ $icon }}"></i>
        @elseif ($image)
            {{-- A broken image falls back via the global handler (layouts/include): data-custom-image, else placeholder_image. --}}
            <img src="{{ $image }}" alt="" @if ($placeholder) data-custom-image="{{ $placeholder }}" @endif>
        @endif
    </span>
    <span class="ec-option-title">{{ $title }}</span>
    @if ($description)
        <span class="ec-option-text">{{ $description }}</span>
    @endif
</button>
