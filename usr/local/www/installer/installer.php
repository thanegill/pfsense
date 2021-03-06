<?php
/*
	installer.php (pfSense webInstaller)
	part of pfSense (https://www.pfsense.org/)
	Copyright (C) 2013-2015 Electric Sheep Fencing, LP
	Copyright (C) 2010 Scott Ullrich <sullrich@gmail.com>
	All rights reserved.

	Redistribution and use in source and binary forms, with or without
	modification, are permitted provided that the following conditions are met:

	1. Redistributions of source code must retain the above copyright notice,
	   this list of conditions and the following disclaimer.

	2. Redistributions in binary form must reproduce the above copyright
	   notice, this list of conditions and the following disclaimer in the
	   documentation and/or other materials provided with the distribution.

	THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
	INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
	AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
	AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
	OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
	SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
	INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
	CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
	ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
	POSSIBILITY OF SUCH DAMAGE.
*/

$nocsrf = true;

require("globals.inc");
require("guiconfig.inc");

define('PC_SYSINSTALL', '/usr/sbin/pc-sysinstall/pc-sysinstall/pc-sysinstall.sh');

if($g['platform'] == "pfSense" or $g['platform'] == "nanobsd") {
	header("Location: /");
	exit;
}

// Main switch dispatcher
switch ($_REQUEST['state']) {
	case "update_installer_status":
		update_installer_status();
		exit;
	case "custominstall":
		installer_custom();
		exit;
	case "begin_install":
		installing_gui();
		begin_install();
		exit;
	case "verify_before_install":
		verify_before_install();
		exit;
	case "easy_install_ufs":
		easy_install("UFS+S");
		exit;

	default:
		installer_main();	
}

function easy_install($fstype = "UFS+S") {
	// Calculate swap and disk sizes
	$disks = installer_find_all_disks();
	$memory = get_memory();
	$swap_size = $memory[0] * 2;
	$first_disk = trim(installer_find_first_disk());
	$disk_info = pcsysinstall_get_disk_info($first_disk);
	$size = $disk_info['size'];
	$first_disk_size = $size - $swap_size;
	$disk_setup = array();
	$tmp_array = array();
	// Build the disk layout for /
	$tmp_array['disk'] = $first_disk;
	$tmp_array['size'] = $first_disk_size;
	$tmp_array['mountpoint'] = "/";
	$tmp_array['fstype'] = $fstype;
	$disk_setup[] = $tmp_array;
	unset($tmp_array);
	$tmp_array = array();
	// Build the disk layout for SWAP
	$tmp_array['disk'] = $first_disk;
	$tmp_array['size'] = $swap_size;
	$tmp_array['mountpoint'] = "none";
	$tmp_array['fstype'] = "SWAP";
	$disk_setup[] = $tmp_array;
	unset($tmp_array);
	$bootmanager = "bsd";
	file_put_contents("/tmp/webInstaller_disk_layout.txt", serialize($disk_setup));
	file_put_contents("/tmp/webInstaller_disk_bootmanager.txt", serialize($bootmanager));
	header("Location: installer.php?state=verify_before_install");
	exit;
}

function write_out_pc_sysinstaller_config($disks, $bootmanager = "bsd") {
	$diskareas = "";
	$fd = fopen("/usr/sbin/pc-sysinstall/examples/pfSense-install.cfg", "w");
	if(!$fd) 
		return true;
	if($bootmanager == "") 
	 	$bootmanager = "none";
	// Yes, -1.  We ++ early in loop.
	$numdisks = -1;
	$lastdisk = "";
	$diskdefs = "";
	// Run through the disks and create the conf areas for pc-sysinstaller
	foreach($disks as $disksa) {
		$fstype = $disksa['fstype'];
		$size = $disksa['size'];
		$mountpoint = $disksa['mountpoint'];
		$disk = $disksa['disk'];
		if($disk <> $lastdisk) {
			$lastdisk = $disk;
			$numdisks++;
			$diskdefs .= "# disk {$disk}\n";
			$diskdefs .= "disk{$numdisks}={$disk}\n";
			$diskdefs .= "partition=all\n";
			$diskdefs .= "bootManager={$bootmanager}\n";
			$diskdefs .= "commitDiskPart\n\n";
		}
		$diskareas .= "disk{$numdisks}-part={$fstype} {$size} {$mountpoint} \n";
		if($encpass)
			$diskareas .= "encpass={$encpass}\n";
	}
	
	$config = <<<EOF
# Sample configuration file for an installation using pc-sysinstall
# This file was automatically generated by installer.php
 
installMode=fresh
installInteractive=yes
installType=FreeBSD
installMedium=LiveCD

# Set the disk parameters
{$diskdefs}

# Setup the disk label
# All sizes are expressed in MB
# Avail FS Types, UFS, UFS+S, UFS+J, ZFS, SWAP
# Size 0 means use the rest of the slice size
# Alternatively, you can append .eli to any of
# the above filesystem types to encrypt that disk.
# If you with to use a passphrase with this 
# encrypted partition, on the next line 
# the flag "encpass=" should be entered:
# encpass=mypass
# disk0-part=UFS 500 /boot
# disk0-part=UFS.eli 500 /
# disk0-part=UFS.eli 500 /usr
{$diskareas}

# Do it now!
commitDiskLabel

# Set if we are installing via optical, USB, or FTP
installType=FreeBSD

packageType=cpdup

# Optional Components
cpdupPaths=boot,COPYRIGHT,bin,conf,conf.default,dev,etc,home,kernels,libexec,lib,root,sbin,usr,var

# runExtCommand=chmod a+rx /usr/local/bin/after_installation_routines.sh ; cd / ; /usr/local/bin/after_installation_routines.sh
EOF;
	fwrite($fd, $config);
	fclose($fd);
	return;
}

