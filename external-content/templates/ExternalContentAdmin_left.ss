<h2><% _t('EXTERNAL_CONTENT.Connectors','Connectors') %></h2>
<div id="treepanes" style="overflow-y: auto;">
	<ul id="TreeActions">
		<li class="action" id="addpage"><button class="btn btn-primary cms-content-addpage-button tool-button font-icon-plus"><% _t('CREATE','Create',PR_HIGH) %></button></li>
		<li class="action" id="deletepage"><button class="btn btn-primary cms-content-addpage-button tool-button font-icon-plus"><% _t('DELETE', 'Delete') %></button></li>
	</ul>

	<% loop $CreateProviderForm %>
		<form class="actionparams" id="$FormName" action="$FormAction">
			<% loop $Fields %>
			$FieldHolder
			<% end_loop %>
		</form>
	<% end_loop %>

	$DeleteItemsForm
	<form class="actionparams" id="sortitems_options" style="display: none">
		<p id="sortitems_message" style="margin: 0"><% _t('TOREORG','To reorganise your folders, drag them around as desired.') %></p>
	</form>
	$SiteTreeAsUL
</div>
