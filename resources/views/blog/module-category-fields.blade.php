{{--
    Module → Category fields of the blog create / edit forms. A blog's module comes from its
    category, so Module isn't stored; picking one reloads the Category list from the server
    (blog/module-category-script) and the server checks the pair on save. Module is only asked
    once there is more than one. Needs $modules, $categories (the selected module's, as
    id/name pairs), $selectedModuleId and $selectedCategoryId.
--}}
@if ($modules->count() > 1)
    <div class="col-md-6">
        <div class="form-group">
            <label for="blog_module_id">{{ __('Module') }} <span class="text-danger">*</span></label>
            <select name="module_id" id="blog_module_id" class="form-control select-search" data-placeholder="{{ __('Select a Module') }}" data-parsley-required="true">
                <option value="">{{ __('Select a Module') }}</option>
                @foreach ($modules as $module)
                    <option value="{{ $module->id }}" @selected($selectedModuleId == $module->id)>{{ $module->name }}</option>
                @endforeach
            </select>
        </div>
    </div>
@endif
<div class="col-md-6">
    <div class="form-group">
        <label for="blog_category_id">{{ __("Category") }} <span class="text-danger">*</span></label>
        <select name="category_id" id="blog_category_id" class="form-control select-search" data-placeholder="{{ __('Select Category') }}" data-parsley-required="true">
            <option value="">{{ __('Select Category') }}</option>
            @foreach ($categories as $category)
                <option value="{{ $category['id'] }}" @selected($selectedCategoryId == $category['id'])>{{ $category['name'] }}</option>
            @endforeach
        </select>
    </div>
</div>
