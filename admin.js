
/**
 * Only toggle all of the checkboxes in the containing table! This is fixed
 * in WordPress 2.9. See /wp-admin/js/common.dev.js line 218 of WP 2.8
 * @deprecated
 */
jQuery('thead :checkbox, tfoot :checkbox').click(function(e) {
	var $ = jQuery;
	var $checkboxes = $(this).closest('table').find('tbody .check-column :checkbox');
	if(this.checked)
		$checkboxes.attr('checked', 'checked');
	else
		$checkboxes.removeAttr('checked');
	e.stopImmediatePropagation();
});