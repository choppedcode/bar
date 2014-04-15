<?php

if (!defined("WHMCS"))
die("This file cannot be accessed directly");

function bar_config() {
	$configarray = array(
    "name" => "Backup & Restore",
	"description" => "Adds the possibility to backup, restore and transfer cPanel accounts from the comfort of your WHMCS admin panel.",
    "version" => "1.4.0",
    "author" => "ChoppedCode",
    "language" => "english",
    "fields" => array(
        "dest" => array("FriendlyName" => "Protocol", "Type" => "dropdown", "Options" => "homedir,ftp,scp,passiveftp", "Description" => "The transfer protocol or destination for the backup."),
	    "port" => array ("FriendlyName" => "Port", "Type" => "text", "Size" => "5", "Description" => "The port to be used during the transfer."),
		"rdir" => array("FriendlyName" => "Directory", "Type" => "text", "Size" => "50", "Description" => "The directory on the remote machine that will store the new backup."),
		"user" => array ("FriendlyName" => "User name", "Type" => "text", "Size" => "25", "Description" => "The username to use to log into the remote location."),
        "pass" => array ("FriendlyName" => "Password", "Type" => "password", "Size" => "25", "Description" => "The password required to access the remote location."),
	    "email" => array ("FriendlyName" => "Email", "Type" => "text", "Size" => "50", "Description" => "Will receive a confirmation email when the backup is complete."),
	    "clientrestore" => array ("FriendlyName" => "Client restore", "Type" => "yesno", "Description" => "Allow clients to restore their latest backup."),
	));
	return $configarray;
}

function bar_vars() {
	$c=bar_config();
	foreach ($c['fields'] as $field => $data) {
		$vars[$field]=bar_get_addon($field);
	}
	return $vars;
}

function bar_upgrade($vars) {

}

function bar_activate() {
	# Create Custom DB Table
	$query = "CREATE TABLE `mod_bar` (`username` VARCHAR( 12 ) NOT NULL PRIMARY KEY , `status` varchar(12), `lastupdate` TIMESTAMP, `backup` varchar(50), `from` int(10), `to` int(10), `id` int(10) )";
	$result = mysql_query($query);
}

function bar_deactivate() {
	# Remove Custom DB Table
	$query = "DROP TABLE `mod_bar`";
	$result = mysql_query($query);
}

function bar_checkDB($alert=true) {
	$query = mysql_query("SHOW TABLES LIKE 'mod_bar'");
	if (!mysql_fetch_assoc($query)) {
		if ($alert) bar_message('The required database table has not been created at activation. Check the permissions of your MySQL user, it should have CREATE permissions.');
		return false;
	} else return true;
}

