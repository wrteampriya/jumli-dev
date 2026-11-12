{{--
    Module filter for admin list tables. Renders nothing while there is only
    one module (forModule() is a no-op then anyway). The select sends
    module_id through moduleQueryParams()/moduleFilterValue() in
    bootstrap-table/queryParams.js, and refreshes `table` on change — pass
    table="" when the page refreshes the table itself.
--}}
@props(['modules', 'table' => '#table_list'])

@if ($modules->count() > 1)
    <div {{ $attributes->merge(['class' => 'col-12']) }}>
        {{-- form-label / form-select: same look as the other list filters next to it. --}}
        <label for="filter_module_id" class="form-label">{{ __('Module') }}</label>
        <select id="filter_module_id" class="form-select module-table-filter" data-table="{{ $table }}">
            <option value="">{{ __('All') }}</option>
            @foreach ($modules as $module)
                <option value="{{ $module->id }}">{{ $module->name }}</option>
            @endforeach
        </select>
    </div>
@endif
