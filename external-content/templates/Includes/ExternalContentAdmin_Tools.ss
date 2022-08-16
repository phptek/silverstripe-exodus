<div class="cms-content-tools west cms-panel cms-panel-layout" data-layout-type="border" id="cms-content-tools-CMSMain">
	<div class="cms-panel-content center">
		<div class="panel panel--padded panel--scrollable flexbox-area-grow fill-height flexbox-display cms-content-view cms-tree-view-sidebar cms-panel-deferred" id="cms-content-treeview"
        data-url="$LinkTreeViewDeferred"
		data-url-treeview="$LinkTreeViewDeferred"
        data-url-listview="$LinkListViewDeferred"
        data-url-listviewroot="$LinkListViewRoot"
        data-no-ajax="<% if $TreeIsFiltered %>true<% else %>false<% end_if %>">
			<% if $TreeIsFiltered %>
				<% include SilverStripe\\CMS\\Controllers\\CMSMain_ListView %>
			<% else %>
				<%-- Lazy-loaded via ajax --%>
			<% end_if %>
		</div>
	</div>
	<div class="cms-panel-content-collapsed">
		<h3 class="cms-panel-header">Collections</h3>
	</div>
</div>