function bar_output($vars) {
	if (!bar_checkDB()) return false;

	$modulelink = $vars['modulelink'];
	$version = $vars['version'];
	$LANG = $vars['_lang'];

	$id=intval($_REQUEST['id']);
	$userid=intval($_REQUEST['userid']);

	if ($id) {
		$query = mysql_query("SELECT * FROM `tblhosting` where `id` = ".$id);
		if (!mysql_num_rows($query) || !($u = mysql_fetch_assoc($query))) {
			bar_message('An undefined error occured x02');
			return false;
		}
	}

	bar_getstatus($u['username'],$status,$from,$to);

	if (!($toserver=intval($_REQUEST['toserver']))) $toserver=$to;

	if ($toserver) {
		$query = mysql_query("SELECT * FROM `tblservers` where `type` = 'cpanel' and `id` = ".$toserver);
		if (!mysql_num_rows($query) || !($n = mysql_fetch_assoc($query))) {
			bar_message('An undefined error occured x01');
			return false;
		}
	}

	if (!($fromserver=intval($_REQUEST['fromserver']))) $fromserver=$from;
	if (!$fromserver) $fromserver=$u['server'];

	if ($fromserver) {
		$query = mysql_query("SELECT * FROM `tblservers` where `type` = 'cpanel' and `id` = ".$fromserver);
		if (!mysql_num_rows($query) || !($s = mysql_fetch_assoc($query))) {
			bar_message('An undefined error occured x03');
			return false;
		}
	}

	$action=$_REQUEST['ac'];

	if ($action == 'et') { //execute transfer
		//bar_getlastbackup($s,$u);
		//die($_REQUEST['dotransfer'].':'.$fromserver.'->'.$toserver);
		$query = mysql_query("SELECT * FROM `tblhosting` where `id` = ".$id);
		if (!mysql_num_rows($query) || !($u = mysql_fetch_assoc($query))) {
			echo 'Couldn\'t retrieve cPanel username';
		} else {
			if (isset($_REQUEST['dotransfer'])) {
				echo "Launching transfer for ".$u['username'].'<br />';
				bar_backup($id,$fromserver,$toserver,$vars);
			} elseif (isset($_REQUEST['doreset'])) {
				echo "Reset done for ".$u['username'].'<br />';
				bar_updatestatus($u['username'],'');
			} elseif (isset($_REQUEST['dorestore'])) {
				echo "Launching restore for ".$u['username'].'<br />';
				$result=bar_restore($id,$fromserver,$toserver,$vars);
				if ($result['output']) bar_message('The restore generated the following output:<div style="padding:10px;background-color:#F5F5F5;font-size:smaller;">'.$result['output'].'</div>');

			} elseif (isset($_REQUEST['dobackup'])) {
				echo "Launching backup for ".$u['username'].'<br />';
				bar_backup($id,$fromserver,0,$vars);
			} elseif ($status=='backingup') {
				list($backup,$time)=bar_getlastbackup($s,$u);
				echo "A backup is currently running for ".$u['username'].':'.$backup.'<br />';
			} else {
				echo "A process is currently running for ".$u['username'].':'.$status.'<br />';
			}
		}
	} elseif ($action == 'pt') { //prepare transfer
		echo '<form method="post" action="addonmodules.php?module=bar">';
		echo '<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">';
		echo '<tr><td class="fieldlabel" width="20%">Client</td><td class="fieldarea">'.$userid.'</td><td></td></tr>';
		echo '<tr><td class="fieldlabel" width="20%">Product</td><td class="fieldarea">'.$u['domain'].' ('.$id.')</td><td></td></tr>';
		if ($status && $status != 'completed') echo '<tr><td class="fieldlabel" width="20%">Status</td><td class="fieldarea">'.$LANG[$status].'</td><td></td></tr>';
		echo '<tr><td class="fieldlabel" width="20%">Origin server</td><td class="fieldarea">';
		echo '<select name="fromserver">';
		$servers = mysql_query("SELECT `id`,`name`,`type`,`ipaddress` FROM `tblservers` where `type` = 'cpanel'");
		if (mysql_num_rows($servers)) {
			while ($s = mysql_fetch_assoc($servers)) {
				if ($s['id'] == $fromserver) $selected='selected="selected"';
				else $selected='';
				echo ' <option value="'.$s['id'].'"'.$selected.'>'.$s['name'].' ('.$s['ipaddress'].')</option>'."\r\n";
			}
		}
		echo '</select></td><td style="font-size:smaller">This is the server from where the backup is taken in case of a simple backup as well as for a transfer.</td></tr>';
		echo '<tr><td class="fieldlabel" width="20%">Destination server</td><td class="fieldarea">';
		echo '<select name="toserver">';
		$servers = mysql_query("SELECT `id`,`name`,`type`,`ipaddress` FROM `tblservers` where `type` = 'cpanel'");
		if (mysql_num_rows($servers)) {
			while ($s = mysql_fetch_assoc($servers)) {
				if (($toserver && ($s['id'] == $toserver)) || ($fromserver && ($s['id'] == $fromserver))) $selected='selected="selected"';
				else $selected='';
				echo ' <option value="'.$s['id'].'"'.$selected.'>'.$s['name'].' ('.$s['ipaddress'].')</option>'."\r\n";
			}
		}
		echo '</select>';
		echo '</td><td style="font-size:smaller">This is the server where the backup will be restored, in case of a simple restore as well as in case of a transfer.</td></tr>';
		echo '</table>';
		echo '<input type="hidden" name="userid" value="'.$userid.'">';
		echo '<input type="hidden" name="id" value="'.$id.'">';
		echo '<input type="hidden" name="ac" value="et">';
		echo '<div align="center">';
		if ($status == '' || $status == 'completed') {
			echo '<input type="submit" name="dobackup" value="Backup" class="button">';
		}
		if ($status == '' || $status == 'completed' || $status == 'restorefail'  || ($status == 'backingup' && $to)) {
			echo '<input type="submit" name="dorestore" value="Restore" class="button">';
		}
		if ($status == '' || $status == 'completed' || $status == 'restorefail') {
			echo '<input type="submit" name="dotransfer" value="Transfer" class="button">';
		}
		if ($status && $status != 'completed') echo '<input type="submit" name="doreset" value="Reset" class="button">';
		echo '</div>';
		echo '</form>';
	} else {
		echo '<ul>';
		echo bar_getprocesses($vars,false);
		echo '</ul>';
	}
	echo '<hr /><p>'.$LANG['intro'].'</p><p>'.$LANG['description'].'</p><p>'.$LANG['documentation'].'</p>';
}

