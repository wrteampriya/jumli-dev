function queryParams(p) {
    return p;
}

// Module filter (<x-module-filter>) — the select only exists on multi-module
// installs, so this is '' (no filter) everywhere else.
function moduleFilterValue() {
    return $('#filter_module_id').val() || '';
}

function moduleQueryParams(p) {
    return {
        ...p,
        module_id: moduleFilterValue(),
    };
}

$(document).on('change', '.module-table-filter', function () {
    const table = $(this).data('table');
    if (table) {
        $(table).bootstrapTable('refresh');
    }
});

function reportReasonQueryParams(p) {
    return {
        ...p,
        "status": $('#filter_status').val(),
    };
}

function userListQueryParams(p) {
    return {
        ...p,
        "status": $('#filter_status').val(),
    };
}

function userPackageQueryParams(p) {
    return {
        ...p,
        "status": $('#filter_status').val(),
        module_id: moduleFilterValue(),
    };
}

function notificationUserList(p) {
    return {
        ...p,
        notification_list: 1
    };
}

// Slider list: module filter + platform (web / app) + status (1 / 0); empty = all.
function sliderQueryParams(p) {
    return {
        ...moduleQueryParams(p),
        platform: $('#slider-platform-filter').val() || '',
        status: $('#slider-status-filter').val() || '',
    };
}

// Popular Categories list: module filter + status filter (1 / 0; empty = all).
function popularCategoriesQueryParams(p) {
    return { ...moduleQueryParams(p), status: $('#pc-status-filter').val() || '' };
}

// Feature Sections list: module filter + category + status (1 / 0; empty = all).
function featureSectionQueryParams(p) {
    return { ...moduleQueryParams(p), category_id: $('#fs-category-filter').val() || '', status: $('#fs-status-filter').val() || '' };
}
