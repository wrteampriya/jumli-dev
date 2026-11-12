{{--
    Global side drawer for Add / Update forms.
    Open it from any link:  <a href="{{ route('x.create') }}" data-side-drawer
                               data-drawer-title="Add X" data-drawer-subtitle="...">
    The link's URL is fetched over AJAX and must return just the form partial.
    Static backdrop + no keyboard: it only closes from the × button.
--}}
<div class="offcanvas offcanvas-end side-drawer" tabindex="-1" id="sideDrawer"
     data-bs-backdrop="static" data-bs-keyboard="false" data-bs-scroll="false"
     aria-labelledby="sideDrawerTitle">
    <div class="offcanvas-header side-drawer-header">
        <div>
            <h5 class="offcanvas-title side-drawer-title" id="sideDrawerTitle"></h5>
            <p class="side-drawer-subtitle mb-0"></p>
        </div>
        <button type="button" class="btn-close side-drawer-close" data-bs-dismiss="offcanvas" aria-label="{{ __('Close') }}"></button>
    </div>
    <div class="offcanvas-body side-drawer-body"></div>
</div>