function start_installation() {
	global $g, $fstype, $savemsg;
	if(file_exists("/tmp/install_complete"))
		return;
	$ps_running = exec("/bin/ps awwwux | /usr/bin/grep -v grep | /usr/bin/grep 'sh /tmp/installer.sh'");
	if($ps_running)	
		return;
	$fd = fopen("/tmp/installer.sh", "w");
	if(!$fd) {
		die(gettext("Could not open /tmp/installer.sh for writing"));
		exit;
	}
	fwrite($fd, "/bin/rm /tmp/.pc-sysinstall/pc-sysinstall.log 2>/dev/null\n");
	fwrite($fd, "/usr/sbin/pc-sysinstall/pc-sysinstall/pc-sysinstall.sh -c /usr/sbin/pc-sysinstall/examples/pfSense-install.cfg \n");
	fwrite($fd, "/bin/chmod a+rx /usr/local/bin/after_installation_routines.sh\n");
	fwrite($fd, "cd / && /usr/local/bin/after_installation_routines.sh\n");
	fwrite($fd, "/bin/mkdir /mnt/tmp\n");
	fwrite($fd, "/usr/bin/touch /tmp/install_complete\n");
	fclose($fd);
	exec("/bin/chmod a+rx /tmp/installer.sh");
	mwexec_bg("/bin/sh /tmp/installer.sh");
}

function installer_find_first_disk() {
	global $g, $fstype, $savemsg;
	$disk = `/usr/sbin/pc-sysinstall/pc-sysinstall/pc-sysinstall.sh disk-list | head -n1 | cut -d':' -f1`;
	return trim($disk);
}

function pcsysinstall_get_disk_info($diskname) {
	global $g, $fstype, $savemsg;
	$disk = explode("\n", `/usr/sbin/pc-sysinstall/pc-sysinstall/pc-sysinstall.sh disk-list`);
	$disks_array = array();
	foreach($disk as $d) {
		$disks_info = explode(":", $d);
		$tmp_array = array();
		if($disks_info[0] == $diskname) {
			$disk_info = explode("\n", `/usr/sbin/pc-sysinstall/pc-sysinstall/pc-sysinstall.sh disk-info {$disks_info[0]}`);
			$disk_info_split = explode("=", $disk_info);
			foreach($disk_info as $di) { 
				$di_s = explode("=", $di);
				if($di_s[0])
					$tmp_array[$di_s[0]] = $di_s[1];
			}
			$tmp_array['size']--;
			$tmp_array['disk'] = trim($disks_info[0]);
			$tmp_array['desc'] = trim(htmlentities($disks_info[1]));
			return $tmp_array;
		}
	}
}

// Return an array with all disks information.
function installer_find_all_disks() {
	global $g, $fstype, $savemsg;
	$disk = explode("\n", `/usr/sbin/pc-sysinstall/pc-sysinstall/pc-sysinstall.sh disk-list`);
	$disks_array = array();
	foreach($disk as $d) {
		if(!$d) 
			continue;
		$disks_info = explode(":", $d);
		$tmp_array = array();
		$disk_info = explode("\n", `/usr/sbin/pc-sysinstall/pc-sysinstall/pc-sysinstall.sh disk-info {$disks_info[0]}`);
		foreach($disk_info as $di) { 
			$di_s = explode("=", $di);
			if($di_s[0])
				$tmp_array[$di_s[0]] = $di_s[1];
		}
		$tmp_array['size']--;
		$tmp_array['disk'] = trim($disks_info[0]);
		$tmp_array['desc'] = trim(htmlentities($disks_info[1]));
		$disks_array[] = $tmp_array;
	}
	return $disks_array;
}