function bar_sidebar($vars) {
	if (!bar_checkDB(false)) return false;

	$modulelink = $vars['modulelink'];
	$version = $vars['version'];
	$LANG = $vars['_lang'];

	$sidebar = '<span class="header"><img src="images/icons/addonmodules.png" class="absmiddle" width="16" height="16" /> Backup & Restore</span>
<ul class="menu">';
	$sidebar.='<li><a href="#">Version: '.$version.'</a></li>';
	$sidebar.=bar_getprocesses($vars,true);
	$sidebar.='</ul>';
	return $sidebar;
}

function bar_getprocesses($vars,$sidebar) {
	$LANG = $vars['_lang'];
	if ($sidebar) $query = mysql_query("SELECT * FROM `mod_bar` where (`status` <> 'completed' and `status` <> '') order by `lastupdate` desc");
	else $query = mysql_query("SELECT * FROM `mod_bar` where (`status` <> 'completed' and `status` <> '') or (`status` = 'completed' and DATE_SUB(CURDATE(),INTERVAL 24 HOUR) <= `lastupdate`) order by `lastupdate` desc");
	if (mysql_num_rows($query)) {
		while ($m = mysql_fetch_assoc($query)) {
			$query2 = mysql_query("SELECT `userid` FROM `tblhosting` where `id` = ".$m['id']);
			if (!mysql_num_rows($query2) || !($u = mysql_fetch_assoc($query2))) {
				bar_message('An undefined error occured x04');
				return false;
			}

			$output.='<li>';
			if ($sidebar) {
				$output.='<a href="addonmodules.php?module=bar&ac=pt&id='.$m['id'].'&userid='.$u['userid'].'">'.$m['username'].'-'.$LANG[$m['status']].'</a>';
			} else {
				$output.='<a href="clientshosting.php?hostingid='.$m['id'].'">'.$m['username'].'</a> - ';
				$output.='<a href="addonmodules.php?module=bar&ac=pt&id='.$m['id'].'&userid='.$u['userid'].'">'.$LANG[$m['status']].'</a>';
			}
			if (!$sidebar) $output.=' - '.$m['lastupdate'];
			if (!$sidebar && $m['status'] != 'completed') $output.='&nbsp<a href="addonmodules.php?module=bar&doreset=on&ac=et&userid='.$u['userid'].'&id='.$m['id'].'"><img src="images/delete.gif" /></a>';
			$output.='</li>';
		}
	}
	return $output;

}
function bar_restore($id,$fromserver,$toserver,$vars) {
	$query = mysql_query("SELECT * FROM `tblservers` where `type` = 'cpanel' and `id` = ".$toserver);
	if (!mysql_num_rows($query) || !($n = mysql_fetch_assoc($query))) {
		bar_message('An undefined error occured x05');
		return false;
	}

	$query = mysql_query("SELECT * FROM `tblservers` where `type` = 'cpanel' and `id` = ".$fromserver);
	if (!mysql_num_rows($query) || !($s = mysql_fetch_assoc($query))) {
		bar_message('An undefined error occured x06');
		return false;
	}

	$query = mysql_query("SELECT * FROM `tblhosting` where `id` = ".$id);
	if (!mysql_num_rows($query) || !($u = mysql_fetch_assoc($query))) {
		bar_message('An undefined error occured x07');
		return false;
	}

	bar_updatestatus($u['username'],'restoring');

	if (($vars['dest']=='homedir') || bar_rename($n['ipaddress'],$vars['user'],$vars['pass'],$u['username'],$vars['rdir'])) {
		bar_message('Check account exists '.$u['username']);
		$result=bar_apicall2($n,$u,'accountsummary?user='.urlencode($u['username']));
		if ($result['result']==0) $all=1;
		else $all=0;
		bar_message('Attempt a restore for '.$u['username']);
		$result=bar_apicall2($n,$u,'restoreaccount?user='.urlencode($u['username']).'&all='.$all.'&type=daily&mail=1&mysql=1&subs=1');
		if ($result['result'] == 1) {
			bar_resetdns($id,$toserver);
			bar_updatestatus($u['username'],'completed');
			$query = "UPDATE `tblhosting` set `server`='".$toserver."' where `id` = ".$id;
			if (!mysql_query($query)) {
				bar_message('Error updating the server id for '.$u['username'],'error');
			}
		} else {
			bar_updatestatus($u['username'],'restorefail');
		}
	} else {
		$result['result']=0;
		bar_updatestatus($u['username'],'restorefail');
	}
	return $result;
}

