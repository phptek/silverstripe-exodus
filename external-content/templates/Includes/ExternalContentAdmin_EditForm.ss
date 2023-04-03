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

    <div class="btn-toolbar" style="padding-top: 35px;">
        <div role="group" id="Form_EditForm_MajorActions_Holder" class="btn-group field CompositeField composite form-group--no-label">
            <% if $Actions %>
            <% loop $Actions %>
            $FieldHolder
            <% end_loop %>
            <% end_if %>
        </div>
    </div>
</form>