function update_installer_status() {
	global $g, $fstype, $savemsg;
	// Ensure status files exist
	if(!file_exists("/tmp/installer_installer_running"))
		touch("/tmp/installer_installer_running");
	$status = `cat /tmp/.pc-sysinstall/pc-sysinstall.log`;
	$status = str_replace("\n", "\\n", $status);
	$status = str_replace("\n", "\\r", $status);
	$status = str_replace("'", "\\'", $status);
	echo "document.forms[0].installeroutput.value='$status';\n";
	echo "document.forms[0].installeroutput.scrollTop = document.forms[0].installeroutput.scrollHeight;\n";	
	// Find out installer progress
	$progress = "5";
	if(strstr($status, "Running: dd")) 
		$progress = "6";
	if(strstr($status, "Running: gpart create -s GPT")) 
		$progress = "7";
	if(strstr($status, "Running: gpart bootcode")) 
		$progress = "7";
	if(strstr($status, "Running: newfs -U")) 
		$progress = "8";
	if(strstr($status, "Running: sync")) 
		$progress = "9";
	if(strstr($status, "/boot /mnt/boot")) 
		$progress = "10";
	if(strstr($status, "/COPYRIGHT /mnt/COPYRIGHT"))
		$progress = "11";
	if(strstr($status, "/bin /mnt/bin"))
		$progress = "12";
	if(strstr($status, "/conf /mnt/conf"))
		$progress = "15";
	if(strstr($status, "/conf.default /mnt/conf.default"))
		$progress = "20";
	if(strstr($status, "/dev /mnt/dev"))
		$progress = "25";
	if(strstr($status, "/etc /mnt/etc"))
		$progress = "30";
	if(strstr($status, "/home /mnt/home"))
		$progress = "35";
	if(strstr($status, "/kernels /mnt/kernels"))
		$progress = "40";
	if(strstr($status, "/libexec /mnt/libexec"))
		$progress = "50";
	if(strstr($status, "/lib /mnt/lib"))
		$progress = "60";
	if(strstr($status, "/root /mnt/root"))
		$progress = "70";
	if(strstr($status, "/sbin /mnt/sbin"))
		$progress = "75";
	if(strstr($status, "/sys /mnt/sys"))
		$progress = "80";
	if(strstr($status, "/usr /mnt/usr"))
		$progress = "95";
	if(strstr($status, "/usr /mnt/usr"))
		$progress = "90";
	if(strstr($status, "/var /mnt/var"))
		$progress = "95";
	if(strstr($status, "cap_mkdb /etc/login.conf"))
		$progress = "96";
	if(strstr($status, "Setting hostname"))
		$progress = "97";
	if(strstr($status, "umount -f /mnt"))
		$progress = "98";
	if(strstr($status, "umount -f /mnt"))
		$progress = "99";
	if(strstr($status, "Installation finished"))
		$progress = "100";
	// Check for error and bail if we see one.
	if(stristr($status, "error")) {
		$error = true;
		echo "\$('#installerrunning').html('<img class=\"infoboxnpimg\" src=\"/themes/{$g['theme']}/images/icons/icon_exclam.gif\"> <font size=\"2\"><b>An error occurred.  Aborting installation.  <a href=\"/installer\">Back</a> to webInstaller'); ";
		echo "\$('#progressbar').css('width','100%');\n";
		unlink_if_exists("/tmp/install_complete");
		return;
	}
	$running_old = trim(file_get_contents("/tmp/installer_installer_running"));
	if($installer_running <> "running") {
		$ps_running = exec("/bin/ps awwwux | /usr/bin/grep -v grep | /usr/bin/grep 'sh /tmp/installer.sh'");
		if($ps_running)	{
			$running = "\$('#installerrunning').html('<table><tr><td valign=\"middle\"><img src=\"/themes/{$g['theme']}/images/misc/loader.gif\"></td><td valign=\"middle\">&nbsp;<font size=\"2\"><b>Installer running ({$progress}% completed)...</td></tr></table>'); ";
			if($running_old <> $running) {
				echo $running;
				file_put_contents("/tmp/installer_installer_running", "$running");			
			}
		}
	}
	if($progress) 
		echo "\$('#progressbar').css('width','{$progress}%');\n";
	if(file_exists("/tmp/install_complete")) {
		echo "\$('#installerrunning').html('<img class=\"infoboxnpimg\" src=\"/themes/{$g['theme']}/images/icons/icon_exclam.gif\"> <font size=\"+1\">Installation completed.  Please <a href=\"/reboot.php\">reboot</a> to continue');\n";
		echo "\$('#pbdiv').fadeOut();\n";
		unlink_if_exists("/tmp/installer.sh");
		file_put_contents("/tmp/installer_installer_running", "finished");
	}
}

function update_installer_status_win($status) {
	global $g, $fstype, $savemsg;
	echo "<script type=\"text/javascript\">\n";
	echo "	\$('#installeroutput').val('" . str_replace(htmlentities($status), "\n", "") . "');\n";
	echo "</script>\n";
}

function begin_install() {
	global $g, $savemsg;
	if(file_exists("/tmp/install_complete"))
		return;
	unlink_if_exists("/tmp/install_complete");
	update_installer_status_win(sprintf(gettext("Beginning installation on disk %s."),$disk));
	start_installation();
}

function head_html() {
	global $g, $fstype, $savemsg;
	echo <<<EOF
<html>
	<head>
		<style type='text/css'>
			hr {
				border: 0;
				color: #000000;
				background-color: #000000;
				height: 1px;
				width: 100%;
				text-align: left;
			}
			a:link { 
				color: #000000;
				text-decoration:underline;
				font-size:14;
			}
			a:visited { 
				color: #000000;
				text-decoration:underline;
				font-size:14;
			}
			a:hover { 
				color: #FFFF00;
				text-decoration: none;
				font-size:14;
			}
			a:active { 
				color: #FFFF00;
				text-decoration:underline;
				font-size:14;
			}
		</style>
	</head>
EOF;

}

function body_html() {
	global $g, $fstype, $savemsg;
	$pgtitle = array("{$g['product_name']}", gettext("Installer"));
	include("head.inc");
	echo <<<EOF
	<body link="#0000CC" vlink="#0000CC" alink="#0000CC">
	<script type="text/javascript" src="/javascript/jquery-1.11.1.min.js"></script>
	<script type="text/javascript" src="/javascript/jquery-migrate-1.2.1.min.js"></script>
	<script type="text/javascript" src="/javascript/jquery/jquery-ui-1.11.1.min.js"></script>
	<script type="text/javascript">
		function getinstallerprogress() {
			url = '/installer/installer.php';
			pars = 'state=update_installer_status';
			callajax(url, pars, installcallback);
		}
		function callajax(url, pars, activitycallback) {
			jQuery.ajax(
				url,
				{
					type: 'post',
					data: pars,
					complete: activitycallback
				});
		}
		function installcallback(transport) {
			setTimeout('getinstallerprogress()', 2000);
			eval(transport.responseText);
		}
	</script>
EOF;

	if($one_two)
		echo "<p class=\"pgtitle\">{$pgtitle}</font></p>";

	if ($savemsg) print_info_box($savemsg); 
}

