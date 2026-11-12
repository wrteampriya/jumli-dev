"use strict";

function LangTrans(label) {
    // return window.languageLabels.hasOwnProperty(label) ? window.languageLabels[label] : label;
    return window?.languageLabels?.[label] || label;
}

function showErrorToast(message) {
    Toastify({
        text: message,
        duration: 6000,
        close: !0,
        style: {
            background: '#dc3545'
        }
    }).showToast();
}

function showSuccessToast(message) {
    Toastify({
        text: message,
        duration: 3000,
        close: !0,
        style: {
            background: "linear-gradient(to right, #00b09b, #96c93d)"
        }
    }).showToast();
}

function showWarningToast(message) {
    Toastify({
        text: message,
        duration: 6000,
        close: !0,
        style: {
            background: "linear-gradient(to right, #a7b000, #b08d00)"
        }
    }).showToast();
}

/**
 *
 * @param type
 * @param url
 * @param data
 * @param {function} beforeSendCallback
 * @param {function} successCallback - This function will be executed if no Error will occur
 * @param {function} errorCallback - This function will be executed if some error will occur
 * @param {function} finalCallback - This function will be executed after all the functions are executed
 * @param processData
 */
function ajaxRequest(type, url, data, beforeSendCallback = null, successCallback = null, errorCallback = null, finalCallback = null, processData = false) {
    // Modifying the data attribute here according to the type method
    if (!["get", "post"].includes(type.toLowerCase())) {
        if (data instanceof FormData) {
            data.append("_method", type);
        } else {
            data = {...data, "_method": type};
            data = JSON.stringify(data);
        }
        type = "POST";
    }
    $.ajax({
        type: type,
        url: url,
        data: data,
        cache: false,
        processData: processData,
        contentType: data instanceof FormData ? false : "application/json",
        dataType: 'json',
        beforeSend: function () {
            if (beforeSendCallback != null) {
                beforeSendCallback();
            }
        },
        success: function (data) {
            try {
                if (!data.error) {
                    if (successCallback != null) {
                        successCallback(data);
                    }
                } else {
                    if (errorCallback != null) {
                        errorCallback(data);
                    }
                }
            } finally {
                if (finalCallback != null) {
                    finalCallback(data);
                }
            }
        }, error: function (jqXHR) {
            if (jqXHR.responseJSON) {
                showErrorToast(jqXHR.responseJSON.message);
            }
            if (finalCallback != null) {
                finalCallback();
            }
        }
    })
}

function formAjaxRequest(type, url, data, formElement, submitButtonElement, successCallback = null, errorCallback = null) {
    // To Remove Red Border from the Validation tag.
    // formElement.find('.has-danger').removeClass("has-danger");
    // formElement.validate();

    let parsley = formElement.parsley({
        excluded: 'input[type=button], input[type=submit], input[type=reset], :hidden'
    });
    parsley.validate();
    if (parsley.isValid()) {
        let submitButtonText = submitButtonElement.val();

        function beforeSendCallback() {
            submitButtonElement.val('Please Wait...').attr('disabled', true);
        }

        function mainSuccessCallback(response) {
            if (response.warning) {
                showWarningToast(response.message);
            } else {
                showSuccessToast(response.message);
            }

            if (successCallback != null) {
                successCallback(response);
            }
        }

        function mainErrorCallback(response) {
            showErrorToast(response.message);
            if (errorCallback != null) {
                errorCallback(response);
            }
        }

        function finalCallback() {
            submitButtonElement.val(submitButtonText).attr('disabled', false);
        }


        ajaxRequest(type, url, data, beforeSendCallback, mainSuccessCallback, mainErrorCallback, finalCallback)
    }
}

