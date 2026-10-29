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
	if (!permission_exists('identity_setup')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//set the defaults
	$show = $_GET["show"] ?? '';
	$show_all = $show == 'all' && permission_exists('identity_log_all');
	$setup = new identity_setup(['database' => $database, 'config' => $config, 'domain_uuid' => $domain_uuid]);
	$identity_settings = $setup->settings();
	$identity = new identity($identity_settings);

//get posted data
	$action = $_POST['action'] ?? '';
	if (!empty($_POST['route_action'])) {
		[$action, $route_uuid] = array_pad(explode(':', $_POST['route_action'], 2), 2, '');
	}

//process http post data
	if (!empty($action)) {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'],'negative');
				header('Location: identity_setup.php'.($show_all ? '?show=all' : ''));
				exit;
			}

		//run the action
			switch ($action) {
				case 'generate_certificate':
					$result = $identity->generate_test_certificates($identity_settings['path'], $_SERVER['HTTP_HOST'] ?? 'STIR Test');
					if (empty($result['error'])) {
						message::add($text['message-certificate_generated']);
					}
					else {
						message::add($text['message-certificate_failed'].' '.$result['error'], 'negative', 5000);
					}
					break;
				case 'test':
					$test['source'] = $identity->normalize_tn($_POST['test_source'] ?? '');
					$test['destination'] = $identity->normalize_tn($_POST['test_destination'] ?? '');
					$test['attest_level'] = in_array($_POST['test_attest_level'] ?? '', ['A', 'B', 'C'], true) ? $_POST['test_attest_level'] : 'A';
					$result = $identity->sign($test['source'], $test['destination'], $test['attest_level']);
					$test['sign_error'] = $result['error'] ?? '';
					$test['header'] = '';
					if (empty($result['error'])) {
						$test['header'] = $identity->build_identity_header($result['passport']);

						//verify with the local certificate
						$status = $identity->certificate_status();
						$test['local'] = $identity->verify($test['header'], $status['certificate']['pem']);
						$test['local_message'] = $test['local'] ? $text['label-attest'].' '.$identity->attest_level : $identity->error_message;

						//verify the same way a carrier would, by downloading the certificate URL
						if (!empty($identity_settings['certificate_url'])) {
							$test['remote'] = $identity->verify($test['header']);
							$test['remote_message'] = $test['remote'] ? $text['label-attest'].' '.$identity->attest_level : $identity->error_message;
						}
					}
					$_SESSION['identity']['test'] = $test;
					break;
				case 'add_signing':
					$destination = $_POST['routes'][$route_uuid]['destination'] ?? '';
					$attest_level = $_POST['routes'][$route_uuid]['attest_level'] ?? '';
					if ($setup->add_signing($route_uuid, $destination, $attest_level)) {
						message::add($text['message-signing_added']);
					}
					else {
						message::add($text['message-signing_failed'], 'negative');
					}
					break;
				case 'remove_signing':
					if ($setup->remove_signing($route_uuid)) {
						message::add($text['message-signing_removed']);
					}
					break;
				case 'add_verify':
					if ($setup->add_verify_dialplan()) {
						message::add($text['message-verify_added']);
					}
					break;
				case 'remove_verify':
					if ($setup->remove_verify_dialplan()) {
						message::add($text['message-verify_removed']);
					}
					break;
			}

		//redirect the user
			header('Location: identity_setup.php'.($show_all ? '?show=all' : ''));
			exit;
	}

//get the test result from the last post
	$test = $_SESSION['identity']['test'] ?? [];
	unset($_SESSION['identity']['test']);

//inspect the key and certificate
	$status = $identity->certificate_status();
	$key = $status['private_key'];
	$certificate = $status['certificate'];

//compare the certificate URL with the local certificate
	$url_fingerprint = '';
	$url_error = '';
	$url_scheme = strtolower(parse_url($identity_settings['certificate_url'], PHP_URL_SCHEME) ?? '');
	if (!empty($identity_settings['certificate_url']) && in_array($url_scheme, ['http', 'https'], true)) {
		$context = stream_context_create(['http' => ['timeout' => 3, 'follow_location' => 1, 'max_redirects' => 3]]);
		$url_pem = @file_get_contents($identity_settings['certificate_url'], false, $context);
		if ($url_pem === false) {
			$url_error = $text['message-url_unreachable'];
		}
		else {
			$url_certificate = @openssl_x509_read($url_pem);
			if ($url_certificate === false) {
				$url_error = $text['message-url_not_certificate'];
			}
			else {
				$url_fingerprint = openssl_x509_fingerprint($url_certificate, 'sha256');
			}
		}
		while (openssl_error_string()) {}
	}
	$local_fingerprint = $certificate['readable'] ? openssl_x509_fingerprint($certificate['pem'], 'sha256') : '';