function bar_resetdns($id,$toserver) {
	$query = mysql_query("SELECT * FROM `tblservers` where `type` = 'cpanel' and `id` = ".$toserver);
	if (!mysql_num_rows($query) || !($n = mysql_fetch_assoc($query))) {
		bar_message('An undefined error occured x08');
		return false;
	}

	$query = mysql_query("SELECT * FROM `tblhosting` where `id` = ".$id);
	if (!mysql_num_rows($query) || !($u = mysql_fetch_assoc($query))) {
		bar_message('An undefined error occured x09');
		return false;
	}

	bar_message('Resetting DNS zone for '.$u['domain']);
	$apicall='cpanel_xmlapi_module=ZoneEdit&cpanel_xmlapi_func=resetzone&domain='.$u['domain'];
	bar_apicall1($n,$u,$apicall,2,false);

	bar_message('Syncing DNS zone for '.$u['domain']);
	$apicall='cpanel_xmlapi_module=ZoneEdit&cpanel_xmlapi_func=edit_zone_record&domain='.$u['domain'];
	bar_apicall1($n,$u,$apicall,2,false);

}

function bar_rename($ftp_server,$ftp_user_name,$ftp_user_pass,$username,$rdir) {
	// set up basic connection
	if ($conn_id = ftp_connect($ftp_server)) {

		// login with username and password
		if ($login_result = @ftp_login($conn_id, $ftp_user_name, $ftp_user_pass)) {

			// get contents of the current directory
			$files = ftp_nlist($conn_id, $rdir);

			// output $contents
			$maxTime='';
			foreach ($files as $file) {
				if (strstr($file,'.tar.gz') && strstr($file,'backup-') && strstr($file,$username)) {
					$tmp=explode('_',$file);
					$tmp[0]=str_replace('backup-','',$tmp[0]);
					list($MM,$dd,$yyyy)=explode('.',$tmp[0]);
					list($hh,$mm,$ss)=explode('-',$tmp[1]);
					$time=strtotime($yyyy.'-'.$MM.'-'.$dd.' '.$hh.':'.$mm.':'.$ss);

					if ($time > $maxTime) {
						$maxTime=$time;
						$backup=$file;
					}
				}
			}
		} else {
			bar_message('Login failed to '.$ftp_server,'error');
			ftp_close($conn_id);
			return false;
		}

		if ($backup) {
			// try to rename $old_file to $new_file
			if (ftp_rename($conn_id, $rdir.$backup, $rdir.$username.'.tar.gz')) {
				bar_message("Successfully renamed $rdir$backup to $rdir$username.tar.gz");
				ftp_close($conn_id);
				return true;
			} else {
				bar_message("There was a problem while renaming $rdir$backup to $rdir$username.tar.gz");
				bar_updatestatus($username,'restorefail');
				ftp_close($conn_id);
				return false;
			}
		} else {
			bar_message("No backup file found for user $username");
			bar_updatestatus($username,'restorefail');
			ftp_close($conn_id);
			return false;
		}

		// close the connection
		ftp_close($conn_id);
	} else {
		bar_message("Can't connect to FTP server at $ftp_server");
		return false;
	}

}
function bar_backup($id,$fromserver,$toserver,$vars) {
	if ($toserver) {
		$query = mysql_query("SELECT * FROM `tblservers` where `type` = 'cpanel' and `id` = ".$toserver);
		if (!mysql_num_rows($query) || !($n = mysql_fetch_assoc($query))) {
			bar_message('An undefined error occured x10');
			return false;
		}
	}

	$query = mysql_query("SELECT * FROM `tblservers` where `type` = 'cpanel' and `id` = ".$fromserver);
	if (!mysql_num_rows($query) || !($s = mysql_fetch_assoc($query))) {
		bar_message('An undefined error occured x11');
		return false;
	}

	$query = mysql_query("SELECT * FROM `tblhosting` where `id` = ".$id);
	if (!mysql_num_rows($query) || !($u = mysql_fetch_assoc($query))) {
		bar_message('An undefined error occured x12');
		return false;
	}

	$apicall='cpanel_xmlapi_module=Fileman&cpanel_xmlapi_func=fullbackup';
	if ($vars['email']) $apicall.='&arg-4='.urlencode($vars['email']);
	if ($toserver) {
		if ($vars['dest']) $apicall.='&arg-0='.urlencode($vars['dest']);
		if ($vars['dest'] && $vars['dest'] != 'homedir')  {
			$apicall.='&arg-1='.urlencode($n['ipaddress']);
			if ($vars['user']) $apicall.='&arg-2='.urlencode($vars['user']);
			else $apicall.='&arg-2='.urlencode($u['username']);
			if ($vars['pass']) $apicall.='&arg-3='.urlencode($vars['pass']);
			else $apicall.='&arg-3='.urlencode($u['password']);
			if ($vars['port']) $apicall.='&arg-5='.urlencode($vars['port']);
			if ($vars['rdir']) $apicall.='&arg-6='.urlencode($vars['rdir']);
		}
	} else {
		$apicall.='&arg-0=homedir';
	}

	if (bar_apicall1($s,$u,$apicall,1,false)) {
		sleep(2);
		list($backup,$time)=bar_getlastbackup($s,$u);
		bar_set($u['username'],'backingup',$backup,$fromserver,$toserver,$id);
	}
}

