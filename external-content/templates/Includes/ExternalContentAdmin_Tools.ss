<div class="cms-content-tools fill-height cms-panel cms-panel-layout" data-expandonclick="true" data-layout-type="border" id="cms-content-tools-CMSMain" style="width: 300px;">
    <div class="cms-content-header north vertical-align-items">
        <div class="cms-content-header-info vertical-align-items fill-width">
            <div class="section-heading flexbox-area-grow">
                <span class="section-label"><a href="admin/external-content/">External Content</a></span>
            </div>
        </div>
    </div>
    <div class="panel panel--scrollable flexbox-area-grow fill-height cms-panel-content">
        <div class="panel panel--padded panel--scrollable flexbox-area-grow fill-height flexbox-displaypanel panel--padded panel--scrollable flexbox-area-grow fill-height flexbox-display cms-content-view cms-tree-view-sidebar cms-panel-deferred"
        data-url="$LinkTreeViewDeferred"
        data-url-treeview="$LinkTreeViewDeferred"
        data-url-listview="$LinkListViewDeferred"
        data-url-listviewroot="$LinkListViewRoot"
        data-no-ajax="<% if $TreeIsFiltered %>true<% else %>false<% end_if %>">
            <%-- Lazy-loaded via ajax --%>
        </div>
    </div>
</div>
