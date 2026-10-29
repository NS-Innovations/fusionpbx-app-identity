<?php

// Disable the PHP session and the web CIDR check, this script is called by FreeSWITCH and checks identity.allowed_cidr instead
$no_session = true;
$no_cidr = true;

// Include files
require_once dirname(__DIR__, 2) . "/resources/require.php";

// Get the config settings
$debug = $config->get('identity.debug', 'false');
$identity_path = $config->get('identity.path', '/etc/fusionpbx/certs');
$allowed_cidr = $config->get('identity.allowed_cidr', '127.0.0.1/32');
$setting_array['private_key_path'] = $identity_path . '/' . $config->get('identity.private_key_name', 'identity_private.key');
$setting_array['certificate_path'] = $identity_path . '/' . $config->get('identity.certificate_name', 'identity_certificate.pem');
$setting_array['certificate_url'] = $config->get('identity.certificate_url', '');
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
	$cidr_array = array_map('trim', explode(',', $allowed_cidr));
	if (!check_cidr($cidr_array, $remote_address)) {
		return;
	}

	// Get the variables
	$identity_header = $_REQUEST['identity'] ?? '';
	$format = $_REQUEST['format'] ?? '';
	$call_uuid = $_REQUEST['call_uuid'] ?? '';
	$domain_uuid = $_REQUEST['domain_uuid'] ?? '';
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
		'error' => ($result || $debug == 'false') ? '' : $identity->error_message,
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
elseif ($result === true) {
	echo 'TN-Validation-Passed-' . $identity->attest_level;
} else {
	if ($debug != 'false') {
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
	'identity_log_message' => $result === true ? '' : $identity->error_message,
]);
