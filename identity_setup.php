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
	$platform = permission_exists('identity_setup_all');
	$setup = new identity_setup(['database' => $database, 'config' => $config, 'domain_uuid' => $domain_uuid]);
	$settings_manager = new identity_settings(['database' => $database, 'config' => $config]);
	$identity_settings = $settings_manager->get();
	$identity_settings['cache_path'] = (string)$settings->get('cache', 'location', '/var/cache/fusionpbx');
	$identity = new identity($identity_settings);

//get posted data
	$action = $_POST['action'] ?? '';

//process http post data, unless the settings form is shown again after a validation error
	if (!empty($action) && empty($_POST['persistformvar'])) {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'],'negative');
				header('Location: identity_setup.php');
				exit;
			}

		//the settings, the certificate, the test and inbound verification apply to the whole platform
			if (in_array($action, ['save_settings', 'generate_certificate', 'import_certificate', 'test', 'add_verify', 'remove_verify'], true) && !$platform) {
				message::add($text['message-platform_permission'], 'negative');
				header('Location: identity_setup.php');
				exit;
			}

		//run the action
			switch ($action) {
				case 'save_settings':
					//get the posted settings
					$values = [];
					foreach (array_keys(identity_settings::definitions) as $name) {
						$values[$name] = trim((string)($_POST['identity_'.$name] ?? ''));
					}

					//check for all required data
					$msg = '';
					foreach ($settings_manager->validate($values) as $name) {
						$msg .= $text['message-setting_invalid'].' '.$text['label-setting_'.$name]."<br>\n";
					}
					if (!empty($msg)) {
						require_once "resources/header.php";
						require_once "resources/persist_form_var.php";
						echo "<div align='center'>\n";
						echo "<table><tr><td>\n";
						echo $msg."<br />";
						echo "</td></tr></table>\n";
						persistformvar($_POST);
						echo "</div>\n";
						require_once "resources/footer.php";
						exit;
					}

					//save the settings
					if ($settings_manager->save($values)) {
						message::add($text['message-settings_saved']);

						//sign the routes of every domain now rather than within the hour
						if ($values['sign_all_routes'] == 'true') {
							$sign_all = $setup->sign_all_routes();
							if ($sign_all['added'] > 0) {
								message::add($text['message-signing_added_all'].' '.$sign_all['added']);
							}
							if ($sign_all['failed'] > 0) {
								message::add($text['message-sign_all_failed'].' '.$sign_all['failed'], 'negative', 5000);
							}
							unset($sign_all);
						}
					}
					else {
						message::add($text['message-settings_failed'], 'negative', 5000);
					}
					break;
				case 'generate_certificate':
					$result = $identity->generate_test_certificates($identity_settings['path'], $_SERVER['HTTP_HOST'] ?? 'STIR Test');
					if (empty($result['error'])) {
						message::add($text['message-certificate_generated']);
					}
					else {
						message::add($text['message-certificate_failed'].' '.$result['error'], 'negative', 5000);
					}
					break;
				case 'import_certificate':
					//read the uploaded files, a file that was not chosen is left empty
					$import_error = '';
					$import = ['private_key' => '', 'certificate' => ''];
					foreach (array_keys($import) as $name) {
						$file = $_FILES['import_'.$name] ?? [];
						if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) == UPLOAD_ERR_NO_FILE) {
							continue;
						}
						if ($file['error'] != UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || $file['size'] > 65536) {
							$import_error = 'upload';
							break;
						}
						$import[$name] = (string)file_get_contents($file['tmp_name']);
					}
					if (empty($import_error)) {
						$import_error = $setup->import_certificates($import['private_key'], $import['certificate'], $_POST['import_passphrase'] ?? '');
					}
					unset($import);
					if (empty($import_error)) {
						message::add($text['message-import_complete']);
					}
					else {
						message::add($text['message-import_'.$import_error], 'negative', 5000);
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

						//verify the same way inbound calls are verified: download the certificate URL with the same restrictions,
						//check it against the trust list, and check the numbers
						if (!empty($identity_settings['certificate_url'])) {
							$test['remote'] = $identity->verify($test['header'], '', $test['source'], $test['destination']);
							$test['remote_message'] = $identity->verstat.($test['remote'] ? '' : ': '.$identity->error_message);
						}
					}
					$_SESSION['identity']['test'] = $test;
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
			header('Location: identity_setup.php');
			exit;
	}

