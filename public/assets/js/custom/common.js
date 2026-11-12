/*
* Common JS is used to write code which is generally used for all the UI components
* Specific component related code won't be written here
*/

"use strict";
$(document).ready(function () {
    $('#table_list').on('all.bs.table', function () {
        $('#toolbar').parent().addClass('col-12  col-md-7 col-lg-7 p-0');
    })
    $(function () {
        $('[data-toggle="tooltip"]').tooltip()
    })

    initPermissionTree($(document));
})
// Also called by side-drawer.js for role forms loaded into the drawer.
function initPermissionTree($scope) {
    // Hidden permission[] inputs mirror the checked nodes — on load too, so
    // saving a role without touching the tree keeps its permissions.
    function syncPermissions(e, data) {
        let html = "";
        data.instance.get_selected(true).forEach(function (node) {
            let permissionName = node.data && node.data.name;
            if (permissionName) {
                html += "<input type='hidden' name='permission[]' value='" + permissionName + "'/>"
            }
        });
        $scope.find('#permission-list').html(html);
    }
    $scope.find('.permission-tree').on('ready.jstree changed.jstree', syncPermissions).jstree({
        "plugins": ["checkbox"],
    });
}

//Setup CSRF Token default in AJAX Request
$.ajaxSetup({
    headers: {
        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
    }
});

$('#create-form,.create-form,.create-form-without-reset').on('submit', function (e) {
    e.preventDefault();
    let formElement = $(this);
    let submitButtonElement = $(this).find(':submit');
    let url = $(this).attr('action');

    let data = new FormData(this);
    let preSubmitFunction = $(this).data('pre-submit-function');
    if (preSubmitFunction) {
        //If custom function name is set in the Form tag then call that function using eval
        if (eval(preSubmitFunction + "()") == false) {
            return false;
        }
    }
    let customSuccessFunction = $(this).data('success-function');

    // noinspection JSUnusedLocalSymbols

    function successCallback(response) {
        if (!$(formElement).hasClass('create-form-without-reset')) {
            formElement[0].reset();
            $(".select2").val("").trigger('change');
            $('.filepond').filepond('removeFile')
        }
        $('#table_list').bootstrapTable('refresh');
        // Modal shown as a side drawer: done once saved.
        formElement.closest('.modal.modal-drawer').modal('hide');
        if (customSuccessFunction) {
            //If custom function name is set in the Form tag then call that function using eval
            eval(customSuccessFunction + "(response)");
        }
    }

    formAjaxRequest('POST', url, data, formElement, submitButtonElement, successCallback);
})

$('#edit-form,.edit-form').on('submit', function (e) {
    e.preventDefault();
    let formElement = $(this);
    let submitButtonElement = $(this).find(':submit');
    let data = new FormData(this);
    $(formElement).parents('modal').modal('hide');
    // let url = $(this).attr('action') + "/" + data.get('edit_id');
    let url = $(this).attr('action');
    let preSubmitFunction = $(this).data('pre-submit-function');
    if (preSubmitFunction) {
        //If custom function name is set in the Form tag then call that function using eval
        if (eval(preSubmitFunction + "()") == false) {
            return false;
        }
    }
    let customSuccessFunction = $(this).data('success-function');

    // noinspection JSUnusedLocalSymbols
    function successCallback(response) {
        $('#table_list').bootstrapTable('refresh');
        setTimeout(function () {
            $('#editModal').modal('hide');
            $(formElement).parents('.modal').modal('hide');
        }, 1000)
        if (customSuccessFunction) {
            //If custom function name is set in the Form tag then call that function using eval
            eval(customSuccessFunction + "(response)");
        }
    }

    formAjaxRequest('PUT', url, data, formElement, submitButtonElement, successCallback);
})

$(document).on('click', '.delete-form', function (e) {
    e.preventDefault();
    // The row's own table (e.g. one inside the side drawer) and the page's list.
    const $tables = $(this).closest('table[data-toggle="table"]').add($('#table_list').first());
    showDeletePopupModal($(this).attr('href'), {
        successCallBack: function () {
            $tables.bootstrapTable('refresh');
        }
    })
})

$(document).on('click', '.restore-data', function (e) {
    e.preventDefault();
    showRestorePopupModal($(this).attr('href'), {
        successCallBack: function () {
            $('#table_list').bootstrapTable('refresh');
        }
    })
})

$(document).on('click', '.trash-data', function (e) {
    e.preventDefault();
    showPermanentlyDeletePopupModal($(this).attr('href'), {
        successCallBack: function () {
            $('#table_list').bootstrapTable('refresh');
        }
    })
})

