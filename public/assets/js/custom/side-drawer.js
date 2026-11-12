/*
* Side drawer for Add / Update forms (markup: layouts/side-drawer.blade.php).
*
* Page forms: any element with [data-side-drawer] opens the drawer and loads
* its href (or data-drawer-url) over AJAX with an X-Side-Drawer header. The
* layout (layouts/main.blade.php) then renders just the page's content and
* scripts, so any regular create/edit page works without changes.
*
* Forms inside the drawer are submitted over AJAX (same X-Side-Drawer header);
* on success the list table is refreshed and the drawer closes. Otherwise it
* only closes from the × button. Action rows are pinned to the drawer bottom.
*
* Modal forms: add .modal-drawer to a .modal to show it as the same kind of
* right-side drawer (static backdrop, closes on success via common.js).
*/

"use strict";

const SideDrawer = (function () {
    let currentUrl = null;
    let currentOptions = {};
    // Global listeners the loaded page added, removed again on close/reload —
    // otherwise every open would stack another copy (e.g. double submits).
    let pageListeners = [];

    function $drawer() {
        return $('#sideDrawer');
    }

    function instance() {
        return bootstrap.Offcanvas.getOrCreateInstance($drawer()[0]);
    }

    function open(url, options = {}) {
        currentUrl = url;
        currentOptions = options;
        setHeader(options.title || '', options.subtitle || '');
        instance().show();
        load();
    }

    function setHeader(title, subtitle) {
        const $el = $drawer();
        $el.find('.side-drawer-title').text(title);
        $el.find('.side-drawer-subtitle').text(subtitle).toggle(!!subtitle);
    }

    function jqueryHandlers() {
        const handlers = new Set();
        [document, window, document.body].forEach(function (target) {
            const events = $._data(target, 'events') || {};
            Object.keys(events).forEach(function (type) {
                events[type].forEach(function (h) {
                    handlers.add(h);
                });
            });
        });
        return handlers;
    }

    function removePageListeners() {
        pageListeners.forEach(function (remove) {
            remove();
        });
        pageListeners = [];
    }

    function load() {
        removePageListeners();
        const $body = $drawer().find('.side-drawer-body');
        $body.html('<div class="side-drawer-loader"><div class="spinner-border text-primary" role="status"></div></div>');

        $.ajax({
            url: currentUrl,
            type: 'GET',
            dataType: 'html',
            cache: false,
            headers: {'X-Side-Drawer': '1'},
            success: function (html) {
                render($body, html);
            },
            error: function (jqXHR) {
                const message = (jqXHR.responseJSON && jqXHR.responseJSON.message) || 'Something went wrong';
                $body.html($('<div class="alert alert-danger m-3"></div>').text(message));
            }
        });
    }

    function render($body, html) {
        // Page scripts are written for a full page load: DOMContentLoaded /
        // window load listeners they add would never fire here, so run them
        // right after the markup and scripts are in place.
        const queued = [];
        const docAdd = document.addEventListener;
        const winAdd = window.addEventListener;
        function track(target, add, type, fn, opts) {
            add.call(target, type, fn, opts);
            pageListeners.push(function () {
                target.removeEventListener(type, fn, opts);
            });
        }
        const before = jqueryHandlers();
        document.addEventListener = function (type, fn, opts) {
            if (type === 'DOMContentLoaded') return queued.push(fn);
            return isPageListener(this, document, fn) ? track(document, docAdd, type, fn, opts) : docAdd.call(this, type, fn, opts);
        };
        window.addEventListener = function (type, fn, opts) {
            if (type === 'load' || type === 'DOMContentLoaded') return queued.push(fn);
            return isPageListener(this, window, fn) ? track(window, winAdd, type, fn, opts) : winAdd.call(this, type, fn, opts);
        };
        // jQuery's shared dispatcher must stay (its handlers are tracked via $._data below).
        function isPageListener(self, target, fn) {
            const jqData = $._data(target);
            return self === target && !(jqData && jqData.handle === fn);
        }
        try {
            // jQuery .html() also executes the page's inline and external scripts.
            $body.html(html);
            queued.forEach(function (fn) {
                try {
                    fn.call(document, new Event('DOMContentLoaded'));
                } catch (e) {
                    console.error(e);
                }
            });
        } finally {
            document.addEventListener = docAdd;
            window.addEventListener = winAdd;
        }

        // jQuery handlers the page bound on document / window / body. Collected in a ready
        // callback: since jQuery 3, $(document).ready(fn) runs async even on a loaded page,
        // so handlers the page binds inside its own ready callbacks only exist after them
        // (ready callbacks run in order, so this one runs last). If the drawer closed in
        // between, the next load() removes them before rendering.
        $(function () {
            [document, window, document.body].forEach(function (target) {
                const events = $._data(target, 'events') || {};
                Object.keys(events).forEach(function (type) {
                    events[type].forEach(function (h) {
                        if (before.has(h)) return;
                        const eventType = h.origType + (h.namespace ? '.' + h.namespace : '');
                        pageListeners.push(function () {
                            $(target).off(eventType, h.selector || undefined, h.handler);
                        });
                    });
                });
            });
        });

        // Title from the trigger, else the page's own @section('title').
        if (!currentOptions.title) {
            const pageTitle = $.trim($body.find('template.side-drawer-meta').data('title') || '');
            setHeader(pageTitle, currentOptions.subtitle || '');
        }

        // A card header repeating the drawer title is just noise.
        const drawerTitle = $.trim($drawer().find('.side-drawer-title').text()).toLowerCase();
        $body.find('.card-header').filter(function () {
            return $.trim($(this).text()).toLowerCase() === drawerTitle;
        }).addClass('d-none');

        // "Back to list" links point at the page the drawer is open over.
        $body.find('a.btn').filter(function () {
            return this.pathname === window.location.pathname && !this.hash;
        }).addClass('d-none');

        promoteActions($body);
        initPlugins($body);

        // Label to put back after a submit. Read now: page scripts may relabel the button
        // (e.g. "Saving...") before the drawer's submit handler below runs.
        $body.find(':submit').each(function () {
            $(this).data('drawerLabel', $(this).is('input') ? $(this).val() : $(this).html());
        });

        // Wizard steps may inject their nav rows later (e.g. item custom fields).
        let queuedPromote = false;
        const observer = new MutationObserver(function () {
            if (queuedPromote) return;
            queuedPromote = true;
            requestAnimationFrame(function () {
                queuedPromote = false;
                promoteActions($body);
            });
        });
        observer.observe($body[0], {childList: true, subtree: true});
        pageListeners.push(function () {
            observer.disconnect();
        });
    }

    // Action rows (the form's last submit + wizard Previous/Next/Continue
    // rows) are pinned to the drawer's bottom edge by .side-drawer .form-actions.
    // They stay where they are in the DOM, so submit buttons remain in their
    // form and wizard steps keep showing only their own row.
    function promoteActions($body) {
        $body.find('form').not('.modal form').each(function () {
            const $form = $(this);
            const $buttons = $form.find(':submit').last()
                .add($form.find('.btn-prev-tab, .btn-next-tab, .btn-previous, .btn-continue'));
            $buttons.parent().not('.modal *').each(function () {
                const $row = $(this);
                // Buttons only (empty spacer divs allowed) — not a field group.
                const others = $row.children().not(':submit, button, a.btn').filter(function () {
                    return this.children.length || $.trim(this.textContent);
                });
                if ($row.is($form) || others.length) return;
                $row.addClass('form-actions');
            });
        });
    }

    // Plugins the page normally initializes once on load, for the new markup.
    function initPlugins($scope) {
        const $el = $drawer();

        $scope.find('select.select2').each(function () {
            // Page scripts may set up their own select2 (e.g. allowClear) — leave those.
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({dropdownParent: $el});
            }
        });

        if (typeof Tagify !== 'undefined') {
            // Tagify copies the input's classes onto its <tags> wrapper — match inputs only.
            $scope.find('input.tagify-input, textarea.tagify-input').not('.tagify-applied').each(function () {
                // Page scripts may have created their own instance already.
                if (this.__tagify || $(this).prev('tags.tagify').length) return;
                new Tagify(this, {delimiters: ",", editTags: true, duplicate: false, dropdown: {enabled: 0}});
                this.classList.add('tagify-applied');
            });
        }

        // Initialized FilePond inputs are replaced by a .filepond--root wrapper.
        if ($.fn.filepond && window.filePondOptions) {
            $scope.find('input[type="file"].filepond').filepond(window.filePondOptions);
        }

        $scope.find('[data-bs-toggle="tooltip"]').tooltip();

        // bootstrap-table's data-toggle auto-init only runs on page load.
        $scope.find('table[data-toggle="table"]').each(function () {
            if (!$(this).data('bootstrap.table')) {
                $(this).bootstrapTable();
            }
        });

        if (typeof initPermissionTree === 'function') {
            initPermissionTree($scope);
        }
    }

    function close() {
        instance().hide();
    }

    // For page scripts with their own submit handling: after a successful
    // save, refresh the list and close the drawer instead of redirecting.
    // Returns false when the form isn't in the drawer (full page).
    function finish(form) {
        if (!$(form).closest('#sideDrawer').length) return false;
        // $('#table_list').first().bootstrapTable('refresh');
        
        // Pages whose list table has its own id (e.g. #popular_categories_table) refresh that instead.
        const $list = $('#table_list').length ? $('#table_list').first()
            : $('table[data-toggle="table"]').not('#sideDrawer table');
        $list.bootstrapTable('refresh');
        close();
        return true;
    }

    return {open, load, close, finish, removePageListeners};
})();
window.SideDrawer = SideDrawer;

