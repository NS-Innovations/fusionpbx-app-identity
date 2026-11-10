<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Portions created by the Initial Developer are Copyright (C) 2026
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
	Norman King <norman@nsinnovations.net>
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";
	require_once "resources/paging.php";

//check permissions
	if (!permission_exists('identity_setup')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//set the defaults
	$setup = new identity_setup(['database' => $database, 'config' => $config, 'domain_uuid' => $domain_uuid]);
	$identity_settings = $setup->settings();
	$sign_all = $identity_settings['sign_all_routes'];

//set variables from http GET parameters
	$page = is_numeric($_GET['page'] ?? '') ? $_GET['page'] : 0;
	$order_by = in_array($_GET['order_by'] ?? '', ['domain_name', 'dialplan_name', 'dialplan_enabled', 'signed'], true) ? $_GET['order_by'] : '';
	$order = ($_GET['order'] ?? '') === 'desc' ? 'desc' : 'asc';
	$search = is_string($_GET['search'] ?? null) ? $_GET['search'] : '';
	$show = $_GET['show'] ?? '';
	$show_all = $show == 'all' && permission_exists('identity_setup_all');
	$statuses = ['signed', 'unsigned', 'excluded'];
	$status = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : '';

//build the query string
	$param = [];
	if (!empty($page)) {
		$param['page'] = $page;
	}
	if (!empty($order_by)) {
		$param['order_by'] = $order_by;
	}
	if (!empty($_GET['order'])) {
		$param['order'] = $order;
	}
	if (!empty($search)) {
		$param['search'] = $search;
	}
	if (!empty($status)) {
		$param['status'] = $status;
	}
	if ($show_all) {
		$param['show'] = 'all';
	}
	$query_string = http_build_query($param);

//the filters of the list, an excluded route is listed as excluded whether sign all routes is on or not
	$filter = ['search' => $search, 'status' => $status];

//get posted data
	if (!empty($_POST['routes']) && is_array($_POST['routes'])) {
		$action = $_POST['action'] ?? '';
		$routes = $_POST['routes'];
	}

//process the http post data by action
	if (!empty($action) && !empty($routes)) {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'],'negative');
				header('Location: identity_routes.php'.($query_string ? '?'.$query_string : ''));
				exit;
			}

		//run the action
			switch ($action) {
				case 'add':
					identity_setup::signing_messages($setup->sign_routes($routes, $show_all), $text);
					break;
				case 'add_all':
					identity_setup::signing_messages($setup->sign_unsigned_routes($routes, $show_all, ['search' => $search]), $text);
					break;
				case 'remove':
					$result = $setup->unsign_routes($routes, $show_all);
					if ($result['removed'] > 0) {
						message::add($text['message-signing_removed_all'].' '.$result['removed']);
					}
					if ($result['excluded'] > 0) {
						message::add($text['message-signing_excluded_all'].' '.$result['excluded']);
					}
					if ($result['failed'] > 0) {
						message::add($text['message-signing_remove_failed_all'].' '.$result['failed'], 'negative', 5000);
					}
					break;
			}

		//redirect the user
			header('Location: identity_routes.php'.($query_string ? '?'.$query_string : ''));
			exit;
	}

//sign the new routes of every domain when it is due, only for platform administrators since it changes every domain
	if ($sign_all && permission_exists('identity_setup_all')) {
		identity_setup::sign_all_messages(identity_setup::sign_all_routes_if_due($database, (string)$settings->get('cache', 'location', '/var/cache/fusionpbx')), $text);
	}

//get the count
	$num_rows = $setup->outbound_route_count($show_all, $filter);
	$num_addable = $setup->outbound_route_count($show_all, ['search' => $search, 'status' => 'unsigned']);

