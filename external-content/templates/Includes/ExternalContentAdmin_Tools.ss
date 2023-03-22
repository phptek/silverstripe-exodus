<!-- The templates used in the "Tools" tpl-variable in _Content.ss -->
<style type="text/css">
.nav ul, .nav ul li {
    list-style-type: none;
}
.nav li a {
    padding: 5px;
    width: 90%;
}
.nav a:hover {
    color: #999;
    text-decoration: none !important;
}
</style>
<div class="cms-content-tools fill-height cms-panel cms-panel-layout" data-expandonclick="true" data-layout-type="border" id="cms-content-tools-CMSMain" style="width: 300px;">
    <div class="cms-content-header north vertical-align-items">
        <div class="cms-content-header-info vertical-align-items fill-width">
            <div class="section-heading flexbox-area-grow">
                <span class="section-label"><a href="admin/migration/">Content Sources</a></span>
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
        <% if $ContentSources %>
        <div class="nav" style="position:absolute;top:200px;left:0;">
        <ul>
            <% loop $ContentSources %>
            <li <% if $ID = $Top.CurrentPageID %>class="current"<% end_if %>><a href="/admin/migration/?ID=$ID">$Name</a></li>
            <% end_loop %>
        </ul>
        </div>
        <% end_if %>
    </div>
</div>
