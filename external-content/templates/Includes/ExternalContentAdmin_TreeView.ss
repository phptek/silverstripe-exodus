<!-- Renders the CMS' central pane tree-view -->
<div id="cms-page-add-form" title="<%t SilverStripe\CMS\Controllers\CMSMain.AddNew 'Add new page' %>">
	$AddForm
</div>

<!-- All that's needed to keep the CMS' central pane happy -->
<div class="cms-tree flexbox-area-grow <% if $TreeIsFiltered %>filtered-list<% end_if %>">
</div>