function Select2SearchDesignTemplate(repo) {
    /**
     * This function is used in Select2 Searching Functionality
     */
    if (repo.loading) {
        return repo.text;
    }
    let $container;
    if (repo.id && repo.text) {
        $container = $(
            "<div class='select2-result-repository clearfix'>" +
            "<div class='select2-result-repository__title'></div>" +
            "</div>"
        );
        $container.find(".select2-result-repository__title").text(repo.text);
    } else {
        $container = $(
            "<div class='select2-result-repository clearfix'>" +
            "<div class='row'>" +
            "<div class='col-1 select2-result-repository__avatar' style='width:20px'>" +
            "<img src='" + repo.image + "' class='w-100' alt=''/>" +
            "</div>" +
            "<div class='col-10'>" +
            "<div class='select2-result-repository__title'></div>" +
            "<div class='select2-result-repository__description'></div>" +
            "</div>" +
            "</div>"
        );

        $container.find(".select2-result-repository__title").text(repo.first_name + " " + repo.last_name);
        $container.find(".select2-result-repository__description").text(repo.email);
    }

    return $container;
}

/**
 *
 * @param searchElement
 * @param searchUrl
 * @param {Object|null} data
 * @param {number} data.total_count
 * @param {string} data.email
 * @param {number} data.page
 * @param placeHolder
 * @param templateDesignEvent
 * @param onTemplateSelectEvent
 */
function select2Search(searchElement, searchUrl, data, placeHolder, templateDesignEvent, onTemplateSelectEvent) {
    //Select2 Ajax Searching Functionality function
    if (!data) {
        data = {};
    }
    $(searchElement).select2({
        tags: true,
        ajax: {
            url: searchUrl,
            dataType: 'json',
            delay: 250,
            cache: true,
            data: function (params) {
                data.email = params.term;
                data.page = params.page;
                return data;
            },
            processResults: function (data, params) {
                params.page = params.page || 1;
                return {
                    results: data.data,
                    pagination: {
                        more: (params.page * 30) < data.total_count
                    }
                };
            }
        },
        placeholder: placeHolder,
        minimumInputLength: 1,
        templateResult: templateDesignEvent,
        templateSelection: onTemplateSelectEvent,
    });
}

/**
 * @param {string} [url] - Ajax URL that will be called when the Confirm button will be clicked
 * @param {string} [method] - GET / POST / PUT / PATCH / DELETE
 * @param {Object} [options] - Options to Configure SweetAlert
 * @param {string} [options.title] - Are you sure
 * @param {string} [options.text] - You won't be able to revert this
 * @param {string} [options.icon] - 'warning'
 * @param {boolean} [options.showCancelButton] - true
 * @param {string} [options.confirmButtonColor] - '#3085d6'
 * @param {string} [options.cancelButtonColor] - '#d33'
 * @param {string} [options.confirmButtonText] - Confirm
 * @param {string} [options.cancelButtonText] - Cancel
 * @param {function} [options.successCallBack] - function()
 * @param {function} [options.errorCallBack] - function()
 * @param {function} [options.data] - FormData Object / Object
 */
function showSweetAlertConfirmPopup(url, method, options = {}) {
    let opt = {
        title: LangTrans("Are you sure"),
        text: LangTrans("You wont be able to revert this"),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: LangTrans("Confirm"),
        cancelButtonText: LangTrans("Cancel"),
        successCallBack: function () {
        },
        errorCallBack: function (response) {
        },
        ...options,
    }

    Swal.fire({
        title: opt.title,
        text: opt.text,
        icon: opt.icon,
        showCancelButton: opt.showCancelButton,
        confirmButtonColor: opt.showCancelButton,
        cancelButtonColor: opt.cancelButtonColor,
        confirmButtonText: opt.confirmButtonText,
        cancelButtonText: opt.cancelButtonText
    }).then((result) => {
        if (result.isConfirmed) {
            function successCallback(response) {
                showSuccessToast(response.message);
                opt.successCallBack(response);
            }

            function errorCallback(response) {
                showErrorToast(response.message);
                opt.errorCallBack(response);
            }

            ajaxRequest(method, url, options.data || null, null, successCallback, errorCallback);
        }
    })
}

