<?php
/* $Id$ */
/*
	system_gateways.php
	part of pfSense (https://www.pfsense.org)

	Copyright (C) 2010 Seth Mos <seth.mos@dds.nl>.
	Copyright (C) 2013-2015 Electric Sheep Fencing, LP
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
/*
	pfSense_MODULE:	routing
*/

##|+PRIV
##|*IDENT=page-system-gateways
##|*NAME=System: Gateways page
##|*DESCR=Allow access to the 'System: Gateways' page.
##|*MATCH=system_gateways.php*
##|-PRIV

require("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");

$a_gateways = return_gateways_array(true, false, true);
$a_gateways_arr = array();
foreach ($a_gateways as $gw)
	$a_gateways_arr[] = $gw;
$a_gateways = $a_gateways_arr;

if (!is_array($config['gateways']['gateway_item']))
	$config['gateways']['gateway_item'] = array();

$a_gateway_item = &$config['gateways']['gateway_item'];

if ($_POST) {

	$pconfig = $_POST;

	if ($_POST['apply']) {

		$retval = 0;

		$retval = system_routing_configure();
		$retval |= filter_configure();
		/* reconfigure our gateway monitor */
		setup_gateways_monitor();

		$savemsg = get_std_save_message($retval);
		if ($retval == 0)
			clear_subsystem_dirty('staticroutes');
	}
}

function can_delete_gateway_item($id) {
	global $config, $input_errors, $a_gateways;

	if (!isset($a_gateways[$id]))
		return false;

	if (is_array($config['gateways']['gateway_group'])) {
		foreach ($config['gateways']['gateway_group'] as $group) {
			foreach ($group['item'] as $item) {
				$items = explode("|", $item);
				if ($items[0] == $a_gateways[$id]['name']) {
					$input_errors[] = sprintf(gettext("Gateway '%s' cannot be deleted because it is in use on Gateway Group '%s'"), $a_gateways[$id]['name'], $group['name']);
					break;
				}
			}
		}
	}

	if (is_array($config['staticroutes']['route'])) {
		foreach ($config['staticroutes']['route'] as $route) {
			if ($route['gateway'] == $a_gateways[$id]['name']) {
				$input_errors[] = sprintf(gettext("Gateway '%s' cannot be deleted because it is in use on Static Route '%s'"), $a_gateways[$id]['name'], $route['network']);
				break;
			}
		}
	}

	if (isset($input_errors))
		return false;

	return true;
}

function delete_gateway_item($id) {
	global $config, $a_gateways;

	if (!isset($a_gateways[$id]))
		return;

	/* NOTE: Cleanup static routes for the monitor ip if any */
	if (!empty($a_gateways[$id]['monitor']) &&
		$a_gateways[$id]['monitor'] != "dynamic" &&
		is_ipaddr($a_gateways[$id]['monitor']) &&
		$a_gateways[$id]['gateway'] != $a_gateways[$id]['monitor']) {
		if (is_ipaddrv4($a_gateways[$id]['monitor']))
			mwexec("/sbin/route delete " . escapeshellarg($a_gateways[$id]['monitor']));
		else
			mwexec("/sbin/route delete -inet6 " . escapeshellarg($a_gateways[$id]['monitor']));
	}

	if ($config['interfaces'][$a_gateways[$id]['friendlyiface']]['gateway'] == $a_gateways[$id]['name'])
		unset($config['interfaces'][$a_gateways[$id]['friendlyiface']]['gateway']);
	unset($config['gateways']['gateway_item'][$a_gateways[$id]['attribute']]);
}

unset($input_errors);
if ($_GET['act'] == "del") {
	if (can_delete_gateway_item($_GET['id'])) {
		$realid = $a_gateways[$_GET['id']]['attribute'];
		delete_gateway_item($_GET['id']);
		write_config("Gateways: removed gateway {$realid}");
		mark_subsystem_dirty('staticroutes');
		header("Location: system_gateways.php");
		exit;
	}
}