//check the allowed cidr
	$cidr_invalid = [];
	foreach (array_map('trim', explode(',', $identity_settings['allowed_cidr'])) as $cidr) {
		[$subnet, $mask] = array_pad(explode('/', $cidr, 2), 2, '');
		if (ip2long($subnet) === false || !ctype_digit($mask) || (int)$mask > 32) {
			$cidr_invalid[] = $cidr;
		}
	}

//get the routes and the inbound dialplan
	$routes = $setup->outbound_routes($show_all);
	$verify_dialplan = $setup->verify_dialplan();
	$routes_signed = count(array_filter($routes, function($route) { return $route['signed']; }));

//build the checks, each one is: label, status (ok, warning, error), details
	$checks = [];

	//log table
	$table_exists = $database->table_exists('v_identity_logs');
	$checks[] = [$text['label-check_logs'], $table_exists ? 'ok' : 'error', $table_exists ? '' : $text['message-run_upgrade']];

	//config.conf
	$missing = array_keys(array_filter($identity_settings['configured'], function($configured) { return !$configured; }));
	$checks[] = [$text['label-check_config'], empty($missing) ? 'ok' : 'warning', empty($missing) ? '' : $text['message-config_default'].' identity.'.implode(', identity.', $missing)];

	//certificate directory
	if (!is_dir($identity_settings['path'])) {
		$checks[] = [$text['label-check_path'], 'error', $text['message-path_missing'].' '.$identity_settings['path']];
	}
	else {
		$checks[] = [$text['label-check_path'], 'ok', $identity_settings['path']];
	}

	//private key
	if (!$key['exists']) {
		$checks[] = [$text['label-check_private_key'], 'error', $text['message-file_missing'].' '.$key['path']];
	}
	else if (!$key['readable']) {
		$checks[] = [$text['label-check_private_key'], 'error', $text['message-file_unreadable'].' '.$key['path']];
	}
	else if ($key['curve'] != 'prime256v1') {
		$checks[] = [$text['label-check_private_key'], 'error', $text['message-key_curve'].' '.($key['curve'] ?: $text['label-none'])];
	}
	else if ((octdec($key['mode']) & 0007) != 0) {
		$checks[] = [$text['label-check_private_key'], 'warning', $text['message-key_mode'].' '.$key['mode']];
	}
	else {
		$checks[] = [$text['label-check_private_key'], 'ok', $key['path'].' ('.$key['curve'].', '.$key['mode'].')'];
	}

	//certificate
	if (!$certificate['exists']) {
		$checks[] = [$text['label-check_certificate'], 'error', $text['message-file_missing'].' '.$certificate['path']];
	}
	else if (!$certificate['readable']) {
		$checks[] = [$text['label-check_certificate'], 'error', $text['message-certificate_unreadable'].' '.$certificate['path']];
	}
	else {
		$days = (int)floor(($certificate['valid_to'] - time()) / 86400);
		$details = $certificate['subject'].' - '.$text['label-expires'].' '.date('Y-m-d', $certificate['valid_to']).' ('.$days.' '.$text['label-days'].')';
		if ($certificate['valid_to'] < time()) {
			$checks[] = [$text['label-check_certificate'], 'error', $text['message-certificate_expired'].' '.$details];
		}
		else if ($certificate['self_signed']) {
			$checks[] = [$text['label-check_certificate'], 'warning', $text['message-certificate_self_signed'].' '.$details];
		}
		else if ($days < 30) {
			$checks[] = [$text['label-check_certificate'], 'warning', $text['message-certificate_expiring'].' '.$details];
		}
		else {
			$checks[] = [$text['label-check_certificate'], 'ok', $details];
		}
	}

	//key pair
	if ($key['readable'] && $certificate['readable']) {
		$checks[] = [$text['label-check_key_pair'], $status['key_matches_certificate'] ? 'ok' : 'error', $status['key_matches_certificate'] ? '' : $text['message-key_mismatch']];
	}

	//certificate url
	if (empty($identity_settings['certificate_url'])) {
		$checks[] = [$text['label-check_certificate_url'], 'error', $text['message-url_missing']];
	}
	else if (!in_array($url_scheme, ['http', 'https'], true)) {
		$checks[] = [$text['label-check_certificate_url'], 'error', $text['message-url_scheme'].' '.$identity_settings['certificate_url']];
	}
	else if (!empty($url_error)) {
		$checks[] = [$text['label-check_certificate_url'], 'error', $url_error.' '.$identity_settings['certificate_url']];
	}
	else if (!empty($local_fingerprint) && $url_fingerprint != $local_fingerprint) {
		$checks[] = [$text['label-check_certificate_url'], 'error', $text['message-url_mismatch'].' '.$identity_settings['certificate_url']];
	}
	else if ($url_scheme != 'https') {
		$checks[] = [$text['label-check_certificate_url'], 'warning', $text['message-url_http'].' '.$identity_settings['certificate_url']];
	}
	else {
		$checks[] = [$text['label-check_certificate_url'], 'ok', $identity_settings['certificate_url']];
	}

	//allowed cidr
	if (!empty($cidr_invalid)) {
		$checks[] = [$text['label-check_cidr'], 'error', $text['message-cidr_invalid'].' '.implode(', ', $cidr_invalid)];
	}
	else if (substr_count($identity_settings['allowed_cidr'], '/0') != 0) {
		$checks[] = [$text['label-check_cidr'], 'warning', $text['message-cidr_open'].' '.$identity_settings['allowed_cidr']];
	}
	else {
		$checks[] = [$text['label-check_cidr'], 'ok', $identity_settings['allowed_cidr']];
	}

	//debug
	if ($identity_settings['debug'] != 'false') {
		$checks[] = [$text['label-check_debug'], 'warning', $text['message-debug_enabled']];
	}

	//outbound routes
	if (empty($routes)) {
		$checks[] = [$text['label-check_outbound'], 'warning', $text['message-no_routes']];
	}
	else {
		$checks[] = [$text['label-check_outbound'], $routes_signed == count($routes) ? 'ok' : 'warning', $routes_signed.' / '.count($routes).' '.$text['label-routes_signing']];
	}

	//inbound verification
	if (empty($verify_dialplan)) {
		$checks[] = [$text['label-check_inbound'], 'warning', $text['message-verify_missing']];
	}
	else {
		$checks[] = [$text['label-check_inbound'], $verify_dialplan['dialplan_enabled'] == 'true' ? 'ok' : 'warning', $text['label-'.$verify_dialplan['dialplan_enabled']]];
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-identity_setup'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-identity_setup']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','link'=>'identity.php']);
	if (!$show_all && permission_exists('identity_log_all')) {
		echo button::create(['type'=>'button','label'=>$text['button-show_all'],'icon'=>$settings->get('theme', 'button_icon_all'),'link'=>'?show=all']);
	}
	echo button::create(['type'=>'button','label'=>$text['button-refresh'],'icon'=>$settings->get('theme', 'button_icon_refresh'),'id'=>'btn_refresh','style'=>'margin-left: 15px;','link'=>'identity_setup.php'.($show_all ? '?show=all' : '')]);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-identity_setup']."\n";
	echo "<br /><br />\n";

	//checks
	$status_icons = ['ok' => 'fa-check-circle', 'warning' => 'fa-exclamation-triangle', 'error' => 'fa-times-circle'];
	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>".$text['label-check']."</th>\n";
	echo "	<th>".$text['label-status']."</th>\n";
	echo "	<th class='hide-sm-dn'>".$text['label-details']."</th>\n";
	echo "</tr>\n";
	foreach ($checks as $check) {
		echo "<tr class='list-row'>\n";
		echo "	<td class='no-wrap'>".$check[0]."</td>\n";
		echo "	<td class='no-wrap'><span class='fas ".$status_icons[$check[1]]." fa-fw'></span> ".$text['label-status_'.$check[1]]."</td>\n";
		echo "	<td class='description hide-sm-dn'>".escape($check[2])."&nbsp;</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

	//config.conf
	echo "<b>".$text['header-config']."</b>\n";
	echo "<br /><br />\n";
	echo $text['description-config']."\n";
	echo "<br /><br />\n";
	echo "<div class='card'>\n";
	echo "<textarea class='formfld' style='width: 100%; height: 130px; font-family: monospace;' readonly='readonly'>";
	echo escape_textarea("#identity - Stir/Shaken\n");
	echo escape_textarea("identity.path = '".$identity_settings['path']."'\n");
	echo escape_textarea("identity.private_key_name = '".basename($identity_settings['private_key_path'])."'\n");
	echo escape_textarea("identity.certificate_name = '".basename($identity_settings['certificate_path'])."'\n");
	echo escape_textarea("identity.certificate_url = '".$identity_settings['certificate_url']."'\n");
	echo escape_textarea("identity.allowed_cidr = '".$identity_settings['allowed_cidr']."'\n");
	echo escape_textarea("identity.debug = ".$identity_settings['debug']."\n");
	echo "</textarea>\n";
	echo "</div>\n";
	echo "<br />\n";

	//certificate
	echo "<form method='post' name='frm_certificate' id='frm_certificate'>\n";
	echo "<b>".$text['header-certificate']."</b>\n";
	echo "<br /><br />\n";
	echo $text['description-certificate']."\n";
	echo "<br /><br />\n";
	if (!$key['exists'] && !$certificate['exists']) {
		echo "<input type='hidden' name='action' value='generate_certificate'>\n";
		echo button::create(['type'=>'submit','label'=>$text['button-generate_certificate'],'icon'=>$settings->get('theme', 'button_icon_add'),'id'=>'btn_generate']);
	}
	else {
		echo $text['message-certificate_exists']."\n";
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";
	echo "<br /><br />\n";

	//test
	echo "<form method='post' name='frm_test' id='frm_test'>\n";
	echo "<input type='hidden' name='action' value='test'>\n";
	echo "<b>".$text['header-test']."</b>\n";
	echo "<br /><br />\n";
	echo $text['description-test']."\n";
	echo "<br /><br />\n";
	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
	echo "<tr>\n";
	echo "<td width='30%' class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-source']."</td>\n";
	echo "<td width='70%' class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='test_source' maxlength='20' value=\"".escape($test['source'] ?? '')."\">\n";
	echo "</td>\n";
	echo "</tr>\n";
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-destination']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='test_destination' maxlength='20' value=\"".escape($test['destination'] ?? '')."\">\n";
	echo "</td>\n";
	echo "</tr>\n";
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-attest']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='test_attest_level'>\n";
	foreach (['A', 'B', 'C'] as $level) {
		echo "		<option value='".$level."' ".(($test['attest_level'] ?? 'A') == $level ? "selected='selected'" : null).">".$level."</option>\n";
	}
	echo "	</select>\n";
	echo "</td>\n";
	echo "</tr>\n";
	if (!empty($test)) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-check_sign']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		if (empty($test['sign_error'])) {
			echo "	<textarea class='formfld' style='width: 100%; height: 90px; font-family: monospace;' readonly='readonly'>".escape_textarea($test['header'])."</textarea>\n";
		}
		else {
			echo "	".$text['label-status_error'].": ".escape($test['sign_error'])."\n";
		}
		echo "</td>\n";
		echo "</tr>\n";
		if (isset($test['local'])) {
			echo "<tr>\n";
			echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-check_verify_local']."</td>\n";
			echo "<td class='vtable' align='left'><span class='fas ".$status_icons[$test['local'] ? 'ok' : 'error']." fa-fw'></span> ".escape($test['local_message'])."</td>\n";
			echo "</tr>\n";
		}
		if (isset($test['remote'])) {
			echo "<tr>\n";
			echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-check_verify_remote']."</td>\n";
			echo "<td class='vtable' align='left'><span class='fas ".$status_icons[$test['remote'] ? 'ok' : 'error']." fa-fw'></span> ".escape($test['remote_message'])."</td>\n";
			echo "</tr>\n";
		}
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";
	echo button::create(['type'=>'submit','label'=>$text['button-test'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_test']);
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";
	echo "<br /><br />\n";

	//outbound routes
	echo "<form method='post' name='frm_routes' id='frm_routes'>\n";
	echo "<b>".$text['header-outbound']."</b>\n";
	echo "<br /><br />\n";
	echo $text['description-outbound']."\n";
	echo "<br /><br />\n";
	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	if ($show_all) {
		echo "	<th>".$text['label-domain']."</th>\n";
	}
	echo "	<th>".$text['label-name']."</th>\n";
	echo "	<th class='center'>".$text['label-enabled']."</th>\n";
	echo "	<th>".$text['label-signing']."</th>\n";
	echo "	<th>".$text['label-destination']."</th>\n";
	echo "	<th>".$text['label-attest']."</th>\n";
	echo "	<td class='action-button'>&nbsp;</td>\n";
	echo "</tr>\n";
	if (empty($routes)) {
		echo "<tr class='list-row'><td colspan='".($show_all ? 7 : 6)."'>".$text['message-no_routes']."</td></tr>\n";
	}
	foreach ($routes as $route) {
		$uuid = $route['dialplan_uuid'];
		echo "<tr class='list-row'>\n";
		if ($show_all) {
			echo "	<td>".escape($route['domain_name'])."</td>\n";
		}
		echo "	<td><a href='".PROJECT_PATH."/app/dialplans/dialplan_edit.php?id=".urlencode($uuid)."&app_uuid=".urlencode(identity_setup::outbound_route_app_uuid)."'>".escape($route['dialplan_name'])."</a></td>\n";
		echo "	<td class='center'>".$text['label-'.$route['dialplan_enabled']]."</td>\n";
		echo "	<td>".($route['signed'] ? $text['label-yes'] : $text['label-no'])."</td>\n";
		if ($route['signed']) {
			echo "	<td>".escape($route['destination'])."</td>\n";
			echo "	<td>".escape($route['attest_level'])."</td>\n";
			echo "	<td class='action-button'>";
			echo button::create(['type'=>'submit','label'=>$text['button-remove'],'icon'=>$settings->get('theme', 'button_icon_delete'),'name'=>'route_action','value'=>'remove_signing:'.$uuid,'onclick'=>"return confirm('".$text['confirm-remove_signing']."');"]);
			echo "</td>\n";
		}
		else {
			echo "	<td><input class='formfld' type='text' name='routes[".escape($uuid)."][destination]' maxlength='16' style='width: 80px;' value=\"".escape($route['destination'])."\"></td>\n";
			echo "	<td>\n";
			echo "		<select class='formfld' name='routes[".escape($uuid)."][attest_level]'>\n";
			foreach (['A', 'B', 'C'] as $level) {
				echo "			<option value='".$level."' ".($route['attest_level'] == $level ? "selected='selected'" : null).">".$level."</option>\n";
			}
			echo "		</select>\n";
			echo "	</td>\n";
			echo "	<td class='action-button'>";
			echo button::create(['type'=>'submit','label'=>$text['button-add'],'icon'=>$settings->get('theme', 'button_icon_add'),'name'=>'route_action','value'=>'add_signing:'.$uuid]);
			echo "</td>\n";
		}
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";
	echo "<br />\n";

	//inbound verification
	echo "<form method='post' name='frm_verify' id='frm_verify'>\n";
	echo "<b>".$text['header-inbound']."</b>\n";
	echo "<br /><br />\n";
	echo $text['description-inbound']."\n";
	echo "<br /><br />\n";
	if (empty($verify_dialplan)) {
		echo "<input type='hidden' name='action' value='add_verify'>\n";
		echo button::create(['type'=>'submit','label'=>$text['button-add'],'icon'=>$settings->get('theme', 'button_icon_add'),'id'=>'btn_add_verify']);
	}
	else {
		echo "<input type='hidden' name='action' value='remove_verify'>\n";
		echo button::create(['type'=>'button','label'=>$text['button-edit'],'icon'=>$settings->get('theme', 'button_icon_edit'),'id'=>'btn_edit_verify','link'=>PROJECT_PATH.'/app/dialplans/dialplan_edit.php?id='.urlencode($verify_dialplan['dialplan_uuid'])]);
		echo button::create(['type'=>'submit','label'=>$text['button-remove'],'icon'=>$settings->get('theme', 'button_icon_delete'),'id'=>'btn_remove_verify','onclick'=>"return confirm('".$text['confirm-remove_verify']."');"]);
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";
	echo "<br /><br />\n";

//include the footer
	require_once "resources/footer.php";

?>