function bar_getlastbackup($s,$u) {
	$result=bar_apicall1($s,$u,'cpanel_xmlapi_module=Backups&cpanel_xmlapi_func=listfullbackups',2,false);
	if (count($result) && is_array($result) > 0) {
		$time=0;
		$files=array();
		foreach ($result as $f) {
			$files[$f['time']]=$f;
			if ($f['time'] > $time) {
				$time=$f['time'];
				$lastBackupName=$f['file'];
				$lastBackupStatus=$f['status'];
				$lastBackupTime=date('Y-m-d H:i:s',$time);
			}
		}
		return array($lastBackupName,$lastBackupTime);
	} else {
		return array('','','');
	}
}

function bar_set($username,$status,$backup,$from,$to,$id) {
	$query = mysql_query("SELECT * FROM `mod_bar` where `username` = '".$username."'");
	if (mysql_num_rows($query) && ($m = mysql_fetch_assoc($query))) {
		$query=sprintf("UPDATE `mod_bar` SET `status`='%s',`backup`='%s',`from`=%s,`to`=%s,`id`=%s WHERE `username`='%s'",$status,$backup,$from,$to,$id,$username);
		mysql_query($query);
	} else {
		$query=sprintf("INSERT INTO `mod_bar` (`username`,`status`,`backup`,`from`,`to`,`id`) VALUES ('%s','%s','%s',%s,%s,%s)",$username,$status,$backup,$from,$to,$id);
		mysql_query($query);
	}
}