//get the test result from the last post
	$test = $_SESSION['identity']['test'] ?? [];
	unset($_SESSION['identity']['test']);

//inspect the key and certificate
	$status = $identity->certificate_status();
	$key = $status['private_key'];
	$certificate = $status['certificate'];

//check a private key, its certificate and its certificate url, each check is: label, status (ok, warning, error), details
//the certificate url is downloaded with the restrictions receivers use and without the cache, and compared with the local certificate
	$certificate_checks = function (array $status, string $certificate_url, array $labels) use ($identity, $text): array {
		$checks = [];
		$key = $status['private_key'];
		$certificate = $status['certificate'];

		//private key
		if (!$key['exists']) {
			$checks[] = [$labels['private_key'], 'error', $text['message-file_missing'].' '.$key['path']];
		}
		else if (!$key['readable']) {
			$checks[] = [$labels['private_key'], 'error', $text['message-file_unreadable'].' '.$key['path']];
		}
		else if ($key['curve'] != 'prime256v1') {
			$checks[] = [$labels['private_key'], 'error', $text['message-key_curve'].' '.($key['curve'] ?: $text['label-none'])];
		}
		else if ((octdec($key['mode']) & 0007) != 0) {
			$checks[] = [$labels['private_key'], 'warning', $text['message-key_mode'].' '.$key['mode']];
		}
		else {
			$checks[] = [$labels['private_key'], 'ok', $key['path'].' ('.$key['curve'].', '.$key['mode'].')'];
		}

		//certificate
		if (!$certificate['exists']) {
			$checks[] = [$labels['certificate'], 'error', $text['message-file_missing'].' '.$certificate['path']];
		}
		else if (!$certificate['readable']) {
			$checks[] = [$labels['certificate'], 'error', $text['message-certificate_unreadable'].' '.$certificate['path']];
		}
		else {
			$days = (int)floor(($certificate['valid_to'] - time()) / 86400);
			$details = $certificate['subject'].' - '.$text['label-expires'].' '.date('Y-m-d', $certificate['valid_to']).' ('.$days.' '.$text['label-days'].')';
			if ($certificate['valid_to'] < time()) {
				$checks[] = [$labels['certificate'], 'error', $text['message-certificate_expired'].' '.$details];
			}
			else if ($certificate['self_signed']) {
				$checks[] = [$labels['certificate'], 'warning', $text['message-certificate_self_signed'].' '.$details];
			}
			else if ($days < 30) {
				$checks[] = [$labels['certificate'], 'warning', $text['message-certificate_expiring'].' '.$details];
			}
			else {
				$checks[] = [$labels['certificate'], 'ok', $details];
			}
		}

		//key pair
		if ($key['readable'] && $certificate['readable']) {
			$checks[] = [$labels['key_pair'], $status['key_matches_certificate'] ? 'ok' : 'error', $status['key_matches_certificate'] ? '' : $text['message-key_mismatch']];
		}

		//certificate url
		$url_scheme = strtolower(parse_url($certificate_url, PHP_URL_SCHEME) ?? '');
		if (empty($certificate_url)) {
			$checks[] = [$labels['certificate_url'], 'error', $text['message-url_missing']];
		}
		else if ($url_scheme != 'https') {
			$checks[] = [$labels['certificate_url'], 'error', $text['message-url_scheme'].' '.$certificate_url];
		}
		else {
			$url_pem = $identity->fetch_certificate($certificate_url, false);
			$url_certificate = $url_pem === false ? false : @openssl_x509_read($url_pem);
			while (openssl_error_string()) {}
			if ($url_pem === false) {
				$checks[] = [$labels['certificate_url'], 'error', $text['message-url_unreachable'].' '.$identity->error_message];
			}
			else if ($url_certificate === false) {
				$checks[] = [$labels['certificate_url'], 'error', $text['message-url_not_certificate'].' '.$certificate_url];
			}
			else if ($certificate['readable'] && openssl_x509_fingerprint($url_certificate, 'sha256') != openssl_x509_fingerprint($certificate['pem'], 'sha256')) {
				$checks[] = [$labels['certificate_url'], 'error', $text['message-url_mismatch'].' '.$certificate_url];
			}
			else {
				$checks[] = [$labels['certificate_url'], 'ok', $certificate_url];
			}
		}

		return $checks;
	};

