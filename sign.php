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

//the cache folder is the server's, read from the default settings only, as the request's host picks the domain of the
//global settings
$cache_location = (string)(new settings(['database' => $database]))->get('cache', 'location', '/var/cache/fusionpbx');

// Branded calling settings (Rich Call Data). Branding is off unless brands_file is set.
$brands_file = $identity_settings['brands_file'];
$bcid_setting_array = [];
$bcid_certificate_url = $identity_settings['bcid_certificate_url'];
if (!empty($bcid_certificate_url)) {
	$bcid_setting_array['private_key_path'] = $identity_settings['bcid_private_key_path'];
	$bcid_setting_array['certificate_path'] = $identity_settings['bcid_certificate_path'];
	$bcid_setting_array['certificate_url'] = $bcid_certificate_url;
	$bcid_setting_array['debug'] = $debug;
}
$bcid_report_log = $identity_settings['bcid_report_log'];

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

	// Only a POST signs, as the routes send, so a link or redirect that makes the server fetch a url can not get a call
	// signed
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
		http_response_code(405);
		return;
	}

	// Check if the CIDR is allowed, and log a refused request so an unsigned call can be traced
	if (!identity_settings::is_allowed($allowed_cidr, $remote_address)) {
		identity_logs::add_refused($database, 'sign', $remote_address, $cache_location);
		return;
	}

	// Get the HTTP request values, a value that is not a string (such as source[]=) is treated as empty
	$request_value = function (string $name, string $default = ''): string {
		return is_string($_POST[$name] ?? null) ? $_POST[$name] : $default;
	};
	$source_number = $request_value('source');
	$destination_number = $request_value('destination');
	$attest_level = $request_value('attest_level', 'A');
	$call_reason = $request_value('call_reason');
	$call_uuid = $request_value('call_uuid');
	$domain_uuid = $request_value('domain_uuid');
}

// Initialize the identity object
$identity = new identity($setting_array);

// Sanitize and clean the source and destination
$source_number = $identity->normalize_tn($source_number);
$destination_number = $identity->normalize_tn($destination_number);

// A call that came in from outside and is going out again, such as one forwarded by call forward, a ring group or follow
// me, still has the outside caller's number, which nobody here authenticated, so it is signed as a gateway (C) whatever
// the route asks for. It is told by identity_origin, which this app's public dialplan exports to every call that comes
// in from outside and which, unlike call_direction, no outbound route sets again; a ring group or follow me leg gets it
// from the call it was made for. It is read from the call itself, so a route signed before needs no change.
$attest_note = '';
if (!is_cli() && strtoupper(trim((string)$attest_level)) !== 'C') {
	$origin = '';
	if (is_uuid($call_uuid)) {
		$esl = event_socket::create();
		$origin = $esl->is_connected() ? $esl->request('api uuid_getvar ' . $call_uuid . ' identity_origin') : '';
		$origin = is_string($origin) ? trim($origin) : '';
		unset($esl);
	}
	if ($origin === 'inbound') {
		$attest_level = 'C';
		$attest_note = 'Attestation lowered to C: the call came in from outside and is forwarded, so its caller was not authenticated here';
	}
	elseif ($origin === '' || strpos($origin, '-ERR') === 0) {
		//the origin could not be read, so the call is signed as the route asks, and the log says so
		$attest_note = 'The origin of the call could not be read, so a forwarded call was not lowered to C';
	}
	unset($origin);
}

// Full attestation (A) asserts that the domain owns the calling number. When the number is not an inbound destination
// of the domain, or the domain is not known, sign with partial attestation (B) instead. Not applied on the command line.
$attest_level = is_string($attest_level) ? strtoupper(trim($attest_level)) : '';
$number_owned = null;
if (!is_cli() && $identity_settings['attestation_check'] && $attest_level === 'A') {
	$numbers = new identity_numbers(['database' => $database]);
	$number_owned = $numbers->owns($source_number, is_string($domain_uuid) ? $domain_uuid : '');
	if (!$number_owned) {
		$attest_level = 'B';
		$attest_note = 'Attestation lowered from A to B: the calling number is not a destination of the domain';
	}
	unset($numbers);
}

// Look up a vetted brand for the calling number. Branding requires full (A) attestation, and the domain must own
// the calling number even when the attestation check is off, because the brands file is shared by every domain.
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
		if ($brand !== null && !is_cli()) {
			if ($number_owned === null) {
				$numbers = new identity_numbers(['database' => $database]);
				$number_owned = $numbers->owns($source_number, is_string($domain_uuid) ? $domain_uuid : '');
				unset($numbers);
			}
			if (!$number_owned) {
				error_log('identity: brand ' . $brand['brand_id'] . ' skipped for ' . $source_number . ': the calling number is not a destination of the domain');
				$brand = null;
			}
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
	if ($debug && is_cli()) {
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
	'identity_log_brand' => $payload['rcd']['nam'] ?? '',
	'identity_log_message' => !empty($result['error']) ? $result['error'] : $attest_note,
]);

// Delete the logs older than the retention period, at most once an hour, after the response was sent
identity_logs::purge_if_due($database, (int)$identity_settings['database_retention_days'], $cache_location);

// Add signing to the new outbound routes of every domain when Sign All Routes is on, at most once an hour, only
// when the response was already sent so the call never waits for it
if ($identity_settings['sign_all_routes'] && !is_cli() && function_exists('fastcgi_finish_request')) {
	identity_setup::sign_all_routes_if_due($database, $cache_location);
}
