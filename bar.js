jQuery(document).ready(function() {
	new jQuery.ajax({
		url : barUrl,
		type : "post",
		data : barPost,
		success : function(request) {
			jQuery('#barresult').html(request);
		}
	});
});
