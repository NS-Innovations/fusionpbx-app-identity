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
	if (!permission_exists('identity_log_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//set variables from http GET parameters
	$page = is_numeric($_GET['page'] ?? '') ? $_GET['page'] : 0;
	$order_by = preg_replace('#[^a-zA-Z0-9_\-]#', '', $_GET['order_by'] ?? '');
	if (!in_array($order_by, ['domain_name', 'insert_date', 'identity_log_type', 'identity_log_status', 'identity_log_attest', 'identity_log_source', 'identity_log_destination', 'identity_log_brand', 'identity_log_certificate_url', 'identity_log_message'], true)) {
		$order_by = '';
	}
	$order = ($_GET['order'] ?? '') === 'asc' ? 'asc' : 'desc';
	$search = $_GET['search'] ?? '';
	$show = $_GET['show'] ?? '';
	$type = in_array($_GET['type'] ?? '', ['sign', 'verify'], true) ? $_GET['type'] : '';
	$status = in_array($_GET['status'] ?? '', ['signed', 'passed', 'failed'], true) ? $_GET['status'] : '';

//build the query string
	$param = [];
	if (!empty($page)) {
		$param['page'] = $page;
	}
	if (!empty($_GET['order_by'])) {
		$param['order_by'] = $order_by;
	}
	if (!empty($_GET['order'])) {
		$param['order'] = $order;
	}
	if (!empty($search)) {
		$param['search'] = $search;
	}
	if (!empty($type)) {
		$param['type'] = $type;
	}
	if (!empty($status)) {
		$param['status'] = $status;
	}
	if (!empty($show) && $show == 'all' && permission_exists('identity_log_all')) {
		$param['show'] = $show;
	}
	$query_string = http_build_query($param);

//get posted data
	if (!empty($_POST['identity_logs']) && is_array($_POST['identity_logs'])) {
		$action = $_POST['action'];
		$identity_logs = $_POST['identity_logs'];
	}

//process the http post data by action
	if (!empty($action) && !empty($identity_logs)) {
		switch ($action) {
			case 'delete':
				if (permission_exists('identity_log_delete')) {
					$obj = new identity_logs;
					$obj->delete($identity_logs);
				}
				break;
		}

		header('Location: identity_logs.php'.($query_string ? '?'.$query_string : ''));
		exit;
	}

//build the where clause, verify logs have no domain because inbound calls are verified before the domain is known
	$sql_where = "where true ";
	if ($show != "all" || !permission_exists('identity_log_all')) {
		$sql_where .= "and (l.domain_uuid = :domain_uuid or l.domain_uuid is null) ";
		$parameters['domain_uuid'] = $domain_uuid;
	}
	if (!empty($type)) {
		$sql_where .= "and l.identity_log_type = :type ";
		$parameters['type'] = $type;
	}
	if (!empty($status)) {
		$sql_where .= "and l.identity_log_status = :status ";
		$parameters['status'] = $status;
	}
	if (!empty($search)) {
		$sql_where .= "and (";
		$sql_where .= "	l.identity_log_source like :search ";
		$sql_where .= "	or l.identity_log_destination like :search ";
		$sql_where .= "	or lower(l.identity_log_brand) like :search ";
		$sql_where .= "	or lower(l.identity_log_certificate_url) like :search ";
		$sql_where .= "	or lower(l.identity_log_origid) like :search ";
		$sql_where .= "	or lower(l.identity_log_message) like :search ";
		$sql_where .= "	or cast(l.identity_log_call_uuid as text) like :search ";
		$sql_where .= ") ";
		$parameters['search'] = '%'.lower_case($search).'%';
	}

//get the count
	$sql = "select count(l.identity_log_uuid) from v_identity_logs as l ";
	$sql .= $sql_where;
	$num_rows = $database->select($sql, $parameters ?? null, 'column');
	unset($sql);

//prepare to page the results
	$rows_per_page = $settings->get('domain', 'paging', 50);
	list($paging_controls, $rows_per_page) = paging($num_rows, $query_string, $rows_per_page);
	list($paging_controls_mini, $rows_per_page) = paging($num_rows, $query_string, $rows_per_page, true);
	$offset = $rows_per_page * $page;

//set the time zone
	$time_zone = $settings->get('domain', 'time_zone', date_default_timezone_get());

//set the time format options: 12h, 24h
	if ($settings->get('domain', 'time_format') == '24h') {
		$time_format = 'HH24:MI:SS';
	}
	else {
		$time_format = 'HH12:MI:SS am';
	}

//get the list
	$sql = "select l.identity_log_uuid, l.domain_uuid, d.domain_name, l.identity_log_call_uuid, ";
	$sql .= "l.identity_log_type, l.identity_log_status, l.identity_log_attest, ";
	$sql .= "l.identity_log_source, l.identity_log_destination, l.identity_log_brand, l.identity_log_certificate_url, ";
	$sql .= "l.identity_log_message, l.insert_date, ";
	$sql .= "to_char(timezone(:time_zone, l.insert_date), 'DD Mon YYYY') as date_formatted, ";
	$sql .= "to_char(timezone(:time_zone, l.insert_date), '".$time_format."') as time_formatted ";
	$sql .= "from v_identity_logs as l ";
	$sql .= "left join v_domains as d on d.domain_uuid = l.domain_uuid ";
	$sql .= $sql_where;
	$sql .= order_by($order_by, $order, 'insert_date', 'desc');
	$sql .= limit_offset($rows_per_page, $offset);
	$parameters['time_zone'] = $time_zone;
	$identity_logs = $database->select($sql, $parameters, 'all');
	unset($sql, $sql_where, $parameters);

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-identity_logs'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-identity_logs']."</b><div class='count'>".number_format($num_rows)."</div></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','link'=>'identity.php']);
	if (permission_exists('identity_log_delete') && $identity_logs) {
		echo button::create(['type'=>'button','label'=>$text['button-delete'],'icon'=>$settings->get('theme', 'button_icon_delete'),'id'=>'btn_delete','name'=>'btn_delete','style'=>'display: none;','onclick'=>"modal_open('modal-delete','btn_delete');"]);
	}
	echo "		<form id='form_search' class='inline' method='get'>\n";
	foreach ($param as $key => $value) {
		if (in_array($key, ['order_by', 'order', 'show'])) {
			echo "		<input type='hidden' name='".escape($key)."' value='".escape($value)."'>\n";
		}
	}
	if ($show !== 'all' && permission_exists('identity_log_all')) {
		echo button::create(['type'=>'button','label'=>$text['button-show_all'],'icon'=>$settings->get('theme', 'button_icon_all'),'link'=>'?show=all']);
	}
	echo "		<select class='formfld' name='type' style='margin-left: 15px;' onchange=\"this.form.submit();\">\n";
	echo "			<option value=''>".$text['label-type']."...</option>\n";
	echo "			<option value='sign' ".($type == 'sign' ? "selected='selected'" : null).">".$text['option-sign']."</option>\n";
	echo "			<option value='verify' ".($type == 'verify' ? "selected='selected'" : null).">".$text['option-verify']."</option>\n";
	echo "		</select>\n";
	echo "		<select class='formfld' name='status' onchange=\"this.form.submit();\">\n";
	echo "			<option value=''>".$text['label-status']."...</option>\n";
	foreach (['signed', 'passed', 'failed'] as $option) {
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

	if (permission_exists('identity_log_delete') && $identity_logs) {
		echo modal::create(['id'=>'modal-delete','type'=>'delete','actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_delete','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_action_set('delete'); list_form_submit('form_list');"])]);
	}

	echo $text['description-identity_logs']."\n";
	echo "<br /><br />\n";

	echo "<form id='form_list' method='post'>\n";
	echo "<input type='hidden' id='action' name='action' value=''>\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	if (permission_exists('identity_log_delete')) {
		echo "	<th class='checkbox'>\n";
		echo "		<input type='checkbox' id='checkbox_all' name='checkbox_all' onclick='list_all_toggle(); checkbox_on_change(this);' ".(empty($identity_logs) ? "style='visibility: hidden;'" : null).">\n";
		echo "	</th>\n";
	}
	if ($show == "all" && permission_exists('identity_log_all')) {
		echo th_order_by('domain_name', $text['label-domain'], $order_by, $order, null, null, $query_string);
	}
	echo th_order_by('insert_date', $text['label-date'], $order_by, $order, null, null, $query_string);
	echo "<th class='hide-md-dn'>".$text['label-time']."</th>\n";
	echo th_order_by('identity_log_type', $text['label-type'], $order_by, $order, null, null, $query_string);
	echo th_order_by('identity_log_status', $text['label-status'], $order_by, $order, null, null, $query_string);
	echo th_order_by('identity_log_attest', $text['label-attest'], $order_by, $order, null, "class='center'", $query_string);
	echo th_order_by('identity_log_source', $text['label-source'], $order_by, $order, null, null, $query_string);
	echo th_order_by('identity_log_destination', $text['label-destination'], $order_by, $order, null, null, $query_string);
	echo th_order_by('identity_log_brand', $text['label-brand'], $order_by, $order, null, "class='hide-sm-dn'", $query_string);
	echo th_order_by('identity_log_certificate_url', $text['label-certificate_url'], $order_by, $order, null, "class='hide-md-dn'", $query_string);
	echo th_order_by('identity_log_message', $text['label-message'], $order_by, $order, null, "class='hide-sm-dn'", $query_string);
	echo "</tr>\n";

	if (is_array($identity_logs) && @sizeof($identity_logs) != 0) {
		$x = 0;
		foreach ($identity_logs as $row) {
			$list_row_url = '';
			if (!empty($row['identity_log_call_uuid']) && permission_exists('xml_cdr_details')) {
				$list_row_url = PROJECT_PATH."/app/xml_cdr/xml_cdr_details.php?id=".urlencode($row['identity_log_call_uuid']);
			}
			echo "<tr class='list-row'".(!empty($list_row_url) ? " href='".$list_row_url."'" : null).">\n";
			if (permission_exists('identity_log_delete')) {
				echo "	<td class='checkbox'>\n";
				echo "		<input type='checkbox' name='identity_logs[$x][checked]' id='checkbox_".$x."' value='true' onclick=\"checkbox_on_change(this); if (!this.checked) { document.getElementById('checkbox_all').checked = false; }\">\n";
				echo "		<input type='hidden' name='identity_logs[$x][uuid]' value='".escape($row['identity_log_uuid'])."' />\n";
				echo "	</td>\n";
			}
			if ($show == "all" && permission_exists('identity_log_all')) {
				echo "	<td>".escape($row['domain_name'] ?? $text['label-global'])."</td>\n";
			}
			echo "	<td class='no-wrap'>".escape($row['date_formatted'])."</td>\n";
			echo "	<td class='no-wrap hide-md-dn'>".escape($row['time_formatted'])."</td>\n";
			echo "	<td>".$text['option-'.$row['identity_log_type']]."</td>\n";
			echo "	<td>".$text['option-'.$row['identity_log_status']]."</td>\n";
			echo "	<td class='center'>".escape($row['identity_log_attest'])."&nbsp;</td>\n";
			echo "	<td>".escape(format_phone($row['identity_log_source']))."&nbsp;</td>\n";
			echo "	<td>".escape(format_phone($row['identity_log_destination']))."&nbsp;</td>\n";
			echo "	<td class='hide-sm-dn'>".escape($row['identity_log_brand'])."&nbsp;</td>\n";
			echo "	<td class='overflow hide-md-dn'>".escape($row['identity_log_certificate_url'])."&nbsp;</td>\n";
			echo "	<td class='description overflow hide-sm-dn'>".escape($row['identity_log_message'])."&nbsp;</td>\n";
			echo "</tr>\n";
			$x++;
		}
		unset($identity_logs);
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