function end_html() {
	global $g, $fstype, $savemsg;
	echo "</form>";
	echo "</body>";
	echo "</html>";
}

function template() {
	global $g, $fstype, $savemsg;
	head_html();
	body_html();
	echo <<<EOF
	<div id="mainlevel">
		<table width="100%" border="0" cellpadding="0" cellspacing="0">
	 		<tr>
	    		<td>
					<div id="mainarea">
						<table class="tabcont" width="100%" border="0" cellpadding="0" cellspacing="0">
							<tr>
	     						<td class="tabcont" >
	      							<form action="installer.php" method="post">
									<div id="pfsensetemplate">


									</div>
	     						</td>
							</tr>
						</table>
					</div>
				</td>
			</tr>
		</table>
	</div>
EOF;
	end_html();
}

function verify_before_install() {
	global $g, $fstype, $savemsg;
	$encrypted_root = false;
	$non_encrypted_boot = false;
	$non_encrypted_notice = false;
	head_html();
	body_html();
	page_table_start($g['product_name'] . " installer - Verify final installation settings");
	// If we are visiting this step from anything but the row editor / custom install
	// then load the on disk layout contents if they are available.
	if(!$_REQUEST['fstype0'] && file_exists("/tmp/webInstaller_disk_layout.txt")) {
		$disks = unserialize(file_get_contents("/tmp/webInstaller_disk_layout.txt"));
		$bootmanager = unserialize(file_get_contents("/tmp/webInstaller_disk_bootmanager.txt"));
		$restored_layout_from_file = true;
		$restored_layout_txt = "The previous disk layout was restored from disk";
	} else {
		$disks = array();
	}
	if(!$bootmanager) 
		$bootmanager = $_REQUEST['bootmanager'];
	// echo "\n<!--" . print_r($_REQUEST, true) . " -->\n";
	$disk = pcsysinstall_get_disk_info(htmlspecialchars($_REQUEST['disk']));
	$disksize = format_bytes($disk['size'] * 1048576);
	// Loop through posted items and create an array
	for($x=0; $x<99; $x++) { // XXX: Make this more optimal
		if(!$_REQUEST['fstype' . $x])
			continue;
		$tmparray = array();
		if($_REQUEST['fstype' . $x] <> "SWAP") {
			$tmparray['mountpoint'] = $_REQUEST['mountpoint' . $x];
			// Check for encrypted slice /
			if(stristr($_REQUEST['fstype' . $x], ".eli")) {
				if($tmparray['mountpoint'] == "/") 
					$encrypted_root = true;
			}
			// Check if we have a non-encrypted /boot
			if($tmparray['mountpoint'] == "/boot") 	{
				if(!stristr($_REQUEST['fstype' . $x], ".eli"))
					$non_encrypted_boot = true;
			}
			if($tmparray['mountpoint'] == "/conf") {	
				$tmparray['mountpoint'] = "/conf{$x}";
				$error_txt[] = "/conf is not an allowed mount point and has been renamed to /conf{$x}.";
			}
		} else  {
			$tmparray['mountpoint'] = "none";
		}
		// If we have an encrypted /root and lack a non encrypted /boot, throw an error/warning
		if($encrypted_root && !$non_encrypted_boot && !$non_encrypted_notice) {
			$error_txt[] = "A non-encrypted /boot slice is required when encrypting the / slice";
			$non_encrypted_notice = true;
		}
		$tmparray['disk'] = $_REQUEST['disk' . $x];
		$tmparray['fstype'] = $_REQUEST['fstype' . $x];
		$tmparray['size'] = $_REQUEST['size' . $x];
		$tmparray['encpass'] = $_REQUEST['encpass' . $x];
		$disks[] = $tmparray;
	}
	// echo "\n<!-- " . print_r($disks, true) . " --> \n";
	$bootmanagerupper = strtoupper($bootmanager);
	echo <<<EOFAMBAC
	<form method="post" action="installer.php">
	<input type="hidden" name="fstype" value="{$fstype_echo}">
	<input type="hidden" name="disk" value="{$disk_echo}">
	<input type="hidden" name="state" value="begin_install">
	<input type="hidden" name="swapsize" value="{$swapsize}">
	<input type="hidden" name="encpass" value="{$encpass}">
	<input type="hidden" name="bootmanager" value="{$bootmanager}">
	<div id="mainlevel">
		<table width="800" border="0" cellpadding="0" cellspacing="0">
	 		<tr>
	    		<td>
					<div id="mainarea">
						<table width="100%" border="0" cellpadding="0" cellspacing="0">
							<tr>
	     						<td >
									<div>
										<center>
											<div id="pfsensetemplate">
												<table width='100%'>
EOFAMBAC;
												// If errors are found, throw the big red box.
												if ($error_txt) {
													echo "<tr><td colspan=\"5\">&nbsp;</td>";
													echo "<tr><td colspan=\"5\">";
													print_input_errors($error_txt);
													echo "</td></tr>";
												} else 
													echo "<tr><td>&nbsp;</td></tr>";

	echo <<<EOFAMBACBAF

												<tr><td colspan='5' align="center"><b>Boot manager: {$bootmanagerupper}</td></tr>
												<tr><td>&nbsp;</td></tr>
												<tr>
													<td align='left'>
														<b>Mount point</b>
													</td>
													<td align='left'>
														<b>Filesysytem type</b>
													</td>
													<td align='left'>
														<b>Disk</b>
													</td>
													<td align='left'>
														<b>Size</b>
													</td>
													<td align='left'>
														<b>Encryption password</b>
													</td>
												</tr>
												<tr><td colspan='5'><hr></td></tr>

EOFAMBACBAF;

													foreach($disks as $disk) {
														$desc = pcsysinstall_get_disk_info($disk['disk']);
														echo "<tr>";
														echo "<td>&nbsp;&nbsp;&nbsp;" . htmlspecialchars($disk['mountpoint']) . "</td>";
														echo "<td>" . htmlspecialchars($disk['fstype']) . "</td>";
														echo "<td>" . htmlspecialchars($disk['disk']) . " " . htmlspecialchars($desc['desc']) . "</td>";
														echo "<td>" . htmlspecialchars($disk['size']) . "</td>";
														echo "<td>" . htmlspecialchars($disk['encpass']) . "</td>";
														echo "</tr>";
													}

echo <<<EOFAMB
												<tr><td colspan="5"><hr></td></tr>
												</table>
											</div>
										</center>
									</div>
	     						</td>
							</tr>
						</table>
					</div>
					<center>
						<p/>
						<input type="button" value="Cancel" onClick="javascript:document.location='installer.php?state=custominstall';"> &nbsp;&nbsp;
EOFAMB;
						if(!$error_txt) 
						echo "<input type=\"submit\" value=\"Begin installation\"> <br />&nbsp;";
echo <<<EOFAMBASDF

					</center>
				</td>
			</tr>
		</table>
	</div>
EOFAMBASDF;


	page_table_end();
	end_html();
	write_out_pc_sysinstaller_config($disks, $bootmanager);
	// Serialize layout to disk so it can be read in later.
	file_put_contents("/tmp/webInstaller_disk_layout.txt", serialize($disks));
	file_put_contents("/tmp/webInstaller_disk_bootmanager.txt", serialize($bootmanager));
}

