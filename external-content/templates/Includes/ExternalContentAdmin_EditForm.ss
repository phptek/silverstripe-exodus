<!-- The Edit form located in the CMS' RHS pane -->
<form $FormAttributes data-layout-type="border">
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
    </fieldset>
    <div class="toolbar--south cms-content-actions cms-content-controls south" style="height: 32px;">
        <div class="btn-toolbar">
            <% if $Actions %>
            <% loop $Actions %>
            $FieldHolder
            <% end_loop %>
            <% end_if %>
        </div>
    </div>
</form>
