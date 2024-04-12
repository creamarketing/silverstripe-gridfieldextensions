<a class="btn btn-secondary btn--no-text btn--icon-large <% if $Toggle == 'open' %>font-icon-down-dir<% else %>font-icon-right-dir<% end_if %> cms-panel-link list-children-link" data-pjax-target="$PjaxFragment" href="$Link" data-toggle="$ToggleLink"></a>
<% if $Toggle == 'open' %>
	$NestedField
<% else %>
	<div class="nested-container" data-pjax-fragment="$PjaxFragment" style="display:none;"></div>
<% end_if %>