function installing_gui() {
	global $g, $fstype, $savemsg;
	head_html();
	body_html();
	echo "<form action=\"installer.php\" method=\"post\" state=\"step1_post\">";
	page_table_start();
	echo <<<EOF
	<center>
		<table width="100%">
		<tr><td>
			<div id="mainlevel">
				<table width="100%" border="0" cellpadding="0" cellspacing="0">
			 		<tr>
			    		<td>
							<div id="mainarea">
								<table width="100%" border="0" cellpadding="0" cellspacing="0">
									<tr>
			     						<td>
											<div id="pfsenseinstaller" width="100%">
												<div id='installerrunning' width='100%' style="padding:8px; border:1px dashed #000000">
													<table>
														<tr>
															<td valign="middle">
																<img src="/themes/{$g['theme']}/images/misc/loader.gif">
															</td>
															<td valign="middle">
																&nbsp;<font size="2"><b>Starting Installer...  Please wait...
															</td>
														</tr>
													</table>
												</div>
												<div id='pbdiv'>
													<br />
													<center>
													<table id='pbtable' height='15' width='640' border='0' colspacing='0' cellpadding='0' cellspacing='0'>
														<tr>
															<td background="/themes/{$g['theme']}/images/misc/bar_left.gif" height='15' width='5'>
															</td>
															<td>
																<table id="progholder" name="progholder" height='15' width='630' border='0' colspacing='0' cellpadding='0' cellspacing='0'>
																	<td background="/themes/{$g['theme']}/images/misc/bar_gray.gif" valign="top" align="left">
																		<img src='/themes/{$g['theme']}/images/misc/bar_blue.gif' width='0' height='15' name='progressbar' id='progressbar'>
																	</td>
																</table>
															</td>
															<td background="/themes/{$g['theme']}/images/misc/bar_right.gif" height='15' width='5'>
															</td>
														</tr>
													</table>
													<br />
												</div>
												<textarea name='installeroutput' id='installeroutput' rows="31" cols="90">
												</textarea>
											</div>
			     						</td>
									</tr>
								</table>
							</div>
						</td>
					</tr>
				</table>
			</div>
		</td></tr>
		</table>
	</center>
	<script type="text/javascript">setTimeout('getinstallerprogress()', 250);</script>

EOF;
	page_table_end();
	end_html();
}

function page_table_start($pgtitle = "") {
	global $g, $fstype, $savemsg;
	if($pgtitle == "") 
		$pgtitle = "{$g['product_name']} installer";
	echo <<<EOF
	<center>
		<img border="0" src="/themes/{$g['theme']}/images/logo.gif"></a><br />
		<table cellpadding="6" cellspacing="0" width="550" style="border:1px solid #000000">
		<tr height="10" bgcolor="#990000">
			<td style="border-bottom:1px solid #000000">
				<font color='white'>
					<b>
						{$pgtitle}
					</b>
				</font>
			</td>
		</tr>
		<tr>
			<td>

EOF;

}

function page_table_end() {
	global $g, $fstype, $savemsg;
	echo <<<EOF
			</td>
		</tr>
		</table>
	</center>

EOF;
	
}

