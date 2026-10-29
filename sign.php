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

// Handle command line vs HTTP
if (is_cli()) {
	// Check for minimum command line arguments
	if ($argc < 3) {
		echo "Usage: $argv[0] <source> <destination> [attest_level]\n";
		echo "Example: $argv[0] 1234567890 0987654321 A\n";
		exit(1);
	}

	// Get the command line arguments
	$source_number = $argv[1];
	$destination_number = $argv[2];
	$attest_level = $argv[3] ?? 'A';
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

	// Get the HTTP request values
	$source_number = $_REQUEST['source'] ?? '';
	$destination_number = $_REQUEST['destination'] ?? '';
	$attest_level = $_REQUEST['attest_level'] ?? 'A';
	$call_uuid = $_REQUEST['call_uuid'] ?? '';
	$domain_uuid = $_REQUEST['domain_uuid'] ?? '';
}

// Initialize the identity object
$identity = new identity($setting_array);

// Sanitize and clean the source and destination
$source_number = $identity->normalize_tn($source_number);
$destination_number = $identity->normalize_tn($destination_number);

// Sign a call
$result = $identity->sign(
	$source_number,        // Source phone number
	$destination_number,   // Destination phone number
	$attest_level          // Attestation level: A, B, or C
);

// Respond with the SIP Identity header, or nothing if signing failed so no Identity header is sent
if (!empty($result['error'])) {
	if ($debug != 'false' && is_cli()) {
		echo $result['error'] . "\n";
	}
}
else {
	echo $identity->build_identity_header($result['passport']);
}

// Send the response to FreeSWITCH before writing the log
if (function_exists('fastcgi_finish_request')) {
	fastcgi_finish_request();
}

// Decode the payload to log what was signed
$payload = [];
if (!empty($result['passport'])) {
	$payload = json_decode($identity->base64_url_decode(explode('.', $result['passport'])[1]), true) ?? [];
}

// Log the result
$identity_logs = new identity_logs(['database' => $database]);
$identity_logs->add([
	'domain_uuid' => $domain_uuid,
	'identity_log_call_uuid' => $call_uuid,
	'identity_log_type' => 'sign',
	'identity_log_status' => empty($result['error']) ? 'signed' : 'failed',
	'identity_log_attest' => $payload['attest'] ?? '',
	'identity_log_source' => $source_number,
	'identity_log_destination' => $destination_number,
	'identity_log_certificate_url' => $setting_array['certificate_url'],
	'identity_log_origid' => $payload['origid'] ?? '',
	'identity_log_iat' => $payload['iat'] ?? '',
	'identity_log_message' => $result['error'] ?? '',
]);
