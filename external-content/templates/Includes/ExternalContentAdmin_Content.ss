
<div id="pages-controller-cms-content" class="has-panel cms-content flexbox-area-grow fill-width fill-height $BaseCSSClasses" data-layout-type="border" data-pjax-fragment="Content" data-ignore-tab-state="true">
    <!-- The contents of the CMS' central column -->
    $Tools
    <div class="fill-height flexbox-area-grow">
        <div class="cms-content-header north">
            <div class="cms-content-header-info flexbox-area-grow vertical-align-items">
                <% include SilverStripe\\Admin\\CMSBreadcrumbs %>
            </div>
        </div>

        <div class="flexbox-area-grow fill-height">
            <div class="panel panel--padded panel--scrollable flexbox-area-grow cms-content-fields ">
            <!-- The contents of the CMS' RHS pane (See *_EditForm.ss) -->
            $EditForm
            </div>
        </div>
    </div>
</div>