//prepare to page the results
	$rows_per_page = $settings->get('domain', 'paging', 50);
	list($paging_controls, $rows_per_page) = paging($num_rows, $query_string, $rows_per_page);
	list($paging_controls_mini, $rows_per_page) = paging($num_rows, $query_string, $rows_per_page, true);
	$offset = $rows_per_page * $page;

//get the list
	$routes = $setup->outbound_routes($show_all, $filter + ['order_by' => $order_by, 'order' => $order, 'limit' => $rows_per_page, 'offset' => $offset]);

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-identity_routes'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-identity_routes']."</b><div class='count'>".number_format($num_rows)."</div></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','link'=>'identity.php'.($show_all ? '?show=all' : '')]);
	if ($routes) {
		echo button::create(['type'=>'button','label'=>$text['button-add'],'icon'=>$settings->get('theme', 'button_icon_add'),'id'=>'btn_add_signing','name'=>'btn_add_signing','class'=>'+revealed','style'=>'display: none; margin-left: 15px;','onclick'=>"list_action_set('add'); list_form_submit('form_list');"]);
		echo button::create(['type'=>'button','label'=>$text['button-remove'],'icon'=>$settings->get('theme', 'button_icon_delete'),'id'=>'btn_remove_signing','name'=>'btn_remove_signing','class'=>'+revealed','style'=>'display: none;','onclick'=>"if (confirm('".$text['confirm-remove_signing_checked']."')) { list_action_set('remove'); list_form_submit('form_list'); }"]);
	}
	if ($routes && $num_addable > 0 && in_array($status, ['', 'unsigned'], true)) {
		echo button::create(['type'=>'button','label'=>$text['button-add_all'],'icon'=>$settings->get('theme', 'button_icon_add'),'id'=>'btn_add_signing_all','style'=>'margin-left: 15px;','onclick'=>"if (confirm('".$text['confirm-add_signing_all']."')) { list_action_set('add_all'); list_form_submit('form_list'); }"]);
	}
	echo "		<form id='form_search' class='inline' method='get'>\n";
	foreach ($param as $key => $value) {
		if (in_array($key, ['order_by', 'order', 'show'])) {
			echo "		<input type='hidden' name='".escape($key)."' value='".escape($value)."'>\n";
		}
	}
	if (!$show_all && permission_exists('identity_setup_all')) {
		echo button::create(['type'=>'button','label'=>$text['button-show_all'],'icon'=>$settings->get('theme', 'button_icon_all'),'style'=>'margin-left: 15px;','link'=>'?show=all']);
	}
	echo "		<select class='formfld' name='status' style='margin-left: 15px;' onchange=\"this.form.submit();\">\n";
	echo "			<option value=''>".$text['label-signing']."...</option>\n";
	foreach ($statuses as $option) {
		echo "			<option value='".$option."' ".($status == $option ? "selected='selected'" : null).">".$text['option-'.$option]."</option>\n";
	}
	echo "		</select>\n";
	echo "		<input type='text' class='txt list-search' name='search' id='search' value=\"".escape($search)."\" placeholder=\"".$text['label-search']."\" onkeydown=''>";
	echo button::create(['label'=>$text['button-search'],'icon'=>$settings->get('theme', 'button_icon_search'),'type'=>'submit','id'=>'btn_search']);
	if ($paging_controls_mini != '') {
		echo "	<span style='margin-left: 15px;'>".$paging_controls_mini."</span>";
	}
	echo "		</form>\n";
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-identity_routes']."\n";
	echo "<br /><br />\n";

	echo "<form id='form_list' method='post'>\n";
	echo "<input type='hidden' id='action' name='action' value=''>\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th class='checkbox'>\n";
	echo "		<input type='checkbox' id='checkbox_all' name='checkbox_all' onclick='list_all_toggle(); checkbox_on_change(this);' ".(empty($routes) ? "style='visibility: hidden;'" : null).">\n";
	echo "	</th>\n";
	if ($show_all) {
		echo th_order_by('domain_name', $text['label-domain'], $order_by, $order, null, null, $query_string);
	}
	echo th_order_by('dialplan_name', $text['label-name'], $order_by, $order, null, null, $query_string);
	echo th_order_by('dialplan_enabled', $text['label-enabled'], $order_by, $order, null, "class='center'", $query_string);
	echo th_order_by('signed', $text['label-signing'], $order_by, $order, null, null, $query_string);
	echo "	<th>".$text['label-destination']."</th>\n";
	echo "	<th>".$text['label-attest']."</th>\n";
	echo "	<td class='action-button'>&nbsp;</td>\n";
	echo "</tr>\n";

	if (is_array($routes) && @sizeof($routes) != 0) {
		$x = 0;
		foreach ($routes as $route) {
			$uuid = $route['dialplan_uuid'];
			echo "<tr class='list-row'>\n";
			echo "	<td class='checkbox'>\n";
			echo "		<input type='checkbox' name='routes[$x][checked]' id='checkbox_".$x."' value='true' onclick=\"checkbox_on_change(this); if (!this.checked) { document.getElementById('checkbox_all').checked = false; }\">\n";
			echo "		<input type='hidden' name='routes[$x][uuid]' value='".escape($uuid)."' />\n";
			echo "	</td>\n";
			if ($show_all) {
				echo "	<td>".escape($route['domain_name'] ?? $text['label-global'])."</td>\n";
			}
			echo "	<td><a href='".PROJECT_PATH."/app/dialplans/dialplan_edit.php?id=".urlencode($uuid)."&app_uuid=".urlencode(identity_setup::outbound_route_app_uuid)."'>".escape($route['dialplan_name'])."</a></td>\n";
			echo "	<td class='center'>".$text['label-'.$route['dialplan_enabled']]."</td>\n";
			if ($route['signed']) {
				echo "	<td>".$text['label-yes']."</td>\n";
				echo "	<td>".escape($route['destination'])."</td>\n";
				echo "	<td>".escape($route['attest_level'])."</td>\n";
				echo "	<td class='action-button'>";
				echo button::create(['type'=>'button','label'=>$text['button-remove'],'icon'=>$settings->get('theme', 'button_icon_delete'),'onclick'=>"if (confirm('".$text['confirm-remove_signing']."')) { list_self_check('checkbox_".$x."'); list_action_set('remove'); list_form_submit('form_list'); }"]);
				echo "</td>\n";
			}
			else {
				echo "	<td>".($route['excluded'] ? $text['label-excluded'] : $text['label-no'])."</td>\n";
				//leave the destination empty when it was not found in the bridge, so it is not signed with a guess
				echo "	<td><input class='formfld' type='text' name='routes[$x][destination]' maxlength='16' style='width: 80px;' placeholder=\"".$text['label-destination_required']."\" value=\"".($route['destination_found'] ? escape($route['destination']) : '')."\"></td>\n";
				echo "	<td>\n";
				echo "		<select class='formfld' name='routes[$x][attest_level]'>\n";
				foreach (['A', 'B', 'C'] as $level) {
					echo "			<option value='".$level."' ".($route['attest_level'] == $level ? "selected='selected'" : null).">".$level."</option>\n";
				}
				echo "		</select>\n";
				echo "	</td>\n";
				echo "	<td class='action-button'>";
				echo button::create(['type'=>'button','label'=>$text['button-add'],'icon'=>$settings->get('theme', 'button_icon_add'),'onclick'=>"list_self_check('checkbox_".$x."'); list_action_set('add'); list_form_submit('form_list');"]);
				echo "</td>\n";
			}
			echo "</tr>\n";
			$x++;
		}
		unset($routes);
	}
	else {
		echo "<tr class='list-row'><td colspan='".($show_all ? 8 : 7)."'>".$text['message-no_routes']."</td></tr>\n";
	}

	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";
	echo "<div align='center'>".$paging_controls."</div>\n";

	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>