function bar_updatestatus($username,$status) {
	$query=sprintf("UPDATE `mod_bar` SET `status`='%s' WHERE `username`='%s'",$status,$username);
	bar_message('Update status to '.$status.' for '.$username,'report');
	mysql_query($query);
}

function bar_getstatus($username,&$status,&$from='',&$to='') {
	$query = mysql_query("SELECT * FROM `mod_bar` where `username` = '".$username."'");
	if (mysql_num_rows($query) && ($m = mysql_fetch_assoc($query))) {
		$status=$m['status'];
		$from=$m['from'];
		$to=$m['to'];
		return true;
	} else {
		return false;
	}
}

function bar_apicall1($s,$u,$call,$version=1,$async=false) {
	global $cc_encryption_hash;
	if ($s['secure']) $apicall="https://";
	else $apicall="http://";
	$apicall .= $s['ipaddress'].":";
	if ($s['secure']) $apicall.="2087";
	else $apicall.="2086";
	$apicall .= "/xml-api/";

	$curl = curl_init();
	curl_setopt($curl, CURLOPT_SSL_VERIFYHOST,0);
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER,0);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER,1);
	if ($async == 1) {
		curl_setopt($curl, CURLOPT_FRESH_CONNECT, true);
		curl_setopt($curl, CURLOPT_TIMEOUT_MS, 1);
	} elseif ($async == 2) {
		curl_setopt($curl, CURLOPT_FRESH_CONNECT, true);
		curl_setopt($curl, CURLOPT_TIMEOUT_MS, 2000);
	}

	if ($s['accesshash']) $header[0] = "Authorization: WHM ".$s['username'].":" . preg_replace("'(\r|\n)'","",$s['accesshash']);
	elseif ($s['password']) $header[0] = "Authorization: Basic " . base64_encode($s['username'].":".decrypt($s['password'],$cc_encryption_hash)) . "\n\r";
	else {
		bar_message("Fill in accesshash or password for your server ".$s['name']);
		return false;
	}

	curl_setopt($curl,CURLOPT_HTTPHEADER,$header);

	$apicall .='cpanel?cpanel_xmlapi_user='.$u['username'].'&cpanel_xmlapi_apiversion='.$version;
	$apicall .= '&'.$call;

	//echo '<br />'.$apicall.'<br />';

	curl_setopt($curl, CURLOPT_URL, $apicall);
	$result = curl_exec($curl);
	if (curl_errno($curl)) {
		bar_message('API Call Error:'.curl_errno($curl).'/'.curl_error($curl).' at '.$apicall);
		curl_close($curl);
		return false;
	}

	curl_close($curl);

	if ($result) {
		try {
			//$result=bar_cleanupXML($result);
			$xml = new SimpleXMLElement($result);
		} catch(Exception $e) {
			bar_message('An undefined error occured x13');
			//echo '<br />'.htmlentities($result).'<br />';
			return false;
		}
		if ($version == 1) {
			$ret=(string)$xml->data->result;
		} else {
			$ret=array();
			foreach ($xml->children() as $t => $d) {
				if ($t == 'data') {
					$ret[]=(array)$d->children();
				}
			}
		}
		if ($ret) return $ret;
		else return true;
	}
	else {
		bar_message('API Call Error: The returned message is empty');
		return false;
	}
}

