@php
    // $lang = Session::get('language');
    // dd($lang);

@endphp

@if (empty($lang) || !$lang->rtl)
    {{-- NON RTL CSS --}}
    <link rel="stylesheet" href="{{ asset('assets/css/main/app.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/pages/otherpages.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/css/custom.css') }}" />
@else
    {{-- RTL CSS --}}
    <link rel="stylesheet" href="{{ asset('assets/css/main/rtl.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/pages/otherpages_rtl.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/css/custom.css') }}" />
@endif
{{-- Bootstrap Switch --}}
<link rel="stylesheet" href="{{ asset('assets/css/bootstrap-switch-button.min.css') }}">

{{-- Phosphor Icons (local) --}}
<link rel="stylesheet" href="{{ asset('assets/libs/phosphor-icons/regular/style.css') }}">
<link rel="stylesheet" href="{{ asset('assets/libs/phosphor-icons/bold/style.css') }}">
<link rel="stylesheet" href="{{ asset('assets/libs/phosphor-icons/fill/style.css') }}">

@php
    use App\Services\CachingService;
    $adminColorDB = CachingService::getSystemSettings('admin_primary_color');
    $adminPrimaryColor =  !empty($adminColorDB) ? $adminColorDB : '#00B2CA';

    // Convert hex to RGB for rgba usage
    $hex = str_replace('#', '', $adminPrimaryColor);
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $rgba = "$r, $g, $b, 0.15";
@endphp

{{-- Dynamic Primary Color Override --}}
<style>
    :root {
        --bs-primary: {{ $adminPrimaryColor }} !important;
        --bs-primary-rgb: {{ $r }}, {{ $g }}, {{ $b }} !important;
        --bs-primary-rgba: {{ $rgba }} !important;
        --bs-blue: {{ $adminPrimaryColor }} !important;
    }
</style>

{{-- Toastify --}}
<link rel="stylesheet" href="{{ asset('assets/extensions/toastify-js/toastify.css') }}">

{{-- Bootstrap Table --}}
<link rel="stylesheet" href="{{ asset('assets/extensions/bootstrap-table/bootstrap-table.min.css') }}"
    type="text/css" />
<link rel="stylesheet"
    href="{{ asset('assets/extensions/bootstrap-table/fixed-columns/bootstrap-table-fixed-columns.min.css') }}"
    type="text/css" />
<link rel="stylesheet" href="{{ asset('assets/extensions/bootstrap-table/bootstrap-table-reorder-rows.css') }}">


{{-- Font Awesome --}}
<link rel="stylesheet" href="{{ asset('assets/extensions/@fortawesome/fontawesome-free/css/all.min.css') }}"
    type="text/css" />

{{-- Magnific Popup --}}
<link rel="stylesheet" href="{{ asset('assets/extensions/magnific-popup/magnific-popup.css') }}">

{{-- Select2 --}}
<link rel="stylesheet" href="{{ asset('assets/extensions/select2/select2.min.css') }}" />
<link rel="stylesheet" href="{{ asset('assets/extensions/select2/select2-bootstrap-5-theme.min.css') }}" />

{{-- Tagify --}}
<link rel="stylesheet" href="{{ asset('assets/extensions/tagify/tagify.css') }}" type="text/css" />

{{-- Sweet Alert --}}
<link rel="stylesheet" href="{{ asset('assets/extensions/sweetalert2/sweetalert2.min.css') }}" />

{{-- Filepond --}}
<link rel="stylesheet" href="{{ asset('assets/extensions/filepond/filepond.min.css') }}" type="text/css" />
<link rel="stylesheet" href="{{ asset('assets/extensions/filepond/filepond-plugin-image-preview.min.css') }}"
    type="text/css" />
<link rel="stylesheet" href="{{ asset('assets/extensions/filepond/filepond-plugin-pdf-preview.min.css') }}"
    type="text/css" />

{{-- Jquery Vectormap --}}
<link rel="stylesheet" href="{{ asset('assets/css/pages/jquery-jvectormap-2.0.5.css') }}" type="text/css" />

{{-- JS Tree --}}
<link rel="stylesheet" href="{{ asset('assets/extensions/jstree/jstree.min.css') }}" />

{{-- <link href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap5.min.css" rel="stylesheet"/> --}}
{{-- <link rel="stylesheet" href="{{ url('assets/extensions/chosen.css') }}"/> --}}

<link rel="stylesheet" href="{{ asset('assets/css/leaflet.css') }}">
<link rel="stylesheet" href="{{ asset('assets/css/map.css') }}">

@php
    // Broken admin images fall back to the placeholder uploaded in Settings (placeholder_image).
    $placeholderImageUrl = \App\Services\CachingService::getSystemSettings('placeholder_image') ?: asset('assets/images/logo/placeholder.png');
@endphp
<script>
    window.placeholderImage = "{{ $placeholderImageUrl }}";

    // Broken image → data-custom-image, else the Settings placeholder. If the fallback
    // fails too, no_image_available.png once, then stop (no error loop).
    function handleImageError(image) {
        const step = Number(image.dataset.imgFallback || 0);
        if (step >= 2) return;
        image.dataset.imgFallback = String(step + 1);
        image.src = step === 0
            ? (image.getAttribute('data-custom-image') || window.placeholderImage)
            : "{{ asset('assets/images/no_image_available.png') }}";
    }

    // One capture-phase listener catches every <img> error on the page — also images
    // inside HTML inserted later (side drawer, bootstrap-table rows). Images with their
    // own onerror handler, or data-no-auto-error, are left to it.
    document.addEventListener('error', function (e) {
        const image = e.target;
        if (!(image instanceof HTMLImageElement)) return;
        if (image.hasAttribute('data-no-auto-error') || image.hasAttribute('onerror')) return;
        handleImageError(image);
    }, true);

    window.defaultProfileImage = "{{ asset('assets/images/default-profile-icon.svg') }}";

    const onErrorImage = (e) => {
        handleImageError(e.target);
    };

    // Early stub: the topbar avatar can fail before custom.js (end of body) defines the real
    // onErrorUserAvatar. Retry once the DOM is parsed; by then custom.js has replaced this function.
    function onErrorUserAvatar(e, size) {
        var img = e.target;
        document.addEventListener('DOMContentLoaded', function () {
            if (window.onErrorUserAvatar !== onErrorUserAvatarStub) window.onErrorUserAvatar({target: img}, size);
        });
    }
    var onErrorUserAvatarStub = onErrorUserAvatar;

    const onErrorImageSidebarHorizontalLogo = (e) => {
        e.target.src = "{{$company_logo}}";
    };
</script>