$(document).on('click', '[data-side-drawer]', function (e) {
    e.preventDefault();
    const $trigger = $(this);
    // Tooltips on table action buttons would otherwise stay stuck on screen.
    $trigger.tooltip('hide');
    SideDrawer.open($trigger.data('drawer-url') || $trigger.attr('href'), {
        title: $trigger.data('drawer-title'),
        subtitle: $trigger.data('drawer-subtitle'),
    });
});

// Hidden-input mirror for switches (custom.js binds these only on page load).
$(document).on('change', '#sideDrawer .status-switch', function () {
    $(this).siblings('input[type="hidden"]').val($(this).is(':checked') ? 1 : 0);
});

/*
* Result of a drawer form submit. Controllers answer either with JSON
* ({error, message}) or — for plain redirect()->with(...) responses — with the
* redirected page, which the layout renders in drawer mode along with the
* flashed messages (see layouts/main.blade.php).
*/
function sideDrawerParseResult(body, jqXHR) {
    const contentType = jqXHR.getResponseHeader('Content-Type') || '';
    if (contentType.indexOf('json') !== -1) {
        const data = typeof body === 'string' ? JSON.parse(body) : body;
        return {ok: !data.error, warning: !!data.warning, message: data.message, data: data};
    }
    const $flash = $('<div>').append($.parseHTML(body)).find('template.side-drawer-flash');
    const errors = $flash.data('errors') || [];
    if ($flash.data('error') || errors.length) {
        return {ok: false, message: $flash.data('error') || errors[0]};
    }
    return {ok: true, message: $flash.data('success') || '', data: {}};
}