function installer_custom() {
	global $g, $fstype, $savemsg;
	global $select_txt, $custom_disks;
	if(file_exists("/tmp/.pc-sysinstall/pc-sysinstall.log")) 
		unlink("/tmp/.pc-sysinstall/pc-sysinstall.log");
	$disks = installer_find_all_disks();
	// Pass size of disks down to javascript.
	$disk_sizes_js_txt = "var disk_sizes = new Array();\n";
	foreach($disks as $disk) 
		$disk_sizes_js_txt .= "disk_sizes['{$disk['disk']}'] = '{$disk['size']}';\n";
	head_html();
	body_html();
	page_table_start($g['product_name'] . " installer - Customize disk(s) layout");
	echo <<<EOF
		<script type="text/javascript">
			Array.prototype.in_array = function(p_val) {
				for(var i = 0, l = this.length; i < l; i++) {
					if(this[i] == p_val) {
						return true;
					}
				}
				return false;
			}
			function row_helper_dynamic_custom() {
				var totalsize = 0;
				{$disk_sizes_js_txt}
				// Run through all rows and process data
				for(var x = 0; x<99; x++) { //optimize me better
					if(\$('#fstype' + x).length) {
						if(\$('#size' + x).val() == '')
							\$('#size' + x).val(disk_sizes[\$('disk' + x).value]);
						var fstype = \$('#fstype' + x).val();
						if(fstype.substring(fstype.length - 4) == ".eli") {
							\$('#encpass' + x).prop('disabled',false);
							if(!encryption_warning_shown) {
								alert('NOTE: If you define a disk encryption password you will need to enter it on *EVERY* bootup!');
								encryption_warning_shown = true;
							}
						} else { 
							\$('#encpass' + x).prop('disabled',true);
						}
					}
					// Calculate size allocations
					if(\$('#size' + x).length) {
						if(parseInt($('#size' + x).val()) > 0)
							totalsize += parseInt($('#size' + x).val());
					}
				}
				// If the totalsize element exists, set it and disable
				if(\$('#totalsize').length) {
					if(\$('#totalsize').val() != totalsize) {
						// When size allocation changes, draw attention.
 						jQuery('#totalsize').effect('highlight');
						\$('#totalsize').val(totalsize);
					}
					\$('#totalsize').prop('disabled',true);
				}
				if(\$('#disktotals').length) {
					var disks_seen = new Array();
					var tmp_sizedisks = 0;
					var disksseen = 0;
					for(var xx = 0; xx<99; xx++) {
						if(\$('#disk' + xx).length) {
							if(!disks_seen.in_array(\$('#disk' + xx).val())) {
								tmp_sizedisks += parseInt(disk_sizes[\$('#disk' + xx).val()]);
								disks_seen[disksseen] = \$('#disk' + xx).val();
								disksseen++;
							}
						}
					\$('#disktotals').val(tmp_sizedisks);
					\$('#disktotals').prop('disabled',true);
					\$('#disktotals').css('color','#000000');
					var remaining = parseInt(\$('#disktotals').val()) - parseInt(\$('#totalsize').val());
						if(remaining == 0) {
							if(\$('#totalsize').length)
								\$('#totalsize').css({
									'background':'#00FF00',
									'color':'#000000'
								});
						} else {
							if(\$('#totalsize').length)
								\$('#totalsize').css({
									'background':'#FFFFFF',
									'color':'#000000'
								});
						}
						if(parseInt(\$('#totalsize').val()) > parseInt(\$('#disktotals').val())) {
							if(\$('#totalsize'))
								\$('#totalsize').css({
									'background':'#FF0000',
									'color':'#000000'
								});							
						}
						if(\$('#availalloc').length) {
							\$('#availalloc').prop('disabled',true);
							\$('#availalloc').val(remaining);
								\$('#availalloc').css({
									'background':'#FFFFFF',
									'color':'#000000'
								});							
						}
					}
				}
			}
		</script>
		<script type="text/javascript" src="/javascript/row_helper_dynamic.js"></script>
		<script type="text/javascript">
			// Setup rowhelper data types
			rowname[0] = "mountpoint";
			rowtype[0] = "textbox";
			rowsize[0] = "8";
			rowname[1] = "fstype";
			rowtype[1] = "select";
			rowsize[1] = "1";
			rowname[2] = "disk";
			rowtype[2] = "select";
			rowsize[2] = "1";
			rowname[3] = "size";
			rowtype[3] = "textbox";
			rowsize[3] = "8";
			rowname[4] = "encpass";
			rowtype[4] = "textbox";
			rowsize[4] = "8";
			field_counter_js = 5;
			rows = 1;
			totalrows = 1;
			loaded = 1;
			rowhelper_onChange = 	" onChange='javascript:row_helper_dynamic_custom()' ";
			rowhelper_onDelete = 	"row_helper_dynamic_custom(); ";
			rowhelper_onAdd = 		"row_helper_dynamic_custom();";
		</script>
		<form action="installer.php" method="post">
			<input type="hidden" name="state" value="verify_before_install">
			<div id="mainlevel">
				<center>
				<table width="100%" border="0" cellpadding="5" cellspacing="0">
			 		<tr>
			    		<td>
							<center>
							<div id="mainarea">
								<center>
								<table width="100%" border="0" cellpadding="5" cellspacing="5">
									<tr>
			     						<td>
											<div id="pfsenseinstaller">
												<center>
												<div id='loadingdiv'>
													<table>
														<tr>
															<td valign="center">
																<img src="/themes/{$g['theme']}/images/misc/loader.gif">
															</td>
															<td valign="center">
														 		&nbsp;Probing disks, please wait...
															</td>
														</tr>
													</table>
												</div>
EOF;
	ob_flush();
	// Read bootmanager setting from disk if found
	if(file_exists("/tmp/webInstaller_disk_bootmanager.txt"))
		$bootmanager = unserialize(file_get_contents("/tmp/webInstaller_disk_bootmanager.txt"));
	if($bootmanager == "none") 
		$noneselected = " SELECTED";
	if($bootmanager == "bsd") 
		$bsdeselected = " SELECTED";
	if(!$disks)  {
		$custom_txt = gettext("ERROR: Could not find any suitable disks for installation.");
	} else {
		// Prepare disk selection dropdown
		$custom_txt = <<<EOF
												<center>
												<table>
												<tr>
													<td align='right'>
														Boot manager:
													</td>
													<td>
														<select name='bootmanager'>
															<option value='none' $noneselected>
																None
															</option>
															<option value='bsd' $bsdeselected>
																BSD
															</option>
														</select>
													</td>
												</tr>
												</table>
												<hr>
												<table id='maintable'><tbody>
												<tr>
													<td align="middle">
														<b>Mount</b>
													</td>
													<td align='middle'>
														<b>Filesysytem</b>
													</td>
													<td align="middle">
														<b>Disk</b>
													</td>
													<td align="middle">
														<b>Size</b>
													</td>
													<td align="middle">
														<b>Encryption password</b>
													</td>
													<td>
														&nbsp;
													</td>
												</tr>
												<tr>

EOF;

		// Calculate swap disk sizes
		$memory = get_memory();
		$swap_size = $memory[0] * 2;
		$first_disk = trim(installer_find_first_disk());
		$disk_info = pcsysinstall_get_disk_info($first_disk);
		$size = $disk_info['size'];
		$first_disk_size = $size - $swap_size;

		// Debugging
		// echo "\n\n<!-- $first_disk - " . print_r($disk_info, true) . " - $size  - $first_disk_size -->\n\n";

		// Check to see if a on disk layout exists
		if(file_exists("/tmp/webInstaller_disk_layout.txt")) {
			$disks_restored = unserialize(file_get_contents("/tmp/webInstaller_disk_layout.txt"));
			$restored_layout_from_file = true;
			$restored_layout_txt = "<br />* The previous disk layout was restored from a previous session";
		}

		// If we restored disk layout(s) from a file then build the rows
		if($restored_layout_from_file == true) {
			$diskcounter = 0;
			foreach($disks_restored as $dr) {
				$custom_txt .= return_rowhelper_row("$diskcounter", $dr['mountpoint'], $dr['fstype'], $dr['disk'], $dr['size'], $dr['encpass']);
				$diskcounter++;
			}
		} else {		
			// Construct the default rows that outline the disks configuration.
			$custom_txt .= return_rowhelper_row("0", "/", "UFS+S", $first_disk, "{$first_disk_size}", "");
			$custom_txt .= return_rowhelper_row("1", "none", "SWAP", $first_disk, "$swap_size", "");
		}

		// tfoot and tbody are used by rowhelper
		$custom_txt .= "</tr>";
		$custom_txt .= "<tfoot></tfoot></tbody>";
		// Total allocation box
		$custom_txt .= "<tr><td></td><td></td><td align='right'>Total allocated:</td><td><input style='border:0px; background-color: #FFFFFF;' size='8' id='totalsize' name='totalsize'></td>";
		// Add row button
		$custom_txt .= "</td><td>&nbsp;</td><td>";
		$custom_txt .= "<div id=\"addrowbutton\">";
		$custom_txt .= "<a onclick=\"javascript:addRowTo('maintable', 'formfldalias'); return false;\" href=\"#\">";
		$custom_txt .= "<img border=\"0\" src=\"/themes/{$g['theme']}/images/icons/icon_plus.gif\" alt=\"\" title=\"add another entry\" /></a>";
		$custom_txt .= "</div>";
		$custom_txt .= "</td></tr>";	
		// Disk capacity box
		$custom_txt .= "<tr><td></td><td></td><td align='right'>Disk(s) capacity total:</td><td><input style='border:0px; background-color: #FFFFFF;' size='8' id='disktotals' name='disktotals'></td></tr>";
		// Remaining allocation box
		$custom_txt .= "<tr><td></td><td></td><td align='right'>Available space for allocation:</td><td><input style='border:0px; background-color: #FFFFFF;' size='8' id='availalloc' name='availalloc'></td></tr>";
		$custom_txt .= "</table>";
		$custom_txt .= "<script type=\"text/javascript\">row_helper_dynamic_custom();</script>";
	}
	echo <<<EOF

												<tr>
													<td colspan='4'>
													<script type="text/javascript">
														\$('#loadingdiv').css('visibility','hidden');
													</script>
													<div id='contentdiv' style="display:none;">
														<p/>
														{$custom_txt}<p/>
														<hr><p/>
														<input type="button" value="Cancel" onClick="javascript:document.location='/installer/installer.php';"> &nbsp;&nbsp
														<input type="submit" value="Next">
													</div>
													<script type="text/javascript">
														var encryption_warning_shown = false;
														\$('#contentdiv').fadeIn();
														row_helper_dynamic_custom();
													</script>
												</center>
												</td></tr>
												</table>
											</div>
			     						</td>
									</tr>
								</table>
								</center>
								<span class="vexpl">
									<span class="red">
										<strong>
											NOTES:
										</strong>
									</span>
									<br />* Sizes are in megabytes.
									<br />* Mount points named /conf are not allowed.  Use /cf if you want to make a configuration slice/mount.
									{$restored_layout_txt}
								</span>
								</strong>
							</div>
						</td>
					</tr>
				</table>
			</div>
			</center>
			<script type="text/javascript">
			<!--
				newrow[1] = "{$select_txt}";
				newrow[2] = "{$custom_disks}";
			-->
			</script>
			

EOF;
	page_table_end();
	end_html();
}

