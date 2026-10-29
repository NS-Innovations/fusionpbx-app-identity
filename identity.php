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

//check permissions
	if (!permission_exists('identity_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//set the defaults
	$periods = [
		'day' => ['interval' => '24 hours', 'unit' => 'hour', 'format' => 'DD Mon HH24:00'],
		'week' => ['interval' => '7 days', 'unit' => 'day', 'format' => 'DD Mon YYYY'],
		'month' => ['interval' => '30 days', 'unit' => 'day', 'format' => 'DD Mon YYYY'],
	];
	$summary = [];
	$activity = [];
	$failures = [];

//get the http get values
	$period = array_key_exists($_GET['period'] ?? '', $periods) ? $_GET['period'] : 'week';
	$show = $_GET['show'] ?? '';
	$show_all = $show == 'all' && permission_exists('identity_log_all');

//build the query string
	$param = ['period' => $period];
	if ($show_all) {
		$param['show'] = 'all';
	}
	$query_string = http_build_query($param);

//set the time zone
	$time_zone = $settings->get('domain', 'time_zone', date_default_timezone_get());

//get the setup status
	$setup = new identity_setup(['database' => $database, 'config' => $config, 'domain_uuid' => $domain_uuid]);
	$identity_settings = $setup->settings();
	$identity = new identity($identity_settings);
	$status = $identity->certificate_status();
	$routes = $setup->outbound_routes($show_all);
	$routes_signed = count(array_filter($routes, function($route) { return $route['signed']; }));
	$verify_dialplan = $setup->verify_dialplan();
	$table_exists = $database->table_exists('v_identity_logs');

//get the statistics
	if ($table_exists) {
		//build the where clause, verify logs have no domain because inbound calls are verified before the domain is known
		$sql_where = "where insert_date >= now() - cast(:interval as interval) ";
		$parameters['interval'] = $periods[$period]['interval'];
		if (!$show_all) {
			$sql_where .= "and (domain_uuid = :domain_uuid or domain_uuid is null) ";
			$parameters['domain_uuid'] = $domain_uuid;
		}

		//get the totals by type, status and attestation
		$sql = "select identity_log_type, identity_log_status, identity_log_attest, count(*) as total, ";
		$sql .= "count(*) filter (where identity_log_brand is not null and identity_log_brand <> '') as branded ";
		$sql .= "from v_identity_logs ";
		$sql .= $sql_where;
		$sql .= "group by identity_log_type, identity_log_status, identity_log_attest ";
		$rows = $database->select($sql, $parameters, 'all');
		unset($sql);
		foreach (['sign', 'verify'] as $type) {
			$summary[$type] = ['total' => 0, 'success' => 0, 'failed' => 0, 'A' => 0, 'B' => 0, 'C' => 0, 'branded' => 0];
		}
		if (is_array($rows) && @sizeof($rows) != 0) {
			foreach ($rows as $row) {
				$type = $row['identity_log_type'];
				if (!isset($summary[$type])) {
					continue;
				}
				$summary[$type]['total'] += $row['total'];
				if ($row['identity_log_status'] == 'failed') {
					$summary[$type]['failed'] += $row['total'];
				}
				else {
					$summary[$type]['success'] += $row['total'];
					$summary[$type]['branded'] += $row['branded'];
					if (isset($summary[$type][$row['identity_log_attest']])) {
						$summary[$type][$row['identity_log_attest']] += $row['total'];
					}
				}
			}
		}
		unset($rows, $row);

		//get the activity by hour or day
		$sql = "select to_char(date_trunc('".$periods[$period]['unit']."', timezone(:time_zone, insert_date)), '".$periods[$period]['format']."') as period, ";
		$sql .= "count(*) filter (where identity_log_type = 'sign' and identity_log_status = 'signed') as signed, ";
		$sql .= "count(*) filter (where identity_log_type = 'sign' and identity_log_status = 'failed') as sign_failed, ";
		$sql .= "count(*) filter (where identity_log_type = 'verify' and identity_log_status = 'passed') as passed, ";
		$sql .= "count(*) filter (where identity_log_type = 'verify' and identity_log_status = 'failed') as verify_failed ";
		$sql .= "from v_identity_logs ";
		$sql .= $sql_where;
		$sql .= "group by date_trunc('".$periods[$period]['unit']."', timezone(:time_zone, insert_date)) ";
		$sql .= "order by date_trunc('".$periods[$period]['unit']."', timezone(:time_zone, insert_date)) desc ";
		$parameters['time_zone'] = $time_zone;
		$activity = $database->select($sql, $parameters, 'all');
		unset($sql);

		//get the most recent failures
		$sql = "select identity_log_type, identity_log_source, identity_log_destination, identity_log_message, ";
		$sql .= "to_char(timezone(:time_zone, insert_date), 'DD Mon YYYY HH24:MI:SS') as date_formatted ";
		$sql .= "from v_identity_logs ";
		$sql .= $sql_where;
		$sql .= "and identity_log_status = 'failed' ";
		$sql .= "order by insert_date desc ";
		$sql .= limit_offset(10, 0);
		$failures = $database->select($sql, $parameters, 'all');
		unset($sql, $sql_where, $parameters);
	}

//show the header
	$document['title'] = $text['title-identity'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-identity']."</b></div>\n";
	echo "	<div class='actions'>\n";
	if (permission_exists('identity_log_view')) {
		echo button::create(['type'=>'button','label'=>$text['button-logs'],'icon'=>$settings->get('theme', 'button_icon_view'),'id'=>'btn_logs','link'=>'identity_logs.php'.($show_all ? '?show=all' : '')]);
	}
	if (permission_exists('identity_setup')) {
		echo button::create(['type'=>'button','label'=>$text['button-setup'],'icon'=>$settings->get('theme', 'button_icon_settings'),'id'=>'btn_setup','link'=>'identity_setup.php'.($show_all ? '?show=all' : '')]);
	}
	echo "		<form id='form_period' class='inline' method='get'>\n";
	if ($show_all) {
		echo "		<input type='hidden' name='show' value='all'>\n";
	}
	else if (permission_exists('identity_log_all')) {
		echo button::create(['type'=>'button','label'=>$text['button-show_all'],'icon'=>$settings->get('theme', 'button_icon_all'),'link'=>'?show=all&period='.urlencode($period)]);
	}
	echo "		<select class='formfld' name='period' style='margin-left: 15px;' onchange=\"this.form.submit();\">\n";
	foreach (array_keys($periods) as $option) {
		echo "			<option value='".$option."' ".($period == $option ? "selected='selected'" : null).">".$text['option-period_'.$option]."</option>\n";
	}
	echo "		</select>\n";
	echo "		</form>\n";
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-identity']."\n";
	echo "<br /><br />\n";

	//log table missing
	if (!$table_exists) {
		echo "<div class='card'>".$text['message-run_upgrade']."</div>\n";
		require_once "resources/footer.php";
		exit;
	}

	//summary
	$log_url = 'identity_logs.php?'.($show_all ? 'show=all&' : '');
	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>".$text['label-type']."</th>\n";
	echo "	<th class='center'>".$text['label-total']."</th>\n";
	echo "	<th class='center'>".$text['label-success']."</th>\n";
	echo "	<th class='center'>".$text['label-failed']."</th>\n";
	echo "	<th class='center hide-sm-dn'>A</th>\n";
	echo "	<th class='center hide-sm-dn'>B</th>\n";
	echo "	<th class='center hide-sm-dn'>C</th>\n";
	echo "	<th class='center hide-sm-dn'>".$text['label-branded']."</th>\n";
	echo "	<th class='center'>".$text['label-success_rate']."</th>\n";
	echo "</tr>\n";
	foreach ($summary as $type => $row) {
		$success_status = $type == 'sign' ? 'signed' : 'passed';
		echo "<tr class='list-row' href='".$log_url."type=".$type."'>\n";
		echo "	<td><a href='".$log_url."type=".$type."'>".$text['label-calls_'.$type]."</a></td>\n";
		echo "	<td class='center'>".number_format($row['total'])."</td>\n";
		echo "	<td class='center'><a href='".$log_url."type=".$type."&status=".$success_status."'>".number_format($row['success'])."</a></td>\n";
		echo "	<td class='center'><a href='".$log_url."type=".$type."&status=failed'>".number_format($row['failed'])."</a></td>\n";
		echo "	<td class='center hide-sm-dn'>".number_format($row['A'])."</td>\n";
		echo "	<td class='center hide-sm-dn'>".number_format($row['B'])."</td>\n";
		echo "	<td class='center hide-sm-dn'>".number_format($row['C'])."</td>\n";
		echo "	<td class='center hide-sm-dn'>".number_format($row['branded'])."</td>\n";
		echo "	<td class='center'>".($row['total'] > 0 ? round($row['success'] / $row['total'] * 100, 1).'%' : '&mdash;')."</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

	//configuration
	$certificate = $status['certificate'];
	echo "<b>".$text['header-configuration']."</b>\n";
	echo "<br /><br />\n";
	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-row'>\n";
	echo "	<td class='no-wrap'>".$text['label-check_certificate']."</td>\n";
	if ($certificate['readable']) {
		echo "	<td>".escape($certificate['subject'])." &mdash; ".$text['label-expires']." ".date('Y-m-d', $certificate['valid_to']).($certificate['self_signed'] ? " (".$text['label-self_signed'].")" : null)."</td>\n";
	}
	else {
		echo "	<td>".$text['label-not_configured']."</td>\n";
	}
	echo "</tr>\n";
	echo "<tr class='list-row'>\n";
	echo "	<td class='no-wrap'>".$text['label-check_certificate_url']."</td>\n";
	echo "	<td>".(!empty($identity_settings['certificate_url']) ? escape($identity_settings['certificate_url']) : $text['label-not_configured'])."</td>\n";
	echo "</tr>\n";
	echo "<tr class='list-row'>\n";
	echo "	<td class='no-wrap'>".$text['label-check_outbound']."</td>\n";
	echo "	<td>".$routes_signed." / ".count($routes)." ".$text['label-routes_signing']."</td>\n";
	echo "</tr>\n";
	echo "<tr class='list-row'>\n";
	echo "	<td class='no-wrap'>".$text['label-check_inbound']."</td>\n";
	echo "	<td>".(!empty($verify_dialplan) ? $text['label-'.$verify_dialplan['dialplan_enabled']] : $text['label-not_configured'])."</td>\n";
	echo "</tr>\n";
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

	//activity
	echo "<b>".$text['header-activity']."</b>\n";
	echo "<br /><br />\n";
	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>".$text['label-period']."</th>\n";
	echo "	<th class='center'>".$text['option-signed']."</th>\n";
	echo "	<th class='center'>".$text['label-sign_failed']."</th>\n";
	echo "	<th class='center'>".$text['option-passed']."</th>\n";
	echo "	<th class='center'>".$text['label-verify_failed']."</th>\n";
	echo "</tr>\n";
	if (is_array($activity) && @sizeof($activity) != 0) {
		foreach ($activity as $row) {
			echo "<tr class='list-row'>\n";
			echo "	<td>".escape($row['period'])."</td>\n";
			echo "	<td class='center'>".number_format($row['signed'])."</td>\n";
			echo "	<td class='center'>".number_format($row['sign_failed'])."</td>\n";
			echo "	<td class='center'>".number_format($row['passed'])."</td>\n";
			echo "	<td class='center'>".number_format($row['verify_failed'])."</td>\n";
			echo "</tr>\n";
		}
	}
	else {
		echo "<tr class='list-row'><td colspan='5'>".$text['label-no_results']."</td></tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

	//recent failures
	echo "<b>".$text['header-recent_failures']."</b>\n";
	echo "<br /><br />\n";
	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>".$text['label-date']."</th>\n";
	echo "	<th>".$text['label-type']."</th>\n";
	echo "	<th>".$text['label-source']."</th>\n";
	echo "	<th>".$text['label-destination']."</th>\n";
	echo "	<th class='hide-sm-dn'>".$text['label-message']."</th>\n";
	echo "</tr>\n";
	if (is_array($failures) && @sizeof($failures) != 0) {
		foreach ($failures as $row) {
			echo "<tr class='list-row'>\n";
			echo "	<td class='no-wrap'>".escape($row['date_formatted'])."</td>\n";
			echo "	<td>".$text['option-'.$row['identity_log_type']]."</td>\n";
			echo "	<td>".escape(format_phone($row['identity_log_source']))."&nbsp;</td>\n";
			echo "	<td>".escape(format_phone($row['identity_log_destination']))."&nbsp;</td>\n";
			echo "	<td class='description overflow hide-sm-dn'>".escape($row['identity_log_message'])."&nbsp;</td>\n";
			echo "</tr>\n";
		}
	}
	else {
		echo "<tr class='list-row'><td colspan='5'>".$text['label-no_results']."</td></tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

//include the footer
	require_once "resources/footer.php";

?>
