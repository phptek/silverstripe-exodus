
<div id="pages-controller-cms-content" class="has-panel cms-content flexbox-area-grow fill-width fill-height $BaseCSSClasses" data-layout-type="border" data-pjax-fragment="Content" data-ignore-tab-state="true">
    <!-- The contents of the CMS' central column -->
    $Tools
    <div class="fill-height flexbox-area-grow">
        <div class="cms-content-header north">
            <div class="cms-content-header-info flexbox-area-grow vertical-align-items">
                <a href="$BreadcrumbsBackLink" class="btn btn-secondary btn--no-text font-icon-left-open-big hidden-lg-up toolbar__back-button"></a>
                <% include SilverStripe\\Admin\\CMSBreadcrumbs %>
            </div>
        </div>

        <div class="flexbox-area-grow fill-height">
            <!-- The contents of the CMS' RHS pane -->
            $EditForm
        </div>
    </div>
</div>
