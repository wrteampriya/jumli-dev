@extends('layouts.main')
@section('title')
    {{__("Edit Tips")}}
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
            <a class="btn btn-primary" href="{{ route('tips.index') }}">< {{__("Back to All Tips")}} </a>
        </div>
        <div class="row">
            <form class="form-redirection" action="{{ route('tips.update',$tip->id) }}" method="POST" data-parsley-validate enctype="multipart/form-data" data-success-function="successFunction">
                @csrf
                <input type="hidden" name="_method" value="PUT">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">{{__("Edit Tips")}}</div>

                        <div class="card-body mt-2">
                            @if ($modules->count() > 1)
                                <div class="form-group mb-3" style="max-width: 320px;">
                                    <label for="module_id" class="form-label">{{ __('Module') }} <span class="text-danger">*</span></label>
                                    <select name="module_id" id="module_id" class="form-select form-control select2" data-parsley-required="true">
                                        <option value="">{{ __('Select a Module') }}</option>
                                        @foreach ($modules as $module)
                                            <option value="{{ $module->id }}" @if ($module->id == $tip->module_id) selected @endif>{{ $module->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            <ul class="nav nav-tabs" id="langTabs" role="tablist">
                                @foreach($languages as $key => $lang)
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link @if($key == 0) active @endif" id="tab-{{ $lang->id }}" data-bs-toggle="tab" data-bs-target="#lang-{{ $lang->id }}" type="button" role="tab">
                                            {{ $lang->name }}
                                        </button>
                                    </li>
                                @endforeach
                            </ul>

                            <div class="tab-content mt-3">
                                @foreach($languages as $key => $lang)
                                    <div class="tab-pane fade @if($key == 0) show active @endif" id="lang-{{ $lang->id }}" role="tabpanel">
                                        <input type="hidden" name="languages[]" value="{{ $lang->id }}">

                                        <div class="form-group">
                                            <label>{{ __('Description') }} ({{ $lang->name }}) <span class="text-danger">*</span></label>
                                            <textarea name="description[{{ $lang->id }}]" class="form-control" cols="10" rows="5" @if($lang->id == 1) required @endif>{{ $translations[$lang->id] ?? '' }}</textarea>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="col-md-12 text-end">
                        <input type="submit" class="btn btn-primary" value="{{__("Save and Back")}}">
                    </div>
                </div>
            </form>
        </div>
    </section>
@endsection
@section('js')
    <script>
        function successFunction() {
            setTimeout(function () {
                window.location.href = "{{ route('tips.index') }}";
            }, 1000)
        }
    </script>
@endsection
