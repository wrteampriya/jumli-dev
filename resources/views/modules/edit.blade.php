@extends('layouts.main')

@section('title')
    {{ __('Edit Module') }} - {{ $module->name }}
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
            <a class="btn btn-primary" href="{{ route('modules.index') }}">
                < {{ __('Back to All Modules') }} </a>
        </div>
        <div class="row">
            <form class="edit-form" action="{{ route('modules.update', $module->id) }}" method="POST"
                data-parsley-validate data-success-function="afterModuleUpdate">
                @method('PUT')
                @csrf
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">{{ __('Identity') }}</div>
                        <div class="card-body mt-2">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label class="form-label">{{ __('Slug') }}</label>
                                        <input type="text" class="form-control" value="{{ $module->slug }}" disabled>
                                        <small class="text-muted">{{ __('Immutable — set at install time.') }}</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label class="form-label">{{ __('URL Prefix') }}</label>
                                        <input type="text" class="form-control" value="/{{ $module->path_prefix }}" disabled>
                                        <small class="text-muted">{{ __('Renaming this changes every existing category URL under this module — not editable yet.') }}</small>
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <div class="form-group mb-3 mandatory">
                                        <label for="name">{{ __('Name') }}</label>
                                        <input type="text" value="{{ $module->name }}" name="name" id="name"
                                            class="form-control" data-parsley-required="true" maxlength="100"
                                            placeholder="{{ __('e.g. Classified') }}" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group mb-3">
                                        <label for="sequence">{{ __('Order') }}</label>
                                        <input type="number" value="{{ $module->sequence }}" name="sequence"
                                            id="sequence" class="form-control" min="0">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label for="icon">{{ __('Icon') }}</label>
                                        <input type="file" name="icon" id="icon" class="form-control" accept=".jpg,.jpeg,.png">
                                        <small class="text-muted">{{ __('Icon (use 256 x 256 size for better view). Leave empty to keep the current icon.') }}</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <img src="{{ $module->icon }}"
                                            alt="{{ $module->name }}" id="icon-preview" class="img-thumbnail"
                                            style="width: 80px; height: 80px; object-fit: contain;" onerror="onErrorImage(event)">
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="form-group mb-3">
                                        <label for="description">{{ __('Description') }}</label>
                                        <textarea name="description" id="description" class="form-control" rows="3"
                                            maxlength="1000" placeholder="{{ __('Shown on the module card.') }}">{{ $module->description }}</textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">{{ __('Supported Pricing') }}</div>
                        <div class="card-body mt-2">
                            <div class="row">
                                @foreach ($pricingModeOptions as $value => $label)
                                    <div class="col-md-3">
                                        <div class="form-check mb-2">
                                            <input type="checkbox" class="form-check-input pricing-mode-checkbox"
                                                name="pricing_modes[]" id="pricing_mode_{{ $value }}"
                                                value="{{ $value }}"
                                                {{ in_array($value, $formSettings['pricing_modes'], true) ? 'checked' : '' }}>
                                            <label class="form-check-label" for="pricing_mode_{{ $value }}">{{ $label }}</label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="form-group mb-0 mt-2" style="max-width: 320px;">
                                <label for="default_pricing_mode" class="form-label">{{ __('Default') }}</label>
                                <select name="default_pricing_mode" id="default_pricing_mode" class="form-control">
                                    @foreach ($pricingModeOptions as $value => $label)
                                        <option value="{{ $value }}" {{ $formSettings['default_pricing_mode'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">{{ __('Monetization') }}</div>
                        <div class="card-body mt-2">
                            <div class="form-check form-switch mb-3">
                                <input type="checkbox" class="form-check-input" role="switch"
                                    name="ad_packages_enabled" id="ad_packages_enabled" value="1"
                                    {{ $formSettings['ad_packages_enabled'] ? 'checked' : '' }}>
                                <label class="form-check-label" for="ad_packages_enabled">{{ __('Ad Packages (listing limits)') }}</label>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input type="checkbox" class="form-check-input" role="switch"
                                    name="feature_packages_enabled" id="feature_packages_enabled" value="1"
                                    {{ $formSettings['feature_packages_enabled'] ? 'checked' : '' }}>
                                <label class="form-check-label" for="feature_packages_enabled">{{ __('Feature / Promote Packages') }}</label>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">{{ __('Media Rules') }}</div>
                        <div class="card-body mt-2">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-3 mandatory">
                                        <label for="max_images">{{ __('Total images limit') }}</label>
                                        <input type="number" name="max_images" id="max_images" class="form-control"
                                            min="1" max="50" value="{{ $formSettings['max_images'] }}" data-parsley-required="true" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label class="form-label d-block">{{ __('Product videos') }}</label>
                                        @php $productVideoAllowed = $formSettings['product_video']; @endphp
                                        <div class="form-check form-check-inline">
                                            <input type="radio" class="form-check-input" name="product_video"
                                                id="product_video_allowed" value="1" {{ $productVideoAllowed ? 'checked' : '' }}>
                                            <label class="form-check-label" for="product_video_allowed">{{ __('Allowed') }}</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input type="radio" class="form-check-input" name="product_video"
                                                id="product_video_not_allowed" value="0" {{ $productVideoAllowed ? '' : 'checked' }}>
                                            <label class="form-check-label" for="product_video_not_allowed">{{ __('Not allowed') }}</label>
                                        </div>
                                        <small class="form-text text-muted d-block">{{ __('When allowed, an ad can have one video: either a link or an uploaded file.') }}</small>
                                    </div>
                                </div>
                                {{-- Only relevant when product videos are allowed; disabled (not sent) otherwise. --}}
                                <div class="col-md-6" id="video_max_file_size_wrapper" @style(['display: none' => !$productVideoAllowed])>
                                    <div class="form-group mb-3 mandatory">
                                        <label for="video_max_file_size_mb">{{ __('Product video max size (MB)') }}</label>
                                        <input type="number" name="video_max_file_size_mb" id="video_max_file_size_mb" class="form-control"
                                            min="1" max="500" value="{{ $formSettings['video_max_file_size_mb'] }}" data-parsley-required="true" required
                                            @disabled(!$productVideoAllowed)>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-12 text-end">
                        <input type="submit" class="btn btn-primary" value="{{ __('Save') }}">
                    </div>
                </div>
            </form>
        </div>
    </section>
@endsection

@section('script')
    <script>
        function afterModuleUpdate() {
            setTimeout(function () {
                window.location.href = "{{ route('modules.index') }}";
            }, 1000)
        }

        document.getElementById('icon').addEventListener('change', function () {
            if (this.files && this.files[0]) {
                document.getElementById('icon-preview').src = URL.createObjectURL(this.files[0]);
            }
        });

        // A dropdown that picks one of the options checked above it (Default
        // pricing) only lists those checked options; the
        // selection falls back to the first checked one, and the select is
        // disabled when none applies. The server enforces the same rule.
        function bindDependentSelect(checkboxSelector, selectId) {
            var checkboxes = document.querySelectorAll(checkboxSelector);
            var select = document.getElementById(selectId);
            var allOptions = Array.prototype.map.call(select.options, function (option) {
                return option.cloneNode(true);
            });

            function sync() {
                var checked = Array.prototype.filter.call(checkboxes, function (checkbox) {
                    return checkbox.checked;
                }).map(function (checkbox) {
                    return checkbox.value;
                });
                var selected = select.value;

                select.innerHTML = '';
                allOptions.forEach(function (option) {
                    if (checked.indexOf(option.value) !== -1) {
                        select.appendChild(option.cloneNode(true));
                    }
                });

                if (select.options.length) {
                    select.value = checked.indexOf(selected) !== -1 ? selected : select.options[0].value;
                }
                select.disabled = !select.options.length;
            }

            checkboxes.forEach(function (checkbox) {
                checkbox.addEventListener('change', sync);
            });
            sync();
        }

        bindDependentSelect('.pricing-mode-checkbox', 'default_pricing_mode');

        // Product video max size only applies when product videos are allowed.
        function syncProductVideoSize() {
            var allowed = document.getElementById('product_video_allowed').checked;
            document.getElementById('video_max_file_size_wrapper').style.display = allowed ? '' : 'none';
            document.getElementById('video_max_file_size_mb').disabled = !allowed;
        }

        document.querySelectorAll('input[name="product_video"]').forEach(function (radio) {
            radio.addEventListener('change', syncProductVideoSize);
        });
        syncProductVideoSize();
    </script>
@endsection