// Bound on window so it runs after every page handler (jQuery ones included,
// they sit on document) and can step aside when a page script took over.
window.addEventListener('submit', function (e) {
    const form = e.target;
    if (e.defaultPrevented || !form.closest || !form.closest('#sideDrawer')) return;
    e.preventDefault();

    const $form = $(form);
    const $submit = $(form.elements).filter(':submit');

    const parsley = $form.parsley({
        excluded: 'input[type=button], input[type=submit], input[type=reset], :hidden'
    });
    if (!parsley.validate()) return;

    const preSubmitFunction = $form.data('pre-submit-function');
    if (preSubmitFunction && eval(preSubmitFunction + "()") === false) return;
    // data-success-function is not called: on these pages it only sends the
    // browser back to the list, which the drawer already shows.

    const submitLabel = $submit.data('drawerLabel') ?? ($submit.is('input') ? $submit.val() : $submit.html());
    $submit.prop('disabled', true);
    $submit.is('input') ? $submit.val(window?.languageLabels?.["Please Wait..."] || 'Please Wait...') : null;

    const data = new FormData(form);
    // Same as common.js's .edit-form handler.
    if ($form.hasClass('edit-form') && !data.has('_method')) {
        data.append('_method', 'PUT');
    }
    // A form in a dialog of the page shown in the drawer (e.g. custom field
    // values): close just that dialog and refresh the page's own tables.
    const $innerModal = $form.closest('#sideDrawer .modal');

    $.ajax({
        url: $form.attr('action'),
        type: 'POST',
        data: data,
        processData: false,
        contentType: false,
        dataType: 'text',
        headers: {'X-Side-Drawer': '1', 'Accept': 'application/json, text/html'},
        success: function (body, textStatus, jqXHR) {
            let result;
            try {
                result = sideDrawerParseResult(body, jqXHR);
            } catch (err) {
                result = {ok: false, message: 'Something went wrong'};
            }
            if (!result.ok) {
                showErrorToast(result.message || 'Something went wrong');
                return;
            }
            if (result.message) {
                result.warning ? showWarningToast(result.message) : showSuccessToast(result.message);
            }
            // Saves that change page-wide UI (e.g. the language switcher) reload the page.
            if ($form.is('[data-drawer-reload]')) {
                SideDrawer.close();
                setTimeout(function () {
                    window.location.reload();
                }, 500);
                return;
            }
            if ($innerModal.length) {
                form.reset();
                $innerModal.modal('hide');
                $('#sideDrawer table[data-toggle="table"]').bootstrapTable('refresh');
                return;
            }
            SideDrawer.finish(form);
        },
        error: function (jqXHR) {
            let message = 'Something went wrong';
            try {
                const data = JSON.parse(jqXHR.responseText);
                message = data.message || message;
            } catch (err) {
                // non-JSON error page
            }
            showErrorToast(message);
        },
        complete: function () {
            $submit.prop('disabled', false);
            $submit.is('input') ? $submit.val(submitLabel) : $submit.html(submitLabel);
        }
    });
});

// Drop the loaded form once closed so stale state never flashes on next open.
$(document).on('hidden.bs.offcanvas', '#sideDrawer', function () {
    SideDrawer.removePageListeners();
    $(this).find('.side-drawer-body').empty();
});

// Form modals marked .modal-drawer slide in from the right and, like the
// drawer, only close from their × / close button.
$(function () {
    $('.modal.modal-drawer').attr({'data-bs-backdrop': 'static', 'data-bs-keyboard': 'false'});
});