if (isset($_POST['del_x'])) {
	/* delete selected items */
	if (is_array($_POST['rule']) && count($_POST['rule'])) {
		foreach ($_POST['rule'] as $rulei)
			if(!can_delete_gateway_item($rulei))
				break;

		if (!isset($input_errors)) {
			$items_deleted = "";
			foreach ($_POST['rule'] as $rulei) {
				delete_gateway_item($rulei);
				$items_deleted .= "{$rulei} ";
			}
			if (!empty($items_deleted)) {
				write_config("Gateways: removed gateways {$items_deleted}");
				mark_subsystem_dirty('staticroutes');
			}
			header("Location: system_gateways.php");
			exit;
		}
	}

} else if ($_GET['act'] == "toggle" && $a_gateways[$_GET['id']]) {
	$realid = $a_gateways[$_GET['id']]['attribute'];

	if(isset($a_gateway_item[$realid]['disabled']))
		unset($a_gateway_item[$realid]['disabled']);
	else
		$a_gateway_item[$realid]['disabled'] = true;

	if (write_config("Gateways: enable/disable"))
		mark_subsystem_dirty('staticroutes');

	header("Location: system_gateways.php");
	exit;
}

$pgtitle = array(gettext("System"),gettext("Gateways"));
$shortcut_section = "gateways";

include("head.inc");

if ($input_errors)
	print_input_errors($input_errors);
if ($savemsg)
	print_info_box($savemsg);
if (is_subsystem_dirty('staticroutes'))
	print_info_box_np(gettext("The gateway configuration has been changed.") . "<br />" . gettext("You must apply the changes in order for them to take effect."));

$tab_array = array();
$tab_array[0] = array(gettext("Gateways"), true, "system_gateways.php");
$tab_array[1] = array(gettext("Routes"), false, "system_routes.php");
$tab_array[2] = array(gettext("Groups"), false, "system_gateway_groups.php");
display_top_tabs($tab_array);

?>
<table class="table">
<thead>
	<tr>
		<th></th>
		<th><?=gettext("Name")?></th>
		<th><?=gettext("Interface")?></th>
		<th><?=gettext("Gateway")?></th>
		<th><?=gettext("Monitor IP")?></th>
		<th><?=gettext("Description")?></th>
		<th></th>
	</tr>
</thead>
<tbody>
<?php
foreach ($a_gateways as $i => $gateway):
	if (isset($gateway['inactive']))
		$icon = 'icon-remove-circle';
	elseif (isset($gateway['disabled']))
		$icon = 'icon-ban-circle';
	else
		$icon = 'icon-ok-circle';

	if (isset($gateway['inactive']))
		$title = gettext("This gateway is inactive because interface is missing");
	else
		$title = '';
?>
	<tr<?=($icon != 'icon-ok-circle')? ' class="disabled"' : ''?>>
		<td title="<?=$title?>"><i class="icon <?=$icon?>"></i></td>
		<td>
			<?=$gateway['name']?>
<?php
			if (isset($gateway['defaultgw']))
				echo " <strong>(default)</strong>";
?>
		</td>
		<td>
			<?=htmlspecialchars(convert_friendly_interface_to_friendly_descr($gateway['friendlyiface']))?>
		</td>
		<td>
			<?=$gateway['gateway']?>
		</td>
		<td>
			<?=htmlspecialchars($gateway['monitor'])?>
		</td>
		<td>
			<?=htmlspecialchars($gateway['descr'])?>
		</td>
		<td>
			<a class="btn btn-xs btn-primary" href="system_gateways_edit.php?id=<?=$i?>">
				edit
			</a>
			<a class="btn btn-xs btn-default" href="system_gateways_edit.php?dup=<?=$i?>">
				copy
			</a>
<? if (is_numeric($gateway['attribute'])): ?>
			<a class="btn btn-xs btn-danger" href="system_gateways.php?act=del&amp;id=<?=$i?>" onclick="return confirm('<?=gettext("Do you really want to delete this gateway?")?>')">
				delete
			</a>
			<a class="btn btn-xs btn-default" href="?act=toggle&amp;id=<?=$i?>">
				toggle
			</a>
<? endif?>
		</td>
	</tr>
<? endforeach?>
</tbody>
</table>

<nav class="action-buttons">
	<a href="system_gateways_edit.php" role="button" class="btn btn-success">
		<?=gettext("edit default");?>
	</a>
</nav>
<?php

include("foot.inc");