function installer_main() {
	global $g, $fstype, $savemsg;
	if(file_exists("/tmp/.pc-sysinstall/pc-sysinstall.log")) 
		unlink("/tmp/.pc-sysinstall/pc-sysinstall.log");
	head_html();
	body_html();
	$disk = installer_find_first_disk();
	// Only enable ZFS if this exists.  The install will fail otherwise.
	if(file_exists("/boot/gptzfsboot")) 
		$zfs_enabled = "<tr bgcolor=\"#9A9A9A\"><td align=\"center\"><a href=\"installer.php?state=easy_install_zfs\">Easy installation of {$g['product_name']} using the ZFS filesystem on disk {$disk}</a></td></tr>";
	page_table_start();
	echo <<<EOF
		<form action="installer.php" method="post" state="step1_post">
			<div id="mainlevel">
				<center>
				<b><font face="arial" size="+2">Welcome to the {$g['product_name']} webInstaller!</b></font><p/>
				<font face="arial" size="+1">This utility will install {$g['product_name']} to a hard disk, flash drive, etc.</font>
				<table width="100%" border="0" cellpadding="5" cellspacing="0">
			 		<tr>
			    		<td>
							<center>
							<div id="mainarea">
								<br />
								<center>
								Please select an installer option to begin:
								<p/>
								<table width="100%" border="0" cellpadding="5" cellspacing="5">
									<tr>
			     						<td>
											<div id="pfsenseinstaller">
												<center>
EOF;
	if(!$disk) {
		echo gettext("ERROR: Could not find any suitable disks for installation.");
		echo "</div></td></tr></table></div></table></div>";
		end_html();
		exit;
	}
	echo <<<EOF

													<table cellspacing="5" cellpadding="5" style="border: 1px dashed;">
														<tr bgcolor="#CECECE"><td align="center">
															<a href="installer.php?state=easy_install_ufs">Easy installation of {$g['product_name']} using the UFS filesystem on disk {$disk}</a>
														</td></tr>
													 	{$zfs_enabled}
														<tr bgcolor="#AAAAAA"><td align="center">
															<a href="installer.php?state=custominstall">Custom installation of {$g['product_name']}</a>
														</td></tr>
														<tr bgcolor="#CECECE"><td align="center">
															<a href='/'>Cancel and return to Dashboard</a>
														</td></tr>
													</table>
												</center>
											</div>
			     						</td>
									</tr>
								</table>
							</div>
						</td>
					</tr>
				</table>
			</div>
EOF;
	page_table_end();
	end_html();
}

