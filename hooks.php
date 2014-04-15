<?php
//error_reporting(E_ALL & ~E_NOTICE);ini_set('display_errors', '1');

function cpanel_AdminCustomButtonArray() {
	$buttonarray = array(
	 "Backup and Restore" => "transfer",
	);
	return $buttonarray;
}

function cpanel_transfer($params) {
	//Nothing happens here, this is now handled via a Javascript redirect following WHMCS v5.2 update
	$userid=intval($_GET['userid']);
	$id=intval($_GET['id']);
}

function bar_process($vars) {
	global $barNlc,$barEcho;
	$barNlc=md5(__DIR__.date('Ymd'));
	$barEcho=false;

	if (!function_exists('bar_vars')) require(dirname(__FILE__).'/bar.php');

	$query = mysql_query("SELECT * FROM `mod_bar` where `status` = 'backingup'");
	if (mysql_num_rows($query)) {
		while ($m = mysql_fetch_assoc($query)) {
			bar_message('check message');
			if (strstr($vars['message'],'_'.$m['username'].'.tar.gz') && strstr($vars['message'],'backup-') && strstr($vars['message'],'pkgacct')) {
				bar_message("Backup confirmation for ".$m['username'].': '.$vars['message'],'report');
				if ($m['to'] > 0) {
					$result=bar_restore($m['id'],$m['from'],$m['to'],bar_vars());
					if ($result['result'] == 1) {
						bar_message('Restore successful for '.$m['username'].': '.$result['output'],'report');
					} else {
						bar_message('Restore failed for '.$m['username'].': '.$result['output'],'report');
					}
				} else {
					bar_message('Backup completed for '.$m['username'].': '.$vars['message'],'report');
					bar_updatestatus($m['username'],'completed');
				}
			}
		}
	}
	return true;
}
function bar_processtest($vars) {
	logactivity('testing hook');

}
add_hook("TicketOpen",1,"bar_process");

function bar_clientareapage() {
	global $smarty,$barEcho;

	$barEcho=false;
	$tpl='';

	//temporary
	if (($_REQUEST['action']=='productdetails') && (($smarty->_tpl_vars['module']=='cpanel') || ($smarty->_tpl_vars['server']['type']=='cpanel'))) {
		//
		if (!function_exists('bar_restore')) require(dirname(__FILE__).'/bar.php');
		$vars=bar_vars();
		if ($vars['clientrestore']!='on') return;

		//load language file
		$language=strtolower($smarty->_tpl_vars['language']);
		$langFile=dirname(__FILE__).'/lang/'.strtolower($smarty->_tpl_vars['language']).'.php';
		if (file_exists($langFile)) require_once($langFile);
		else require_once(dirname(__FILE__).'/lang/english.php');

		//product ID
		$productId=$smarty->_tpl_vars['id'];
		//$module=$smarty['module'];
		//$module=$smarty['server']['type'];
		//echo "<pre>_tpl_vars: " . print_r($smarty->_tpl_vars,TRUE) . "</pre>";
		//	var_dump($smarty);
		//systemurl
		if ($_REQUEST['ac']=='pr') {
			$tpl.=$_ADDONLANG['Launching restore for']." ".$smarty->_tpl_vars['username'].'<br />';
			$tpl.='<script type="text/javascript">';
			$tpl.='var barUrl=\''.$smarty->_tpl_vars['systemurl'].'clientarea.php?action=productdetails\';';
			$tpl.='var barPost=\''.'&ac=er&id='.$productId.'\';';
			$tpl.='</script>';
			$tpl.='<script type="text/javascript" src="modules/addons/bar/bar.js"></script>';
			$tpl.='<div style="min-height:66px;" id="barresult"><img src="modules/addons/bar/loader.gif" /></div>';
		} elseif ($_REQUEST['ac']=='er') {
			$query = mysql_query("SELECT * FROM `tblhosting` where `id` = ".$productId);
			if (!mysql_num_rows($query) || !($s = mysql_fetch_assoc($query))) {
				$result['result']=false;
			} else {
				$result=bar_restore($productId,$s['server'],$s['server'],$vars);
			}
			if ($result['result']) echo $_ADDONLANG['clientarearestoresuccess'];
			else echo $_ADDONLANG['clientarearestorefail'];
			die();
		} else {
			$tpl.='<form method="post" action="'.$smarty->_tpl_vars['systemurl'].'clientarea.php?action=productdetails">';
			$tpl.='<input type="hidden" name="id" value="'.$productId.'">';
			$tpl.='<input type="hidden" name="ac" value="pr">';
			$tpl.='<input type="hidden" name="action" value="productdetails">';
			$tpl.='<input class="btn" type="submit" onclick="return confirm(\''.$_ADDONLANG['clientconfirm'].'\');" value="'.$_ADDONLANG['clientarearestorebackup'].'">';
			$tpl.='</form>';
		}
		$tpl.='<br />';
		$smarty->assign('moduleclientarea',$tpl.$smarty->_tpl_vars['moduleclientarea']);
	}
}
add_hook("ClientAreaPage",1,"bar_clientareapage");

function bar_header($vars) {
	if (isset($vars['filename']) && ($vars['filename']=='clientsservices')) {
		$content='';
		$content.='<script type="text/javascript" src="../modules/addons/bar/barbutton.js"></script>';
		$content.='<script type= "text/javascript">';
		$content.='jQuery(document).ready(function($) {';
		//$content.="var barId=jQuery('#oldpackageid').val();";
		//$content.='jQuery(\'div#modcmdbtns input[value="Backup and Restore"]\').attr(\'onclick\',\'window.location="addonmodules.php?module=bar&ac=pt&userid='.$_REQUEST['userid'].'&id='.$_REQUEST['id'].'"\');';
		$content.='barAdminButtonTransfer('.$_REQUEST['userid'].');';
		
		//$content.='jQuery(\'div#modcmdbtns input[value="Backup and Restore"]\').attr(\'onclick\',\'window.location="addonmodules.php?module=bar&ac=pt&userid='.$_REQUEST['userid'].'&id='.$_REQUEST['id'].'"\');';
		$content.='});';
		$content.='</script>';
		return $content;
	}
}

add_hook('AdminAreaHeaderOutput',1,'bar_header');
