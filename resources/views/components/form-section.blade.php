{{--
    Bordered form section with an icon tile, title, optional subtitle and
    header actions (language pills, "Generate with AI", a type picker...).
--}}
@props(['title', 'subtitle' => null, 'icon' => null, 'hint' => null])

<div {{ $attributes->merge(['class' => 'ec-section']) }}>
    <div class="ec-section-head">
        @if ($icon)
            <span class="ec-section-icon" aria-hidden="true"><i class="ph-fill {{ $icon }}"></i></span>
        @endif
        <div class="ec-section-heading">
            <h5 class="ec-section-title">{{ $title }}@if ($hint) <span class="ec-section-hint">{{ $hint }}</span>@endif</h5>
            @if ($subtitle)
                <p class="ec-section-subtitle">{{ $subtitle }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="ec-section-actions">{{ $actions }}</div>
        @endisset
    </div>
    <div class="ec-section-body">
        {{ $slot }}
    </div>
</div>