$(document).on('click', '.set-form-url', function (e) {
    //This event will be called when user clicks on the edit button of the bootstrap table
    e.preventDefault();
    $('#edit-form,.edit-form').attr('action', $(this).attr('href'));
})

$(document).on('click', '.delete-form-reload', function (e) {
    e.preventDefault();
    showDeletePopupModal($(this).attr('href'), {
        successCallBack: function () {
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        }
    })
})

// Change event for Status toggle change in Bootstrap-table
$(document).on('change', '.update-status', function () {
    let tableElement = $(this).parents('table');
    let url = $(tableElement).data('custom-status-change-url') || window.baseurl + "common/change-status";
    ajaxRequest('PUT', url, {
        id: $(this).attr('id'),
        table: $(tableElement).data('table'),
        column: $(tableElement).data('status-column') || "",
        status: $(this).is(':checked') ? 1 : 0
    }, null, function (response) {
        showSuccessToast(response.message);
    }, function (error) {
        showErrorToast(error.message);
    })
})


//Fire Ajax request when the Bootstrap-table rows are rearranged
$('#table_list').on('reorder-row.bs.table', function (element, rows) {
    let url = $(element.currentTarget).data('custom-reorder-row-url') || window.baseurl + "common/change-row-order";
    ajaxRequest('PUT', url, {
        table: $(element.currentTarget).data('table'),
        column: $(element.currentTarget).data('reorder-column') || "",
        data: rows
    }, null, function (success) {
        $('#table_list').bootstrapTable('refresh');
        showSuccessToast(success.message);
    }, function (error) {
        showErrorToast(error.message);
    })
})


$('.img_input').click(function () {
    $('#edit_cs_image').click();
});
$('.preview-image-file').on('change', function () {
    const [file] = this.files
    if (file) {
        $('.preview-image').attr('src', URL.createObjectURL(file));
    }
})


$('.form-redirection').on('submit', function (e) {
    let parsley = $(this).parsley({
        excluded: 'input[type=button], input[type=submit], input[type=reset], :hidden'
    });
    parsley.validate();
    if (parsley.isValid()) {
        $(this).find(':submit').attr('disabled', true);
    }
})

$('#editlanguage-form,.editlanguage-form').on('submit', function (e) {
    e.preventDefault();
    let formElement = $(this);
    let submitButtonElement = $(this).find(':submit');
    let data = new FormData(this);
    $(formElement).parents('modal').modal('hide');
    // let url = $(this).attr('action') + "/" + data.get('edit_id');
    let url = $(this).attr('action');
    let preSubmitFunction = $(this).data('pre-submit-function');
    if (preSubmitFunction) {
        //If custom function name is set in the Form tag then call that function using eval
        eval(preSubmitFunction + "()");
    }
    let customSuccessFunction = $(this).data('success-function');

    // noinspection JSUnusedLocalSymbols
    function successCallback(response) {
        setTimeout(() => {
            window.location.reload();
        }, 1000);
    }
    formAjaxRequest('PUT', url, data, formElement, submitButtonElement, successCallback);
})

// A required field inside a hidden tab (e.g. the English tab of a translated form while
// another language is showing) can't be focused, so the browser cancels the submit with
// only a console error ("An invalid form control ... is not focusable"). Show that
// field's tab first so the browser can point at it. Only the first invalid field of a
// submit does this; tabs a page keeps locked (show.bs.tab prevented) stay closed.
let invalidFieldTabShown = false;
document.addEventListener('invalid', function (e) {
    const field = e.target;
    if (invalidFieldTabShown || !field.closest) return;
    invalidFieldTabShown = true;
    setTimeout(function () { invalidFieldTabShown = false; }, 0);

    const panes = [];
    for (let pane = field.closest('.tab-pane'); pane; pane = pane.parentElement && pane.parentElement.closest('.tab-pane')) {
        panes.unshift(pane);
    }
    panes.filter(function (pane) { return !pane.classList.contains('active') && pane.id; }).forEach(function (pane) {
        const selector = '[data-bs-toggle="tab"][data-bs-target="#' + CSS.escape(pane.id) + '"], [data-bs-toggle="tab"][href="#' + CSS.escape(pane.id) + '"]';
        // Same form first: the drawer and the page under it can reuse pane ids.
        const trigger = (field.form && field.form.querySelector(selector)) || document.querySelector(selector);
        if (trigger && window.bootstrap && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(trigger).show();
        }
    });
}, true);