//check the allowed cidr
	$cidr_invalid = identity_settings::invalid_cidr($identity_settings['allowed_cidr']);

//sign the new routes of every domain, so the checks are current
	if ($identity_settings['sign_all_routes']) {
		$sign_all = $setup->sign_all_routes();
		if ($sign_all['added'] > 0) {
			message::add($text['message-signing_added_all'].' '.$sign_all['added']);
		}
		if ($sign_all['failed'] > 0) {
			message::add($text['message-sign_all_failed'].' '.$sign_all['failed'], 'negative', 5000);
		}
		unset($sign_all);
	}

//count the routes, of every domain for platform administrators, and get the inbound dialplan
	$route_counts = $setup->outbound_route_counts($platform);
	$verify_dialplan = $setup->verify_dialplan();

//build the checks, each one is: label, status (ok, warning, error), details
	$checks = [];

	//database tables
	$table_exists = $database->table_exists('v_identity_logs') && $database->table_exists('v_identity_route_exclusions');
	$checks[] = [$text['label-check_logs'], $table_exists ? 'ok' : 'error', $table_exists ? '' : $text['message-run_upgrade']];

	//settings
	if ($identity_settings['source'] == 'config') {
		$checks[] = [$text['label-check_settings'], 'warning', $text['message-settings_config']];
	}
	else {
		$checks[] = [$text['label-check_settings'], 'ok', ''];
	}

	//certificate directory, with the commands that fix it
	$directory = identity_setup::directory_problem($identity_settings['path']);
	switch ($directory['problem']) {
		case '':
			$checks[] = [$text['label-check_path'], 'ok', $identity_settings['path']];
			break;
		case 'missing':
			$checks[] = [$text['label-check_path'], 'error', $text['message-path_missing'].' '.$directory['command']];
			break;
		case 'owner':
			$checks[] = [$text['label-check_path'], 'warning', $text['message-path_owner'].' '.$directory['command'].' ('.$text['label-path_owner'].' '.$directory['owner'].', '.$text['label-path_mode'].' '.$directory['mode'].', '.$text['label-path_web_user'].' '.$directory['user'].')'];
			break;
		case 'restart':
			$checks[] = [$text['label-check_path'], 'warning', $text['message-path_restart'].' '.$directory['command']];
			break;
		case 'protected':
			$checks[] = [$text['label-check_path'], 'warning', $text['message-path_protected'].' '.$directory['command']];
			break;
		default:
			$checks[] = [$text['label-check_path'], 'warning', $text['message-path_read_only'].' '.$directory['command']];
	}
	unset($directory);

	//private key, certificate, key pair and certificate url
	$checks = array_merge($checks, $certificate_checks($status, $identity_settings['certificate_url'], [
		'private_key' => $text['label-check_private_key'],
		'certificate' => $text['label-check_certificate'],
		'key_pair' => $text['label-check_key_pair'],
		'certificate_url' => $text['label-check_certificate_url'],
	]));

	//allowed cidr
	if (!empty($cidr_invalid)) {
		$checks[] = [$text['label-check_cidr'], 'error', $text['message-cidr_invalid'].' \''.implode('\', \'', $cidr_invalid).'\''];
	}
	else if (substr_count($identity_settings['allowed_cidr'], '/0') != 0) {
		$checks[] = [$text['label-check_cidr'], 'warning', $text['message-cidr_open'].' '.$identity_settings['allowed_cidr']];
	}
	else {
		$checks[] = [$text['label-check_cidr'], 'ok', $identity_settings['allowed_cidr']];
	}

	//attestation check
	if (!$identity_settings['attestation_check']) {
		$checks[] = [$text['label-check_attestation'], 'warning', $text['message-attestation_check_off']];
	}
	else {
		$checks[] = [$text['label-check_attestation'], 'ok', $text['message-attestation_check_on']];
	}

	//debug
	if ($identity_settings['debug']) {
		$checks[] = [$text['label-check_debug'], 'warning', $text['message-debug_enabled']];
	}

	//outbound routes
	if ($route_counts['total'] == 0) {
		$checks[] = [$text['label-check_outbound'], 'warning', $text['message-no_routes']];
	}
	else {
		$checks[] = [$text['label-check_outbound'], $route_counts['signed'] == $route_counts['total'] ? 'ok' : 'warning', $route_counts['signed'].' / '.$route_counts['total'].' '.$text['label-routes_signing']];
	}

	//sign all routes, the routes it cannot sign because the destination was not found in the bridge
	if ($identity_settings['sign_all_routes']) {
		if ($route_counts['skipped'] > 0) {
			$checks[] = [$text['label-check_sign_all_routes'], 'warning', $text['message-sign_all_skipped'].' '.$route_counts['skipped']];
		}
		else {
			$checks[] = [$text['label-check_sign_all_routes'], 'ok', $route_counts['excluded'].' '.$text['label-routes_excluded']];
		}
	}

	//inbound verification
	if (empty($verify_dialplan)) {
		$checks[] = [$text['label-check_inbound'], 'warning', $text['message-verify_missing']];
	}
	else {
		$checks[] = [$text['label-check_inbound'], $verify_dialplan['dialplan_enabled'] == 'true' ? 'ok' : 'warning', $text['label-'.$verify_dialplan['dialplan_enabled']]];
	}

	//trust list, without it inbound calls with a valid signature are reported as No-TN-Validation
	if (empty($identity_settings['trust_list'])) {
		if (!empty($verify_dialplan)) {
			$checks[] = [$text['label-check_trust_list'], 'error', $text['message-trust_list_missing']];
		}
	}
	else if (!is_file($identity_settings['trust_list']) || !is_readable($identity_settings['trust_list'])) {
		$checks[] = [$text['label-check_trust_list'], 'error', $text['message-trust_list_unreadable'].' '.$identity_settings['trust_list']];
	}
	else {
		$trust_total = 0;
		$trust_expired = 0;
		preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', (string)file_get_contents($identity_settings['trust_list']), $matches);
		foreach ($matches[0] as $block) {
			$trust_certificate = @openssl_x509_read($block);
			if ($trust_certificate !== false) {
				$trust_total++;
				if ((openssl_x509_parse($trust_certificate)['validTo_time_t'] ?? 0) < time()) {
					$trust_expired++;
				}
			}
		}
		while (openssl_error_string()) {}
		if ($trust_total == 0) {
			$checks[] = [$text['label-check_trust_list'], 'error', $text['message-trust_list_invalid'].' '.$identity_settings['trust_list']];
		}
		else if ($trust_expired > 0) {
			$checks[] = [$text['label-check_trust_list'], 'warning', $text['message-trust_list_expired'].' '.$trust_expired.' / '.$trust_total];
		}
		else {
			$checks[] = [$text['label-check_trust_list'], 'ok', $trust_total.' '.$text['label-certificates']];
		}
		unset($trust_total, $trust_expired, $trust_certificate, $matches, $block);
	}

	//revocation, checked against the crl once the trust list is set because the crl signer must chain to it
	if (empty($identity_settings['crl_url'])) {
		if (!empty($verify_dialplan) || !empty($identity_settings['trust_list'])) {
			$checks[] = [$text['label-check_crl'], 'warning', $text['message-crl_off']];
		}
	}
	else if (!empty($identity_settings['trust_list'])) {
		$crl = new identity_crl($identity, $identity_settings['crl_url'], $identity_settings['cache_path']);
		if ($crl->load()) {
			$checks[] = [$text['label-check_crl'], 'ok', $text['label-crl_updated'].' '.date('Y-m-d H:i', $crl->this_update).', '.$text['label-crl_next'].' '.date('Y-m-d H:i', $crl->next_update).', '.$crl->count.' '.$text['label-crl_revoked']];
		}
		else {
			$checks[] = [$text['label-check_crl'], 'error', $text['message-crl_failed'].' '.$crl->error_message];
		}
		unset($crl);
	}

	//brands file, a missing or malformed file only shows in the php error log when calls are signed
	$bcid_brands = 0;
	if (!empty($identity_settings['brands_file'])) {
		$brands = new identity_brands($identity_settings['brands_file']);
		if (!file_exists($identity_settings['brands_file'])) {
			$checks[] = [$text['label-check_brands_file'], 'warning', $text['message-brands_file_missing'].' '.$identity_settings['brands_file']];
		}
		else if (!$brands->load()) {
			$checks[] = [$text['label-check_brands_file'], 'error', $text['message-brands_file_invalid'].' '.$brands->error_message];
		}
		else {
			foreach ($brands->get_brands() as $brand) {
				if (is_array($brand) && !empty($brand['enabled']) && ($brand['profile'] ?? 'bcid') === 'bcid') {
					$bcid_brands++;
				}
			}
			$checks[] = [$text['label-check_brands_file'], 'ok', count($brands->get_brands()).' '.$text['label-brands'].', '.count($brands->get_numbers()).' '.$text['label-numbers']];
		}
		unset($brands, $brand);
	}

	//bcid profile, its key and certificate are only used for branded calls, which are signed without branding when they fail
	if (!empty($identity_settings['bcid_certificate_url'])) {
		$bcid_identity = new identity([
			'private_key_path' => $identity_settings['bcid_private_key_path'],
			'certificate_path' => $identity_settings['bcid_certificate_path'],
			'certificate_url' => $identity_settings['bcid_certificate_url'],
		]);
		$checks = array_merge($checks, $certificate_checks($bcid_identity->certificate_status(), $identity_settings['bcid_certificate_url'], [
			'private_key' => $text['label-check_bcid_private_key'],
			'certificate' => $text['label-check_bcid_certificate'],
			'key_pair' => $text['label-check_bcid_key_pair'],
			'certificate_url' => $text['label-check_bcid_certificate_url'],
		]));
		unset($bcid_identity);
	}
	else if ($bcid_brands > 0) {
		$checks[] = [$text['label-check_bcid_certificate_url'], 'warning', $text['message-bcid_off'].' '.$bcid_brands];
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
	echo button::create(['type'=>'button','label'=>$text['button-routes'],'icon'=>$settings->get('theme', 'button_icon_list'),'id'=>'btn_routes','link'=>'identity_routes.php']);
	echo button::create(['type'=>'button','label'=>$text['button-refresh'],'icon'=>$settings->get('theme', 'button_icon_refresh'),'id'=>'btn_refresh','style'=>'margin-left: 15px;','link'=>'identity_setup.php']);
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

	//the settings, the certificate and the test apply to the whole platform
	if ($platform) {
		//settings, the posted values when the form is shown again after a validation error
		$form = [];
		foreach (array_keys(identity_settings::definitions) as $name) {
			if (!empty($_POST['persistformvar'])) {
				$form[$name] = (string)($_POST['identity_'.$name] ?? '');
			}
			else if (is_bool($identity_settings[$name])) {
				$form[$name] = $identity_settings[$name] ? 'true' : 'false';
			}
			else {
				$form[$name] = $identity_settings[$name];
			}
		}
		echo "<form method='post' name='frm_settings' id='frm_settings'>\n";
		echo "<input type='hidden' name='action' value='save_settings'>\n";
		echo "<b>".$text['header-settings']."</b>\n";
		echo "<br /><br />\n";
		echo $text['description-settings']."\n";
		echo "<br /><br />\n";
		echo "<div class='card'>\n";
		echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
		foreach (array_keys(identity_settings::definitions) as $name) {
			if ($name == 'brands_file') {
				echo "<tr>\n";
				echo "<td colspan='2'><br /><b>".$text['header-settings_branded']."</b><br /><br /></td>\n";
				echo "</tr>\n";
			}
			echo "<tr>\n";
			echo "<td width='30%' class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-setting_'.$name]."</td>\n";
			echo "<td width='70%' class='vtable' align='left'>\n";
			if (identity_settings::definitions[$name]['type'] == 'boolean') {
				echo "	<select class='formfld' name='identity_".$name."'>\n";
				echo "		<option value='true' ".($form[$name] == 'true' ? "selected='selected'" : null).">".$text['label-true']."</option>\n";
				echo "		<option value='false' ".($form[$name] != 'true' ? "selected='selected'" : null).">".$text['label-false']."</option>\n";
				echo "	</select>\n";
			}
			else {
				echo "	<input class='formfld' type='text' name='identity_".$name."' maxlength='1024' style='width: 100%; max-width: 450px;' value=\"".escape($form[$name])."\">\n";
			}
			echo "	<br />\n";
			echo "	".$text['description-setting_'.$name]."\n";
			echo "</td>\n";
			echo "</tr>\n";
		}
		echo "</table>\n";
		echo "</div>\n";
		echo "<br />\n";
		echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save_settings']);
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo "</form>\n";
		echo "<br /><br />\n";

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

		//import
		echo "<form method='post' name='frm_import' id='frm_import' enctype='multipart/form-data'>\n";
		echo "<input type='hidden' name='action' value='import_certificate'>\n";
		echo "<b>".$text['header-import']."</b>\n";
		echo "<br /><br />\n";
		echo $text['description-import']."\n";
		echo "<br /><br />\n";
		echo "<div class='card'>\n";
		echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
		echo "<tr>\n";
		echo "<td width='30%' class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-check_private_key']."</td>\n";
		echo "<td width='70%' class='vtable' align='left'>\n";
		echo "	<input class='formfld fileinput' type='file' name='import_private_key' id='import_private_key' accept='.key,.pem,.txt'>\n";
		echo "	<br />\n";
		echo "	".escape($key['path'])."\n";
		echo "</td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-passphrase']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input class='formfld' type='password' name='import_passphrase' maxlength='255' autocomplete='new-password' value=''>\n";
		echo "	<br />\n";
		echo "	".$text['description-passphrase']."\n";
		echo "</td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-check_certificate']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input class='formfld fileinput' type='file' name='import_certificate' id='import_certificate' accept='.pem,.crt,.cer,.der'>\n";
		echo "	<br />\n";
		echo "	".escape($certificate['path'])."\n";
		echo "</td>\n";
		echo "</tr>\n";
		echo "</table>\n";
		echo "</div>\n";
		echo "<br />\n";
		echo button::create(['type'=>'submit','label'=>$text['button-import'],'icon'=>$settings->get('theme', 'button_icon_import'),'id'=>'btn_import','onclick'=>($key['exists'] || $certificate['exists'] ? "return confirm('".$text['confirm-import']."');" : null)]);
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
	}

	//outbound routes, signed on their own page
	echo "<b>".$text['header-outbound']."</b>\n";
	echo "<br /><br />\n";
	echo $text['description-outbound']."\n";
	echo "<br /><br />\n";
	echo button::create(['type'=>'button','label'=>$text['button-routes'],'icon'=>$settings->get('theme', 'button_icon_list'),'id'=>'btn_routes_section','link'=>'identity_routes.php']);
	echo "<br /><br /><br />\n";

	//inbound verification applies to the whole platform
	if ($platform) {
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
	}

//include the footer
	require_once "resources/footer.php";

?>
