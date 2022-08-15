<form $FormAttributes data-layout-type="border">
	<div class="panel panel--padded panel--scrollable flexbox-area-grow cms-content-fields ">
		<% if $Message %>
		<p id="{$FormName}_error" class="message $AlertType">$Message</p>
		<% else %>
		<p id="{$FormName}_error" class="message $AlertType" style="display: none"></p>
		<% end_if %>

		<fieldset>
			<% if $Legend %><legend>$Legend</legend><% end_if %>
			<% loop $Fields %>
				$FieldHolder
			<% end_loop %>
			<div class="clear"><!-- --></div>
		</fieldset>
	</div>
</form>
