<?php

// Disable the PHP session and the web CIDR check, this script is called by FreeSWITCH and checks the identity allowed_cidr setting instead
$no_session = true;
$no_cidr = true;

// Include files
require_once dirname(__DIR__, 2) . "/resources/require.php";

// Never display PHP errors, whatever error.reporting is set to: the response becomes a SIP header or channel variable
ini_set('display_errors', '0');

// Get the identity settings from Default Settings
$identity_settings = (new identity_settings(['database' => $database, 'config' => $config]))->get();
$debug = $identity_settings['debug'];
$allowed_cidr = $identity_settings['allowed_cidr'];
$setting_array['private_key_path'] = $identity_settings['private_key_path'];
$setting_array['certificate_path'] = $identity_settings['certificate_path'];
$setting_array['certificate_url'] = $identity_settings['certificate_url'];
$setting_array['debug'] = $debug;

// Get the identity header
if (is_cli()) {
	$identity_header = $argv[1] ?? '';
	$format = $argv[2] ?? '';
	$call_uuid = '';
	$domain_uuid = '';
}
else {
	// Get the client's IP address
	$remote_address = $_SERVER['REMOTE_ADDR'];
	if (empty($remote_address)) {
		return;
	}

	// Check if the CIDR is allowed
	if (!identity_settings::is_allowed($allowed_cidr, $remote_address)) {
		return;
	}

	// Get the variables, a value that is not a string (such as identity[]=) is treated as empty
	$request_value = function (string $name): string {
		return is_string($_REQUEST[$name] ?? null) ? $_REQUEST[$name] : '';
	};
	$identity_header = $request_value('identity');
	$format = $request_value('format');
	$call_uuid = $request_value('call_uuid');
	$domain_uuid = $request_value('domain_uuid');
}

// Empty identity header
if (empty($identity_header)) {
	echo 'No-TN-Validation';
	return;
}

// Initialize the identity object
$identity = new identity($setting_array);

// Verify the identity header
$result = $identity->verify($identity_header);

// JSON output includes Rich Call Data (name, logo, call reason) for display
if ($format === 'json') {
	echo json_encode([
		'verified' => $result,
		'attest' => $identity->attest_level,
		'rcd' => (object)$identity->rcd,
		'crn' => $identity->crn,
		'error' => ($result || !$debug) ? '' : $identity->error_message,
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
elseif ($result === true) {
	echo 'TN-Validation-Passed-' . $identity->attest_level;
} else {
	if ($debug) {
		echo "PASSporT verification error " . $identity->error_message;
	}
	else {
		echo 'TN-Validation-Failed';
	}
}

// Send the response to FreeSWITCH before writing the log
if (function_exists('fastcgi_finish_request')) {
	fastcgi_finish_request();
}

// Log the result
$payload = $identity->passport['payload'];
$identity_logs = new identity_logs(['database' => $database]);
$identity_logs->add([
	'domain_uuid' => $domain_uuid,
	'identity_log_call_uuid' => $call_uuid,
	'identity_log_type' => 'verify',
	'identity_log_status' => $result === true ? 'passed' : 'failed',
	'identity_log_attest' => is_string($payload['attest'] ?? null) ? $payload['attest'] : '',
	'identity_log_source' => is_string($payload['orig']['tn'] ?? null) ? $payload['orig']['tn'] : '',
	'identity_log_destination' => is_string($payload['dest']['tn'][0] ?? null) ? $payload['dest']['tn'][0] : '',
	'identity_log_certificate_url' => is_string($identity->passport['header']['x5u'] ?? null) ? $identity->passport['header']['x5u'] : '',
	'identity_log_origid' => is_string($payload['origid'] ?? null) ? $payload['origid'] : '',
	'identity_log_iat' => is_numeric($payload['iat'] ?? null) ? $payload['iat'] : '',
	'identity_log_brand' => $result === true && is_string($identity->rcd['nam'] ?? null) ? $identity->rcd['nam'] : '',
	'identity_log_message' => $result === true ? '' : $identity->error_message,
]);

// Delete the logs older than the retention period, at most once an hour, after the response was sent
identity_logs::purge_if_due($database, (int)$identity_settings['database_retention_days'], (string)$settings->get('cache', 'location', '/var/cache/fusionpbx'));