/**
 *
 * @param {string} [url] - Ajax URL that will be called when the Delete will be successfully
 * @param {Object} [options] - Options to Configure SweetAlert
 * @param {string} [options.text] - "Are you sure?"
 * @param {string} [options.title] - "You won't be able to revert this!"
 * @param {string} [options.icon] - "warning"
 * @param {boolean} [options.showCancelButton] - true
 * @param {string} [options.confirmButtonColor] - "#3085d6"
 * @param {string} [options.cancelButtonColor] - "#d33"
 * @param {string} [options.confirmButtonText] - "Yes, delete it!"
 * @param {string} [options.cancelButtonText] - "Cancel"
 * @param {function} [options.successCallBack] - function()
 * @param {function} [options.errorCallBack] - function()
 * @param {function} [options.data] - FormData Object / Object
 */
function showDeletePopupModal(url, options = {}) {
    // console.log(windows.LangTrans("Are you sure"));
    // To Preserve OLD
    let opt = {
        title: window?.languageLabels["Are you sure"] || "Are you sure",
        text: window?.languageLabels["You wont be able to revert this"] || "You wont be able to revert this",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: window?.languageLabels["Yes Delete"] || "Yes Delete",
        cancelButtonText: window?.languageLabels["Cancel"] || "Cancel",
        successCallBack: function () {
        },
        errorCallBack: function (response) {
        },
        ...options,
    }
    showSweetAlertConfirmPopup(url, 'DELETE', opt);
}


/**
 *
 * @param {string} [url] - Ajax URL that will be called when the Delete will be successfully
 * @param {Object} [options] - Options to Configure SweetAlert
 * @param {string} [options.text] - "Are you sure?"
 * @param {string} [options.title] - "You won't be able to revert this!"
 * @param {string} [options.icon] - "warning"
 * @param {boolean} [options.showCancelButton] - true
 * @param {string} [options.confirmButtonColor] - "#3085d6"
 * @param {string} [options.cancelButtonColor] - "#d33"
 * @param {string} [options.confirmButtonText] - "Yes, delete it!"
 * @param {string} [options.cancelButtonText] - "Cancel"
 * @param {function} [options.successCallBack]
 * @param {function} [options.errorCallBack]
 */
function showRestorePopupModal(url, options = {}) {
    // To Preserve OLD
    let opt = {
        title: LangTrans("Are you sure"),
        text: LangTrans("You wont be able to revert this"),
        icon: 'success',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: LangTrans('Yes Restore it'),
        cancelButtonText: LangTrans('Cancel'),
        successCallBack: function () {
        },
        errorCallBack: function (response) {
        },
        ...options,
    }
    showSweetAlertConfirmPopup(url, 'PUT', opt);
}

/**
 *
 * @param {string} [url] - Ajax URL that will be called when the Delete will be successfully
 * @param {Object} [options] - Options to Configure SweetAlert
 * @param {string} [options.text] - "Are you sure?"
 * @param {string} [options.title] - "You won't be able to revert this!"
 * @param {string} [options.icon] - "warning"
 * @param {boolean} [options.showCancelButton] - true
 * @param {string} [options.confirmButtonColor] - "#3085d6"
 * @param {string} [options.cancelButtonColor] - "#d33"
 * @param {string} [options.confirmButtonText] - "Yes, delete it!"
 * @param {string} [options.cancelButtonText] - "Cancel"
 * @param {function} [options.successCallBack]
 * @param {function} [options.errorCallBack]
 */
function showPermanentlyDeletePopupModal(url, options = {}) {
    // To Preserve OLD
    let opt = {
        title: LangTrans("Are you sure"),
        text: LangTrans("You are about to Delete this data"),
        icon: 'error',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: LangTrans("Yes Delete Permanently"),
        cancelButtonText: LangTrans('Cancel'),
        successCallBack: function () {
        },
        errorCallBack: function (response) {
        },
        ...options,
    }
    showSweetAlertConfirmPopup(url, 'DELETE', opt);
}