function bar_message($message,$type='debug') {
	global $barEcho;

	if (is_array($message)) $message=print_r($message,true);
	$output='Backup & Restore: '.$message;
	//if (!$barNlc || isset($_SERVER['HTTP_USER_AGENT'])) echo '<br />'.$output,'<br />';
	if (!isset($barEcho) || $barEcho) echo '<br />'.$output,'<br />';
	logactivity($output);
}

function bar_apicall2($s,$u,$call,$async=false) {
	global $cc_encryption_hash;

	if ($s['secure']) $apicall="https://";
	else $apicall="http://";
	$apicall .= $s['ipaddress'].":";
	if ($s['secure']) $apicall.="2087";
	else $apicall.="2086";
	$apicall .= "/xml-api/";

	$curl = curl_init();
	curl_setopt($curl, CURLOPT_SSL_VERIFYHOST,0);
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER,0);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER,1);
	if ($async) {
		curl_setopt($curl, CURLOPT_FRESH_CONNECT, true);
		curl_setopt($curl, CURLOPT_TIMEOUT_MS, 1);
	}

	if ($s['accesshash']) $header[0] = "Authorization: WHM ".$s['username'].":" . preg_replace("'(\r|\n)'","",$s['accesshash']);
	elseif ($s['password']) $header[0] = "Authorization: Basic " . base64_encode($s['username'].":".decrypt($s['password'],$cc_encryption_hash)) . "\n\r";
	else {
		bar_message("Fill in accesshash or password for your server ".$s['name']);
		return false;
	}

	curl_setopt($curl,CURLOPT_HTTPHEADER,$header);

	$apicall .= $call;
	$apicall .= '&api.version=1';

	curl_setopt($curl, CURLOPT_URL, $apicall);
	$result = curl_exec($curl);
	curl_close($curl);

	if ($result) {
		$xml = new SimpleXMLElement($result);
		$ret['reason']=(string)$xml->metadata->reason;
		$ret['result']=(string)$xml->metadata->result;
		$ret['output']=$xml->metadata->output->raw;
		$ret['data']=$xml->data;
		if (!$ret['result']) bar_message($ret['reason']);
		return $ret;
	} else {
		bar_message($ret['reason']);
		return array('result' => 0,'reason' => 'API call failed');
	}
}

function bar_update_addon($setting,$value) {
	if (bar_get_addon($setting)) {
		$sql = "update `tbladdonmodules` set `value`='".$value."' where `module`='bar' and `setting`=".$setting;
		$rs = mysql_query($sql);
	} else {
		$sql = "insert into `tbladdonmodules` (`value`,`module`,`setting`) values ('".$value."','bar','".$setting."')";
		$rs = mysql_query($sql);
	}
}

function bar_get_addon($setting) {
	$sql="SELECT value FROM tbladdonmodules WHERE module='bar' and setting='".$setting."'";
	$result = full_query($sql);
	if($data = mysql_fetch_array($result)) {
		return $data["value"];
	}
	return null;
}

function bar_cleanupXML($xml) {
	if (class_exists('tidy')) {
		$config = array(
           'input-xml'  => true,
           'output-xml' => true,
           'wrap'       => false);
		// Tidy
		$tidy = new tidy;
		$tidy->parseString($xml, $config);
		$tidy->cleanRepair();
		// Output
		return $tidy;
	} else return $xml;
}
