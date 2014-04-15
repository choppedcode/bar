function barAdminButtonTransfer(userId) {
	var id=jQuery('#servicecontent [name="id"]').val();
	jQuery('div#modcmdbtns input[value="Backup and Restore"]').attr('onclick','window.location="addonmodules.php?module=bar&ac=pt&userid='+userId+'&id='+id+'"');
}