/**
 * Calculate Discounted price based on the Price and Discount(%)
 * @param price
 * @param discount
 * @returns {string}
 */
function calculateDiscountedAmount(price, discount) {
    let finalPrice = price - (price * discount / 100);
    return finalPrice.toFixed(2);
}

/**
 * Calculate Discount(%)
 * @param price
 * @param discountedPrice
 * @returns {string}
 */
function calculateDiscount(price, discountedPrice) {
    let finalDiscount = 100 - discountedPrice * 100 / price;
    return finalDiscount.toFixed(2);
}

function generateSlug(text){
        // Remove non-English and non-number/dash/underscore characters, replace spaces with dash, to lower case
    return text
        .toLowerCase()
        .replace(/[^a-z0-9 -_]/g, '')      // Remove non-English chars except dash/underscore
        .replace(/\s+/g, '-')              // Replace spaces with dash
        .replace(/[-_]+/g, '-')            // Collapse multiple - or _
        .replace(/^-+|-+$/g, '');          // Remove leading/trailing dash
}

function showDeleteLanguagePopupModal(url, options = {}) {
    let opt = {
        title: LangTrans("Are you sure?"),
        text: LangTrans("Deleting this language will delete all the language files and translations associated with it."),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: LangTrans("Yes Delete"),
        cancelButtonText: LangTrans('Cancel'),
        successCallBack: function () {},
        errorCallBack: function (response) {},
        ...options,
    };
    showSweetAlertConfirmPopup(url, 'DELETE', opt);
}
// Rebuild admin category <select>s with one module's categories from the shared lookup
// (AdminLookupController::categories). Forms render their first module's categories server-side
// and mark each select with data-category-module="<id>"; this runs when the Module dropdown changes.
// - selects: element(s) / jQuery, e.g. several selects can share one request.
// - Placeholder options (value '') stay; selections still present after the reload are kept, and
//   'change' fires only when the value actually changed.
// - No request when every select already holds (or is loading) moduleId's categories. Only the
//   latest call fills a select, so a slow response for a module the user already left is dropped.
// - options.label(category, text, select) can decorate an option's text (e.g. item counts).
// - options.requireModule: no moduleId empties the selects instead of listing every module.
// Used by: Slider create, Banner Ad create/edit, Feature Section create/edit.
function loadCategoryOptionsForModule(selects, moduleId, options = {}) {
    const $selects = $(selects);
    moduleId = String(moduleId || '');
    const heldModule = el => String($(el).data('categoryModuleLoading') ?? $(el).attr('data-category-module') ?? '');
    if (!$selects.length || $selects.toArray().every(el => heldModule(el) === moduleId)) {
        return $.Deferred().resolve().promise();
    }

    const token = {};
    $selects.data({categoryModuleLoading: moduleId, categoryModuleToken: token});
    const fill = function (categories) {
        $selects.each(function () {
            const $select = $(this);
            if ($select.data('categoryModuleToken') !== token) return;
            $select.removeData('categoryModuleLoading');
            const before = $select.val();
            $select.find('option').filter((i, option) => option.value !== '').remove();
            $.each(categories, function (i, category) {
                let text = '\u00a0'.repeat(category.level * 4) + '|-- ' + category.name;
                if (options.label) text = options.label(category, text, $select[0]);
                $select.append(new Option(text, category.id));
            });
            $select.attr('data-category-module', moduleId);
            $select.val(before);
            if ($select.val() === null && !$select.prop('multiple')) $select.val('');
            if (JSON.stringify($select.val()) !== JSON.stringify(before)) $select.trigger('change');
        });
    };

    if (!moduleId && options.requireModule) {
        fill([]);
        return $.Deferred().resolve().promise();
    }

    return $.get(window.baseurl + 'lookup/categories', moduleId ? {module_id: moduleId} : {})
        .then(response => fill(response.data || []))
        .fail(() => $selects.each(function () {
            if ($(this).data('categoryModuleToken') === token) $(this).removeData('categoryModuleLoading');
        }));
}
