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

// Branded calling settings (Rich Call Data). Branding is off unless brands_file is set.
$brands_file = $config->get('identity.brands_file', '');
$bcid_setting_array = [];
$bcid_certificate_url = $config->get('identity.bcid.certificate_url', '');
if (!empty($bcid_certificate_url)) {
	$bcid_setting_array['private_key_path'] = $identity_path . '/' . $config->get('identity.bcid.private_key_name', 'bcid_private.key');
	$bcid_setting_array['certificate_path'] = $identity_path . '/' . $config->get('identity.bcid.certificate_name', 'bcid_certificate.pem');
	$bcid_setting_array['certificate_url'] = $bcid_certificate_url;
	$bcid_setting_array['debug'] = $debug;
}
$bcid_report_log = $config->get('identity.bcid.report_log', '');

// Handle command line vs HTTP
if (is_cli()) {
	// Check for minimum command line arguments
	if ($argc < 3) {
		echo "Usage: {$argv[0]} <source> <destination> [attest_level] [call_reason]\n";
		echo "Example: {$argv[0]} 1234567890 0987654321 A \"Appointment reminder\"\n";
		exit(1);
	}

	// Get the command line arguments
	$source_number = $argv[1];
	$destination_number = $argv[2];
	$attest_level = $argv[3] ?? 'A';
	$call_reason = $argv[4] ?? '';
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
	$call_reason = $_REQUEST['call_reason'] ?? '';
	$call_uuid = $_REQUEST['call_uuid'] ?? '';
	$domain_uuid = $_REQUEST['domain_uuid'] ?? '';
}

// Initialize the identity object
$identity = new identity($setting_array);

// Sanitize and clean the source and destination
$source_number = $identity->normalize_tn($source_number);
$destination_number = $identity->normalize_tn($destination_number);

// Look up a vetted brand for the calling number. Branding requires full (A) attestation.
// Any error, including a malformed brands file, signs the call without branding.
$brand = null;
$rich_call_data = [];
$signing_profile = 'default';
if (!empty($brands_file) && $attest_level === 'A') {
	try {
		$brands = new identity_brands($brands_file);
		if ($brands->load()) {
			$brand = $brands->lookup($source_number);
		}
		else {
			error_log('identity: ' . $brands->error_message);
		}
		if ($brand !== null) {
			$brand_profile = $brand['profile'] ?? 'bcid';
			if ($brand_profile === 'bcid' && !empty($bcid_setting_array)) {
				$signing_profile = 'bcid';
			}
			elseif ($brand_profile === 'default') {
				$signing_profile = 'default';
			}
			else {
				// BCID brand but no BCID certificate configured: sign without branding
				error_log('identity: brand ' . $brand['brand_id'] . ' requires the bcid profile which is not configured');
				$brand = null;
			}
		}
		if ($brand !== null) {
			$call_reason = identity_brands::resolve_call_reason($brand, is_string($call_reason) ? $call_reason : '');
			$rich_call_data = identity_brands::to_rich_call_data($brand, $call_reason);
		}
	}
	catch (Throwable $e) {
		error_log('identity: branding skipped for ' . $source_number . ': ' . $e->getMessage());
		$brand = null;
		$rich_call_data = [];
		$signing_profile = 'default';
	}
}

// Use the BCID certificate profile for BCID brands
if ($signing_profile === 'bcid') {
	$identity = new identity($bcid_setting_array);
}

// Sign a call
$result = $identity->sign(
	$source_number,        // Source phone number
	$destination_number,   // Destination phone number
	$attest_level,         // Attestation level: A, B, or C
	'',                    // Origid (generated)
	0,                     // Issued at (now)
	$rich_call_data        // Rich Call Data (empty when not branded)
);

// Never lose call authentication because of branding: fall back to an unbranded signature
if (!empty($result['error']) && !empty($rich_call_data)) {
	error_log('identity: branded signing failed for ' . $source_number . ': ' . $result['error']);
	$brand = null;
	$signing_profile = 'default';
	$identity = new identity($setting_array);
	$result = $identity->sign($source_number, $destination_number, $attest_level);
}

// Respond with the SIP Identity header, or nothing if signing failed so no Identity header is sent
if (!empty($result['error'])) {
	if ($debug != 'false' && is_cli()) {
		echo $result['error'] . "\n";
	}
}
else {
	echo $identity->build_identity_header($result['passport']);
}

// Send the response to FreeSWITCH before writing the logs
if (function_exists('fastcgi_finish_request')) {
	fastcgi_finish_request();
}

// Decode the payload to log what was signed
$payload = [];
if (!empty($result['passport'])) {
	$payload = json_decode($identity->base64_url_decode(explode('.', $result['passport'])[1]), true) ?? [];
}

// Record branded calls signed with the BCID certificate for platform reporting
if ($brand !== null && $signing_profile === 'bcid' && !empty($bcid_report_log) && empty($result['error'])) {
	$reporter = new identity_bcid_reporter($bcid_report_log);
	$reporter->record_signing([
		'origid' => $payload['origid'] ?? '',
		'iat' => $payload['iat'] ?? time(),
		'orig' => $source_number,
		'dest' => $destination_number,
		'brand_id' => $brand['brand_id'],
		'crn' => $payload['crn'] ?? '',
		'profile' => $signing_profile,
	]);
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
	'identity_log_certificate_url' => $identity->get_config()['certificate_url'],
	'identity_log_origid' => $payload['origid'] ?? '',
	'identity_log_iat' => $payload['iat'] ?? '',
	'identity_log_message' => $result['error'] ?? '',
]);