function return_rowhelper_row($rownum, $mountpoint, $fstype, $disk, $size, $encpass) {
		global $g, $select_txt, $custom_disks, $savemsg;
		$release = php_uname("r");
		// Get release number like 8.3 or 10.1
		$relnum = strtok($release, "-");

		// Mount point
		$disks = installer_find_all_disks();
		$custom_txt .= "<tr>";
		$custom_txt .=  "<td><input size='8' id='mountpoint{$rownum}' name='mountpoint{$rownum}' value='{$mountpoint}'></td>";

		// Filesystem type array
		$types = array(
			'UFS' => 'UFS',
			'UFS+S' => 'UFS + Softupdates',
			'UFS.eli' => 'Encrypted UFS',
			'UFS+S.eli' => 'Encrypted UFS + Softupdates',
			'SWAP' => 'SWAP'
		);

		// UFS + Journaling was introduced in 9.0
		if($relnum >= 9) {
			$types['UFS+J'] = "UFS + Journaling";
			$types['UFS+J.eli'] = "Encrypted UFS + Journaling";
		}
		
		// Add ZFS Boot loader if it exists
		if(file_exists("/boot/gptzfsboot")) {
			$types['ZFS'] = "Zetabyte Filesystem";
			$types['ZFS.eli'] = "Encrypted Zetabyte Filesystem";
		}

		// fstype form field
		$custom_txt .=  "<td><select onChange='javascript:row_helper_dynamic_custom()' id='fstype{$rownum}' name='fstype{$rownum}'>";
		$select_txt = "";
		foreach($types as $type => $desc) {
			if($type == $fstype)
				$SELECTED="SELECTED";
			else 
				$SELECTED="";
			$select_txt .= "<option value='$type' $SELECTED>$desc</option>";
		}
		$custom_txt .= "{$select_txt}</select>\n";
		$custom_txt .= "</td>";
		
		// Disk selection form field
		$custom_txt .= "<td><select id='disk{$rownum}' name='disk{$rownum}'>\n";
		$custom_disks = "";
		foreach($disks as $dsk) {
			$disksize_bytes = format_bytes($dsk['size'] * 1048576);
			$disksize = $dsk['size'];
			if($disk == $dsk['disk'])
				$SELECTED="SELECTED";
			else 
				$SELECTED="";
			$custom_disks .= "<option value='{$dsk['disk']}' $SELECTED>{$dsk['disk']} - {$dsk['desc']} - {$disksize}MB ({$disksize_bytes})</option>";
		}
		$custom_txt .= "{$custom_disks}</select></td>\n";

		// Slice size
		$custom_txt .= "<td><input onChange='javascript:row_helper_dynamic_custom();' name='size{$rownum}' id='size{$rownum}' size='8' type='text' value='{$size}'></td>";

		// Encryption password
		$custom_txt .= "<td>";
		$custom_txt .= "<input id='encpass{$rownum}' name='encpass{$rownum}' size='8' value='{$encpass}'>";
		$custom_txt .= "</td>";
	
		// Add Rowhelper + button
		if($rownum > 0) 
			$custom_txt .= "<td><a onclick=\"removeRow(this); return false;\" href=\"#\"><img border=\"0\" src=\"/themes/{$g['theme']}/images/icons/icon_x.gif\" alt=\"\" title=\"remove this entry\"/></a></td>";

		$custom_txt .= "</tr>";	
		return $custom_txt;
}

?>
