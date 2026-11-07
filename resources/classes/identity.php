<?php

/**
 * STIR/SHAKEN Call Sign and Verify Module for FusionPBX
 *
 * This module signs outgoing phone calls with STIR/SHAKEN PASSporT tokens
 * to authenticate call origins and prevent caller ID spoofing.
 *
 * RFC 8225 - PASSporT (PASSport for Telephone Identity)
 * RFC 8228 - Authentication, Authorization, and Accounting for the
 *			Secure Telephone Identity (STI)
 */
class identity {

	/**
	 * Error message from verification or signing operations.
	 *
	 * @var string
	 */
	public $error_message = '';

	/**
	 * Attestation level from verified PASSporT token.
	 *
	 * @var string
	 */
	public $attest_level = '';

	/**
	 * Decoded PASSporT header and payload from the last verify() call.
	 *
	 * @var array Array with keys: header (array), payload (array)
	 */
	public $passport = ['header' => [], 'payload' => []];

	/**
	 * Seconds to wait when downloading the certificate during verification.
	 *
	 * @var int
	 */
	public $certificate_timeout = 2;

	/**
	 * Largest certificate download accepted. A certificate with its chain is a few KB.
	 */
	const MAX_CERTIFICATE_SIZE = 65536;

	/**
	 * Address ranges a certificate URL may never connect to: private, loopback, link-local, shared (CGNAT), benchmark,
	 * multicast, reserved and documentation ranges, deprecated IPv6 site-local addresses, the reserved IPv6 ::/8 block
	 * (which holds the IPv4-compatible and SIIT IPv4-translated forms), and IPv6 transition ranges that can wrap any IPv4
	 * address (6to4, Teredo, NAT64). IPv4-mapped addresses are checked as IPv4 before these ranges. Checked in addition
	 * to FILTER_FLAG_GLOBAL_RANGE, which misses some of them.
	 */
	const BLOCKED_RANGES = [
		'0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
		'192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
		'224.0.0.0/4', '240.0.0.0/4',
		'::/8', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32', '2002::/16', 'fc00::/7',
		'fe80::/10', 'fec0::/10', 'ff00::/8',
	];

	/**
	 * Seconds a verified PASSporT may be older or newer than the local clock (ATIS-1000074 freshness).
	 */
	const MAX_PASSPORT_AGE = 60;

	/**
	 * Seconds a downloaded certificate is cached in APCu when it is available.
	 */
	const CERTIFICATE_CACHE_TTL = 300;

	/**
	 * Rich Call Data claim from a verified PASSporT (empty if none).
	 *
	 * @var array
	 */
	public $rcd = [];

	/**
	 * Rich Call Data integrity claim from a verified PASSporT (empty if none).
	 *
	 * @var array
	 */
	public $rcdi = [];

	/**
	 * Call reason claim from a verified PASSporT (empty if none).
	 *
	 * @var string
	 */
	public $crn = '';

	/**
	 * Result of the last verify() call, the value returned to FreeSWITCH: TN-Validation-Passed-A, -B or -C,
	 * TN-Validation-Failed when the header is bad, or No-TN-Validation when it could not be checked completely.
	 *
	 * @var string
	 */
	public $verstat = 'No-TN-Validation';

	/**
	 * OID of the TNAuthList extension that every STIR/SHAKEN certificate carries (RFC 8226, ATIS-1000080).
	 */
	const TN_AUTH_LIST_OID = '1.3.6.1.5.5.7.1.26';

	/**
	 * JSON encoding flags used for PASSporT header, payload and rcdi digests.
	 * Slashes and unicode are left unescaped so URLs and display names are
	 * serialized the way RFC 8225 / RFC 9795 examples show them.
	 */
	const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * Maximum display name length allowed in rcd "nam" (BCID allows 35).
	 */
	const MAX_NAME_LENGTH = 35;

	/**
	 * Path to the private key file for signing.
	 *
	 * @var string
	 */
	private $private_key_path = '';

	/**
	 * Path to the certificate file for signing.
	 *
	 * @var string
	 */
	private $certificate_path = '';

	/**
	 * URL to download the public certificate during verification.
	 *
	 * @var string
	 */
	private $certificate_url = '';

	/**
	 * Path to the PEM file of the STI-CA root certificates that verified certificates must chain to.
	 *
	 * @var string
	 */
	private $trust_list = '';

	/**
	 * URL of the CRL that certificates are checked against, or empty to not check revocation.
	 *
	 * @var string
	 */
	private $crl_url = '';

	/**
	 * Directory the downloaded CRL is kept in between calls, the FusionPBX cache location.
	 *
	 * @var string
	 */
	private $cache_path = '/var/cache/fusionpbx';

	/**
	 * Default attestation level (A, B, or C) for new PASSporTs.
	 *
	 * @var string
	 */
	private $default_attest_level = 'C';

	/**
	 * Signature algorithm (ES256 for P-256 curve).
	 *
	 * @var string
	 */
	private $algorithm = 'ES256';

	/**
	 * Initialize with configuration settings.
	 *
	 * Loads certificate and key paths from the provided configuration array. Sets
	 * up instance properties for signing and verification operations.
	 *
	 * @param array $setting_array Configuration array with keys: private_key_path,
	 *                               certificate_path, certificate_url
	 *
	 * @return void
	 */
	public function __construct(array $setting_array = []) {
		// Load default configuration
		$this->private_key_path = $setting_array['private_key_path'] ?? '';
		$this->certificate_path = $setting_array['certificate_path'] ?? '';
		$this->certificate_url = $setting_array['certificate_url'] ?? '';
		$this->trust_list = $setting_array['trust_list'] ?? '';
		$this->crl_url = $setting_array['crl_url'] ?? '';
		$this->cache_path = $setting_array['cache_path'] ?? '/var/cache/fusionpbx';
	}

	/**
	 * Set custom configuration properties dynamically.
	 *
	 * Updates instance properties with values from the configuration array. Only
	 * properties that exist on the class are updated.
	 *
	 * @param array $config Configuration array with property names and values
	 *
	 * @return void
	 */
	public function set_config(array $config) {
		foreach ($config as $key => $value) {
			if (property_exists($this, $key)) {
				$this->$key = $value;
			}
		}
	}

	/**
	 * Get current configuration as associative array.
	 *
	 * Returns all configuration properties including private key path, certificate
	 * path, URL, attestation level, and algorithm.
	 *
	 * @return array Configuration array with keys: private_key_path, certificate_path,
	 *               certificate_url, attest_level, algorithm
	 */
	public function get_config() {
		return [
			'private_key_path' => $this->private_key_path,
			'certificate_path' => $this->certificate_path,
			'certificate_url' => $this->certificate_url,
			'attest_level' => $this->default_attest_level,
			'algorithm' => $this->algorithm
		];
	}

	/**
	 * Sign a call with a STIR/SHAKEN PASSporT token.
	 *
	 * Generates a PASSporT token for the given call parameters using the configured
	 * private key. Returns the signed JWT or an error message if signing fails.
	 * Uses current time if iat is not provided and generates a UUID if origid is
	 * not provided.
	 *
	 * @param string $orig_tn      Originator phone number (e.g., "1234567890")
	 * @param string $dest_tn      Destination phone number (e.g., "0987654321")
	 * @param string $attest_level Attestation level (A, B, or C); uses default if empty
	 * @param string $origid       Optional originator UUID; generated if empty
	 * @param int    $iat          Optional issued-at timestamp; uses current time if zero
	 * @param array  $rich_call_data Optional Rich Call Data (RFC 9795) with keys:
	 *                               nam (display name), icn (HTTPS logo URL),
	 *                               icn_digest (precomputed "sha256-..." digest of
	 *                               the logo bytes; required when icn is set),
	 *                               crn (call reason for this call)
	 *
	 * @return array Array with keys: passport (string), error (string|null)
	 */
	public function sign(string $orig_tn, string $dest_tn, string $attest_level = '', string $origid = '', int $iat = 0, array $rich_call_data = []): array {

		// Validate inputs
		if (empty($orig_tn)) {
			return ['passport' => '', 'error' => 'Missing originator phone number'];
		}
		if (empty($dest_tn)) {
			return ['passport' => '', 'error' => 'Missing destination phone number'];
		}
		if (empty($attest_level) || !in_array($attest_level, ['A', 'B', 'C'])) {
			$attest_level = $this->default_attest_level;
		}

		// Generate origid if not provided
		if (empty($origid)) {
			$origid = $this->generate_uuid();
		}

		// Use current time if iat not provided
		if ($iat === 0) {
			$iat = time();
		}

		// Build PASSporT header
		$header = $this->build_header();

		// Build PASSporT payload
		$payload = $this->build_payload($orig_tn, $dest_tn, $attest_level, $iat, $origid);

		// Add Rich Call Data claims (rcd, rcdi, crn) when provided
		if (!empty($rich_call_data)) {
			$rcd_result = $this->build_rich_call_data($rich_call_data);
			if (!empty($rcd_result['error'])) {
				return ['passport' => '', 'error' => $rcd_result['error']];
			}
			$payload = array_merge($payload, $rcd_result['claims']);
		}

		// Sign the PASSporT
		$result = $this->sign_passport($header, $payload);

		return $result;
	}

	/**
	 * Verify a PASSporT and decide the verification status of the call.
	 *
	 * The header must be well formed, recent (iat) and not already used (replay, with APCu), its calling and called
	 * numbers must be those of the call, the certificate at its x5u URL must carry TNAuthList and chain to the trust
	 * list, and the signature must be valid. Sets verstat to TN-Validation-Passed-<attest> when every check passes,
	 * TN-Validation-Failed when one fails, and No-TN-Validation when the header is valid but could not be checked
	 * completely: no usable trust list, or the call's numbers were not given. Rich Call Data is only kept on a pass.
	 *
	 * @param string      $identity_header The SIP Identity header, or the PASSporT alone
	 * @param string      $certificate_pem Certificate to check the signature with instead of downloading x5u. The trust
	 *                                     list and the numbers are not checked; used by Setup to test the local key
	 * @param string|null $orig_tn         Calling number of the call, or null when it is not known
	 * @param string|null $dest_tn         Called number of the call, or null when it is not known
	 *
	 * @return bool True only when every check passed
	 */
	public function verify($identity_header, string $certificate_pem = '', ?string $orig_tn = null, ?string $dest_tn = null): bool {

		// Reset the results from any previous call
		$this->attest_level = '';
		$this->passport = ['header' => [], 'payload' => []];
		$this->rcd = [];
		$this->rcdi = [];
		$this->crn = '';
		$this->verstat = 'TN-Validation-Failed';

		// Split the token into its 3 parts
		$parts = preg_split('/\./', $identity_header);

		if (count($parts) < 3) {
			$this->error_message = "Invalid PASSporT format.";
			return false;
		}

		// Extract header, payload, and signature
		$header_b64 = $parts[0];
		$payload_b64 = $parts[1];
		$signature_b64 = $parts[2];

		// Remove signature parameters (everything after the first semicolon)
		$signature_with_params = explode(';', $signature_b64);
		$signature = $signature_with_params[0];

		// Decode header (JWT uses base64url encoding)
		$header = $this->base64_url_decode($header_b64);
		if ($header === false) {
			$this->error_message = "base64_url_decode failed for header";
			return false;
		}

		// Decode payload (JWT uses base64 url encoding)
		$payload = $this->base64_url_decode($payload_b64);
		if ($payload === false) {
			$this->error_message = "base64_url_decode failed for payload";
			return false;
		}

		// Decode payload
		$payload_json = json_decode($payload, true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload_json)) {
			$this->error_message = "Decode payload failed: " . json_last_error_msg();
			return false;
		}

		// Decode the header
		$header_json = json_decode($header, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->error_message = "json decode failed for header: " . json_last_error_msg();
			return false;
		}

		// Save the decoded PASSporT so the caller can log it
		$this->passport['header'] = is_array($header_json) ? $header_json : [];
		$this->passport['payload'] = is_array($payload_json) ? $payload_json : [];

		// Verify PASSporT type
		if (!isset($header_json['typ']) || $header_json['typ'] !== 'passport') {
			$this->error_message = "Verify type invalid";
			return false;
		}

		// Verify PASSporT extension type
		if (!isset($header_json['ppt']) || $header_json['ppt'] !== 'shaken') {
			$this->error_message = "Verify extension type invalid";
			return false;
		}

		// Verify algorithm
		if (!isset($header_json['alg']) || $header_json['alg'] !== 'ES256') {
			$this->error_message = "Verify algorithm invalid";
			return false;
		}

		// Verify certificate
		if (!isset($header_json['x5u'])) {
			$this->error_message = "Verify certificate URL missing";
			return false;
		}

		// Get the certificate URL from the header
		$header_certificate_url = $header_json['x5u'];
		if (!is_string($header_certificate_url)) {
			$this->error_message = "Verify certificate URL invalid";
			return false;
		}

		// Attestation must be A, B or C, it is returned to FreeSWITCH in identity_verstat
		if (!in_array($payload_json['attest'] ?? null, ['A', 'B', 'C'], true)) {
			$this->error_message = "Attestation missing or not A, B or C";
			return false;
		}

		// Freshness: refuse a PASSporT issued too long ago or in the future, before anything is downloaded
		if (!is_int($payload_json['iat'] ?? null) || abs(time() - $payload_json['iat']) > self::MAX_PASSPORT_AGE) {
			$this->error_message = "PASSporT iat is missing or more than " . self::MAX_PASSPORT_AGE . " seconds from the current time";
			return false;
		}

		// The PASSporT must be for this call: its calling and called numbers must be those of the call (ATIS-1000074).
		// Checked before anything is downloaded and before the replay claim, so a header copied onto another call
		// fails without blocking the call it was signed for.
		$numbers_given = $orig_tn !== null && $dest_tn !== null;
		if ($numbers_given && !$this->numbers_match($payload_json, $orig_tn, $dest_tn)) {
			return false;
		}

		// Replay: refuse a PASSporT already presented within the freshness window (needs APCu, shared by the PHP-FPM
		// workers). The key is the signed payload, which has a unique origid per call and cannot be re-encoded without
		// breaking the signature, unlike the signature text. apcu_add() claims it atomically before the download, and
		// the claim is released unless verification passes so a forged copy cannot block the real call.
		$replay_key = 'identity_replay_' . hash('sha256', $payload);
		$replay_cache = empty($certificate_pem) && function_exists('apcu_enabled') && apcu_enabled();
		if ($replay_cache && !apcu_add($replay_key, 1, 2 * self::MAX_PASSPORT_AGE)) {
			$this->error_message = "PASSporT was already used for another call";
			return false;
		}

		// Check the signature, then that the certificate belongs to an authorized signer
		$certificate_string = '';
		$passed = $this->check_signature($header_b64, $payload_b64, $signature, $header_certificate_url, $certificate_pem, $certificate_string);
		if ($passed && empty($certificate_pem)) {
			$passed = $this->check_authorization($certificate_string, $numbers_given);
		}

		// Keep the claims only when every check passed
		if ($passed) {
			$this->attest_level = $payload_json['attest'];
			if (isset($payload_json['rcd']) && is_array($payload_json['rcd'])) {
				$this->rcd = $payload_json['rcd'];
			}
			if (isset($payload_json['rcdi']) && is_array($payload_json['rcdi'])) {
				$this->rcdi = $payload_json['rcdi'];
			}
			if (isset($payload_json['crn']) && is_string($payload_json['crn'])) {
				$this->crn = $payload_json['crn'];
			}
			$this->verstat = 'TN-Validation-Passed-' . $this->attest_level;
			$this->error_message = "";
		}
		elseif ($replay_cache) {
			apcu_delete($replay_key);
		}
		return $passed;
	}

	/**
	 * Check that the numbers of a PASSporT are those of the call.
	 *
	 * Both sides are normalized with normalize_tn(), so 2025550100, 12025550100 and +12025550100 are the same number.
	 * The calling number must equal orig.tn and the called number must be one of dest.tn.
	 *
	 * @param array  $payload Decoded PASSporT payload
	 * @param string $orig_tn Calling number of the call
	 * @param string $dest_tn Called number of the call
	 *
	 * @return bool True if both match, otherwise false with error_message set
	 */
	private function numbers_match(array $payload, string $orig_tn, string $dest_tn): bool {
		$signed_orig = is_string($payload['orig']['tn'] ?? null) ? $this->normalize_tn($payload['orig']['tn']) : '';
		$call_orig = $this->normalize_tn($orig_tn);
		if ($signed_orig === '' || $signed_orig !== $call_orig) {
			$this->error_message = "The calling number " . $call_orig . " is not the signed number " . $signed_orig;
			return false;
		}
		$call_dest = $this->normalize_tn($dest_tn);
		$signed_dests = [];
		foreach (is_array($payload['dest']['tn'] ?? null) ? $payload['dest']['tn'] : [] as $tn) {
			if (is_string($tn)) {
				$signed_dests[] = $this->normalize_tn($tn);
			}
		}
		if ($call_dest === '' || !in_array($call_dest, $signed_dests, true)) {
			$this->error_message = "The called number " . $call_dest . " is not a signed number " . implode(',', $signed_dests);
			return false;
		}
		return true;
	}

	/**
	 * Check that a validly signed PASSporT comes from an authorized signer and was checked against the call.
	 *
	 * A certificate that is not a STIR/SHAKEN certificate, does not chain to the trust list, or is revoked, or that
	 * was served with a revoked intermediate certificate, fails (TN-Validation-Failed). Without a usable trust list,
	 * without a current CRL when revocation is checked, or without the numbers of the call, the PASSporT cannot be
	 * fully checked and the result is No-TN-Validation rather than a pass or a failure.
	 *
	 * @param string $certificate_string Certificate downloaded from x5u, with any intermediate certificates
	 * @param bool   $numbers_given      Whether the numbers of the call were checked
	 *
	 * @return bool True if the signer is authorized and the numbers were checked
	 */
	private function check_authorization(string $certificate_string, bool $numbers_given): bool {
		if (!$this->trust_list_usable()) {
			$this->verstat = 'No-TN-Validation';
			$this->error_message = $this->trust_list === '' ? "No trust list is set, the signer was not checked" : "The trust list " . $this->trust_list . " cannot be read, the signer was not checked";
			return false;
		}

		// The signer's certificate first, then any intermediate certificates served with it
		preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $certificate_string, $matches);
		$certificates = [];
		foreach ($matches[0] as $block) {
			$certificate = @openssl_x509_read($block);
			if ($certificate === false) {
				break;
			}
			$certificates[] = $certificate;
		}
		while (openssl_error_string()) {}
		if (empty($certificates) || count($certificates) != count($matches[0])) {
			$this->error_message = "Failed to read certificate.";
			return false;
		}

		if (!$this->check_certificate_chain($certificates)) {
			return false;
		}
		if (!$this->check_revocation($certificates)) {
			return false;
		}
		if (!$numbers_given) {
			$this->verstat = 'No-TN-Validation';
			$this->error_message = "The calling and called numbers of the call were not given, they were not checked";
			return false;
		}
		return true;
	}

	/**
	 * Check that the trust list is set and can be read.
	 *
	 * @return bool True if the trust list can be used
	 */
	private function trust_list_usable(): bool {
		return $this->trust_list !== '' && is_file($this->trust_list) && is_readable($this->trust_list);
	}

	/**
	 * Check that a certificate is a STIR/SHAKEN certificate that chains to the trust list.
	 *
	 * @param array $certificates The signer's certificate, then the intermediate certificates served with it
	 *
	 * @return bool True if the certificate chains to the trust list, otherwise false with error_message set
	 */
	private function check_certificate_chain(array $certificates): bool {
		// Every STIR/SHAKEN certificate carries TNAuthList, a certificate issued for anything else does not
		$extensions = openssl_x509_parse($certificates[0])['extensions'] ?? [];
		if (!isset($extensions[self::TN_AUTH_LIST_OID]) && !isset($extensions['tnAuthList']) && !isset($extensions['TNAuthList'])) {
			$this->error_message = "The certificate is not a STIR/SHAKEN certificate (no TNAuthList extension)";
			return false;
		}
		return $this->chains_to_trust_list($certificates[0], array_slice($certificates, 1));
	}

	/**
	 * Check that a certificate chains to a certificate in the trust list, and to nothing else.
	 *
	 * The intermediate certificates are only used to build the chain. openssl_x509_checkpurpose() adds the system
	 * certificate directory (the web PKI roots) unless a directory is given, so an empty temporary directory is passed
	 * with the trust list. The dates of every certificate in the chain are checked, revocation is not.
	 *
	 * @param \OpenSSLCertificate $certificate   Certificate to check
	 * @param array               $intermediates Intermediate certificates (OpenSSLCertificate) to build the chain with
	 *
	 * @return bool True if the certificate chains to the trust list, otherwise false with error_message set
	 */
	public function chains_to_trust_list($certificate, array $intermediates = []): bool {
		if (!$this->trust_list_usable()) {
			$this->error_message = "The trust list is not set or cannot be read";
			return false;
		}

		// Temporary files: an empty directory so only the trust list is trusted, and the intermediate certificates
		$empty_directory = sys_get_temp_dir() . '/identity_trust_' . bin2hex(random_bytes(8));
		if (!@mkdir($empty_directory, 0700)) {
			$this->error_message = "Unable to create a temporary directory in " . sys_get_temp_dir();
			return false;
		}
		$untrusted_file = null;
		if (!empty($intermediates)) {
			$pem = '';
			foreach ($intermediates as $intermediate) {
				openssl_x509_export($intermediate, $intermediate_pem);
				$pem .= $intermediate_pem;
			}
			$untrusted_file = @tempnam(sys_get_temp_dir(), 'identity_chain_');
			if ($untrusted_file === false || @file_put_contents($untrusted_file, $pem) === false) {
				if ($untrusted_file !== false) {
					@unlink($untrusted_file);
				}
				@rmdir($empty_directory);
				$this->error_message = "Unable to write a temporary file in " . sys_get_temp_dir();
				return false;
			}
		}

		// Build and check the chain
		$result = openssl_x509_checkpurpose($certificate, X509_PURPOSE_ANY, [$this->trust_list, $empty_directory], $untrusted_file);
		while (openssl_error_string()) {}
		if ($untrusted_file !== null) {
			@unlink($untrusted_file);
		}
		@rmdir($empty_directory);

		if ($result !== true) {
			$this->error_message = "The certificate does not chain to the trust list, or a certificate in the chain is expired or not yet valid";
			return false;
		}
		return true;
	}

	/**
	 * Check that neither the signer's certificate nor an intermediate certificate served with it is revoked.
	 *
	 * Uses the CRL at crl_url (see identity_crl). Not checked when crl_url is empty. Without a current, valid CRL the
	 * certificate cannot be checked and the result is No-TN-Validation.
	 *
	 * @param array $certificates The signer's certificate, then the intermediate certificates served with it
	 *
	 * @return bool True if none is revoked, otherwise false with verstat and error_message set
	 */
	private function check_revocation(array $certificates): bool {
		if ($this->crl_url === '') {
			return true;
		}
		$crl = new identity_crl($this, $this->crl_url, $this->cache_path);
		if (!$crl->load()) {
			$this->verstat = 'No-TN-Validation';
			$this->error_message = "Revocation could not be checked: " . $crl->error_message;
			return false;
		}
		foreach ($certificates as $i => $certificate) {
			if ($crl->is_revoked($certificate)) {
				$this->verstat = 'TN-Validation-Failed';
				$this->error_message = ($i == 0 ? "The certificate is revoked: " : "An intermediate certificate is revoked: ") . (openssl_x509_parse($certificate)['name'] ?? '');
				return false;
			}
		}
		return true;
	}

	/**
	 * Check the signature of a decoded PASSporT.
	 *
	 * @param string $header_b64             Header as sent, base64url encoded
	 * @param string $payload_b64            Payload as sent, base64url encoded
	 * @param string $signature              Signature as sent, base64url encoded
	 * @param string $header_certificate_url Certificate URL (x5u) from the header
	 * @param string $certificate_pem        Certificate to use instead of downloading the URL, or empty
	 * @param string $certificate_string     Set to the certificate that was used, with any chain that came with it
	 *
	 * @return bool True if the signature is valid
	 */
	private function check_signature(string $header_b64, string $payload_b64, string $signature, string $header_certificate_url, string $certificate_pem, string &$certificate_string): bool {
		// Use the provided certificate or download it from the header URL
		if (!empty($certificate_pem)) {
			$header_certificate_string = $certificate_pem;
		}
		else {
			// The URL comes from the caller: fetch_certificate() limits it to https on a public address
			$header_certificate_string = $this->fetch_certificate($header_certificate_url);
			if ($header_certificate_string === false) {
				return false;
			}
		}
		$certificate_string = $header_certificate_string;

		// Verify signature - The JWT signature is computed over ASCII(base64url(header) || '.' || base64url(payload))
		$this->error_message = "Verifying signature...";
		$secret = $header_certificate_string;
		$signing_input = $header_b64 . '.' . $payload_b64;

		// Read certificate from secret (which is the certificate content), it comes from the caller so no PHP warning
		$certificate = @openssl_x509_read($secret);
		if (!$certificate) {
			while (openssl_error_string()) {}
			$this->error_message = "Failed to read certificate.";
			return false;
		}

		// Extract public key from certificate
		$public_key = openssl_pkey_get_public($certificate);
		if (!$public_key) {
			$this->error_message = "Failed to extract public key.";
			return false;
		}

		// Convert JWT P1363 signature (raw R||S) to DER format required by OpenSSL
		try {
			$sig_der = $this->p1363_to_der($this->base64_url_decode($signature));
		} catch (Exception $e) {
			$this->error_message = "Invalid signature: " . $e->getMessage();
			return false;
		}

		// Verify the signature
		$result = openssl_verify($signing_input, $sig_der, $public_key, OPENSSL_ALGO_SHA256);

		// Free the key from memory (openssl_free_key is deprecated in PHP 8+)
		unset($public_key);

		// Check for errors
		$error = '';
		while ($msg = openssl_error_string()) {
			$error .= $msg;
		}

		if (!empty($error)) {
			$this->error_message = "OpenSSL errors: " . $error;
		}

		// Return result
		if ($result == 1) {
			$this->error_message = "";
			return true;
		}
		elseif ($result === 0) {
			$this->error_message = "Signature is invalid.";
			return false;
		}
		else {
			$this->error_message = "Error checking signature.";
			return false;
		}
	}

	/**
	 * Build the Rich Call Data claims for a PASSporT payload.
	 *
	 * Produces the "rcd", "rcdi" and "crn" claims defined in RFC 9795 for use
	 * inside a SHAKEN PASSporT (RFC 9795 section 13). "rcdi" is only added
	 * when the rcd contains a URI reference (the logo), because integrity
	 * protection is required for referenced content. The logo digest must be
	 * precomputed when the brand is provisioned so no HTTP fetch happens
	 * during call setup.
	 *
	 * @param array $rich_call_data Keys: nam, icn, icn_digest, crn
	 *
	 * @return array Array with keys: claims (array), error (string|null)
	 */
	public function build_rich_call_data(array $rich_call_data): array {
		$claims = [];
		$rcd = [];
		$rcdi = [];

		foreach (['nam', 'icn', 'icn_digest', 'crn'] as $key) {
			if (isset($rich_call_data[$key]) && !is_string($rich_call_data[$key])) {
				return ['claims' => [], 'error' => "Rich Call Data {$key} must be a string"];
			}
		}
		$nam = trim($rich_call_data['nam'] ?? '');
		$icn = trim($rich_call_data['icn'] ?? '');
		$icn_digest = trim($rich_call_data['icn_digest'] ?? '');
		$crn = trim($rich_call_data['crn'] ?? '');

		// The rcd object requires nam (RFC 9795 section 5.1.1)
		if ($nam === '' && $icn !== '') {
			return ['claims' => [], 'error' => 'Rich Call Data logo requires a display name'];
		}

		if ($nam !== '') {
			$error = $this->validate_display_name($nam);
			if ($error !== null) {
				return ['claims' => [], 'error' => $error];
			}
			$rcd['nam'] = $nam;
		}

		if ($icn !== '') {
			if (stripos($icn, 'https://') !== 0) {
				return ['claims' => [], 'error' => 'Rich Call Data logo URL must use https'];
			}
			if (!$this->is_valid_digest($icn_digest)) {
				return ['claims' => [], 'error' => 'Rich Call Data logo digest missing or invalid'];
			}
			$rcd['icn'] = $icn;
			$rcdi['/icn'] = $icn_digest;
		}

		if (!empty($rcd)) {
			$claims['rcd'] = $rcd;
		}
		if (!empty($rcdi)) {
			$claims['rcdi'] = $rcdi;
		}

		if ($crn !== '') {
			if (self::str_length($crn) > 64 || preg_match('/[\x00-\x1F\x7F]/', $crn)) {
				return ['claims' => [], 'error' => 'Call reason is too long or contains control characters'];
			}
			$claims['crn'] = $crn;
		}

		return ['claims' => $claims, 'error' => null];
	}

	/**
	 * Compute an rcdi digest over raw bytes (e.g. a downloaded logo image).
	 *
	 * Format is "<alg>-<base64>" using standard base64 without padding, as in
	 * the RFC 9795 examples.
	 *
	 * @param string $bytes     Raw content
	 * @param string $algorithm sha256, sha384 or sha512
	 *
	 * @return string Digest string such as "sha256-..."
	 */
	public static function compute_digest(string $bytes, string $algorithm = 'sha256'): string {
		if (!in_array($algorithm, ['sha256', 'sha384', 'sha512'], true)) {
			throw new InvalidArgumentException("Unsupported digest algorithm: {$algorithm}");
		}
		return $algorithm . '-' . rtrim(base64_encode(hash($algorithm, $bytes, true)), '=');
	}

	/**
	 * Compute an rcdi digest over an inline JSON value (e.g. "/nam").
	 *
	 * The value is serialized as compact JSON, so strings include their
	 * surrounding double quotes (RFC 9795 section 6.1).
	 *
	 * @param mixed  $value     JSON value
	 * @param string $algorithm sha256, sha384 or sha512
	 *
	 * @return string Digest string such as "sha256-..."
	 */
	public static function compute_json_digest($value, string $algorithm = 'sha256'): string {
		return self::compute_digest(json_encode($value, self::JSON_FLAGS), $algorithm);
	}

	/**
	 * Check that a value looks like an rcdi digest string.
	 *
	 * @param string $digest Digest string
	 *
	 * @return bool True if the format is valid
	 */
	private function is_valid_digest(string $digest): bool {
		return (bool)preg_match('/^(sha256|sha384|sha512)-[A-Za-z0-9+\/]+={0,2}$/', $digest);
	}

	/**
	 * Count characters in a UTF-8 string without requiring mbstring.
	 *
	 * @param string $value UTF-8 string
	 *
	 * @return int Number of characters
	 */
	private static function str_length(string $value): int {
		if (function_exists('mb_strlen')) {
			return mb_strlen($value, 'UTF-8');
		}
		return (int)preg_match_all('/./us', $value);
	}

	/**
	 * Validate a display name for the rcd "nam" claim.
	 *
	 * @param string $nam Display name
	 *
	 * @return string|null Error message or null when valid
	 */
	private function validate_display_name(string $nam): ?string {
		if (!preg_match('//u', $nam)) {
			return 'Display name must be valid UTF-8';
		}
		if (self::str_length($nam) > self::MAX_NAME_LENGTH) {
			return 'Display name exceeds ' . self::MAX_NAME_LENGTH . ' characters';
		}
		if (preg_match('/[\x00-\x1F\x7F"\\\\]/', $nam)) {
			return 'Display name contains control characters, quotes or backslashes';
		}
		return null;
	}

	/**
	 * Build JWT header with algorithm and certificate information.
	 *
	 * Creates a standard STIR/SHAKEN JWT header array with algorithm (ES256),
	 * passport type indicator (shaken), JWT type indicator (passport), and the
	 * certificate URL for public key retrieval during verification.
	 *
	 * @return array Header array with keys: alg, ppt, typ, x5u
	 */
	private function build_header(): array {
		return [
			'alg' => $this->algorithm,
			'ppt' => 'shaken',
			'typ' => 'passport',
			'x5u' => $this->certificate_url
		];
	}

	/**
	 * Build JWT payload with call and attestation information.
	 *
	 * Creates the STIR/SHAKEN JWT payload structure containing originator and
	 * destination phone numbers, attestation level, issued-at timestamp, and
	 * originator identifier.
	 *
	 * @param string $orig_tn  Originator phone number
	 * @param string $dest_tn  Destination phone number
	 * @param string $attest   Attestation level (A, B, or C)
	 * @param int    $iat      Issued-at Unix timestamp
	 * @param string $origid   Originator UUID identifier
	 *
	 * @return array Payload array with keys: attest, dest, iat, orig, origid
	 */
	private function build_payload(string $orig_tn, string $dest_tn, string $attest, int $iat, string $origid): array {
		$payload = [
			'attest' => $attest,
			'dest' => ['tn' => [$dest_tn]],
			'iat' => $iat,
			'orig' => ['tn' => $orig_tn],
			'origid' => $origid
		];

		return $payload;
	}

	/**
	 * Sign header and payload and create complete PASSporT token.
	 *
	 * Encodes header and payload to base64url, signs the concatenation with the
	 * configured private key using ECDSA SHA256, converts the signature from DER
	 * to IEEE P1363 format, and returns the complete JWT.
	 *
	 * @param array $header Header array to encode
	 * @param array $payload Payload array to encode
	 *
	 * @return array Array with keys: passport (string), error (string|null)
	 */
	private function sign_passport(array $header, array $payload): array {
		// Encode header and payload to base64url
		$encoded_header = $this->base64_url_encode(json_encode($header, self::JSON_FLAGS));
		$encoded_payload = $this->base64_url_encode(json_encode($payload, self::JSON_FLAGS));

		// Create signing input
		$signing_input = $encoded_header . '.' . $encoded_payload;

		// Load private key
		$private_key = $this->load_private_key();
		if ($private_key === false) {
			return ['passport' => '', 'error' => 'Failed to load private key'];
		}

		// Sign with ECDSA SHA256
		$signature = '';
		if (!openssl_sign($signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256)) {
			return ['passport' => '', 'error' => 'Signing failed: ' . openssl_error_string()];
		}

		// Convert DER signature to raw R||S format (IEEE P1363)
		$raw_signature = $this->der_to_raw($signature);
		if ($raw_signature === false) {
			return ['passport' => '', 'error' => 'Failed to convert signature format'];
		}

		// Encode signature to base64url
		$encoded_signature = $this->base64_url_encode($raw_signature);

		// Create compact JWS
		$passport = $signing_input . '.' . $encoded_signature;

		return ['passport' => $passport, 'error' => null];
	}

	/**
	 * Load private key from configured file path.
	 *
	 * Reads the private key PEM file and returns an OpenSSL resource. Returns
	 * false if the file path is empty or the key cannot be loaded.
	 *
	 * @return \OpenSSLAsymmetricKey|false OpenSSL private key resource or false on failure
	 */
	private function load_private_key() {
		if (empty($this->private_key_path)) {
			return false;
		}

		$key_content = file_get_contents($this->private_key_path);
		if ($key_content === false) {
			return false;
		}

		return openssl_pkey_get_private($key_content);
	}

	/**
	 * Download a certificate from a URL, using the APCu cache when it is available.
	 *
	 * The x5u URL of an inbound call comes from the caller, so it is downloaded with fetch_url() and its restrictions.
	 * Certificates are cached in APCu for CERTIFICATE_CACHE_TTL seconds when it is available.
	 *
	 * @param string $url       Certificate URL
	 * @param bool   $use_cache Use and fill the APCu cache
	 *
	 * @return string|false The downloaded content, or false with error_message set
	 */
	public function fetch_certificate(string $url, bool $use_cache = true) {
		// Use the cached certificate
		$cache_key = 'identity_x5u_' . hash('sha256', $url);
		$apcu = $use_cache && function_exists('apcu_enabled') && apcu_enabled();
		if ($apcu) {
			$cached = apcu_fetch($cache_key, $found);
			if ($found && is_string($cached)) {
				return $cached;
			}
		}

		// Download it
		$body = $this->fetch_url($url, self::MAX_CERTIFICATE_SIZE, 'certificate');
		if ($body === false) {
			return false;
		}

		// Cache only a readable certificate
		if ($apcu && @openssl_x509_read($body) !== false) {
			apcu_store($cache_key, $body, self::CERTIFICATE_CACHE_TTL);
		}
		while (openssl_error_string()) {}

		return $body;
	}

	/**
	 * Download a URL with the restrictions for URLs that may come from a caller.
	 *
	 * Only https on port 443 is allowed and the connection may only be to a public address (see is_public_address()).
	 * curl resolves the host within the timeout and the address is checked after it connects but before any request
	 * is sent (CURLOPT_PREREQFUNCTION, PHP 8.4 and later), so DNS cannot point it elsewhere afterwards. Before PHP 8.4
	 * the host is resolved and checked first and the connection pinned to the checked address; that lookup is not
	 * limited by the timeout. Redirects and proxies are never used, the download stops at max_size, and only HTTP 200
	 * is accepted.
	 *
	 * @param string $url      URL to download
	 * @param int    $max_size Largest response accepted, in bytes
	 * @param string $what     What is downloaded, for the error messages, such as certificate or CRL
	 *
	 * @return string|false The downloaded content, or false with error_message set
	 */
	public function fetch_url(string $url, int $max_size, string $what) {
		// Check the URL
		$parts = parse_url($url);
		$scheme = strtolower($parts['scheme'] ?? '');
		$host = strtolower(trim($parts['host'] ?? '', '[]'));
		if ($host === '' || $scheme !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
			$this->error_message = ucfirst($what) . " URL must be an https URL without a user name: " . $url;
			return false;
		}
		$port = (int)($parts['port'] ?? 443);
		if ($port !== 443) {
			$this->error_message = ucfirst($what) . " URL must use the https port 443: " . $url;
			return false;
		}

		if (!function_exists('curl_init')) {
			$this->error_message = "The PHP curl extension is required to download the " . $what;
			return false;
		}

		// Refuse an address in the URL itself without connecting
		if (filter_var($host, FILTER_VALIDATE_IP) && !self::is_public_address($host)) {
			$this->error_message = ucfirst($what) . " URL must use a public address: " . $url;
			return false;
		}

		// Check the address curl connects to before the request is sent, or resolve and pin it first on older PHP
		$blocked = false;
		$options = [];
		if (defined('CURLOPT_PREREQFUNCTION')) {
			$options[CURLOPT_PREREQFUNCTION] = function ($ch, $primary_ip, $local_ip, $primary_port, $local_port) use (&$blocked) {
				if (!self::is_public_address((string)$primary_ip)) {
					$blocked = true;
					return CURL_PREREQFUNC_ABORT;
				}
				return CURL_PREREQFUNC_OK;
			};
		}
		else {
			$addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
			if (!filter_var($host, FILTER_VALIDATE_IP)) {
				foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
					if (!empty($record['ipv6'])) {
						$addresses[] = $record['ipv6'];
					}
				}
			}
			if (empty($addresses)) {
				$this->error_message = ucfirst($what) . " URL host could not be resolved: " . $url;
				return false;
			}
			foreach ($addresses as $address) {
				if (!self::is_public_address($address)) {
					$this->error_message = ucfirst($what) . " URL must use a public address: " . $url;
					return false;
				}
			}
			$options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . (strpos($addresses[0], ':') !== false ? '[' . $addresses[0] . ']' : $addresses[0])];
		}

		// Download, stopping when the response is larger than max_size
		$body = '';
		$too_large = false;
		$ch = curl_init($url);
		curl_setopt_array($ch, $options + [
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => 0,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROXY => '',
			CURLOPT_CONNECTTIMEOUT => $this->certificate_timeout,
			CURLOPT_TIMEOUT => $this->certificate_timeout,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$body, &$too_large, $max_size) {
				if (strlen($body) + strlen($data) > $max_size) {
					$too_large = true;
					return 0;
				}
				$body .= $data;
				return strlen($data);
			},
		]);
		$result = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);
		if ($blocked) {
			$this->error_message = ucfirst($what) . " URL must use a public address: " . $url;
			return false;
		}
		if ($too_large) {
			$this->error_message = ucfirst($what) . " download is larger than " . $max_size . " bytes: " . $url;
			return false;
		}
		if ($result === false || $status !== 200 || $body === '') {
			$this->error_message = "Failed to download " . $what . " from: " . $url . ($curl_error !== '' ? " (" . $curl_error . ")" : ($status ? " (HTTP " . $status . ")" : ""));
			return false;
		}

		return $body;
	}

	/**
	 * Check that an address is public, so a certificate URL from a caller cannot reach internal services.
	 *
	 * An IPv4-mapped IPv6 address is checked as its IPv4 address.
	 *
	 * @param string $ip IPv4 or IPv6 address
	 *
	 * @return bool True if the address is public
	 */
	public static function is_public_address(string $ip): bool {
		$binary = @inet_pton($ip);
		if ($binary === false) {
			return false;
		}
		if (strlen($binary) == 16 && substr($binary, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
			return self::is_public_address(inet_ntop(substr($binary, 12)));
		}
		$flags = defined('FILTER_FLAG_GLOBAL_RANGE') ? FILTER_FLAG_GLOBAL_RANGE : (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
		if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
			return false;
		}
		foreach (self::BLOCKED_RANGES as $range) {
			[$network, $bits] = explode('/', $range);
			$network = inet_pton($network);
			if (strlen($network) !== strlen($binary)) {
				continue;
			}
			$bytes = intdiv((int)$bits, 8);
			$remainder = (int)$bits % 8;
			if (substr($binary, 0, $bytes) !== substr($network, 0, $bytes)) {
				continue;
			}
			if ($remainder == 0) {
				return false;
			}
			$mask = (0xff << (8 - $remainder)) & 0xff;
			if ((ord($binary[$bytes]) & $mask) === (ord($network[$bytes]) & $mask)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Build complete SIP Identity header from PASSporT token.
	 *
	 * Constructs the Identity header value with the PASSporT token and optional
	 * parameters including certificate URL, algorithm, and passport type indicator.
	 * Format: PASSporT;info="certificate_url;alg=ES256;ppt=shaken"
	 *
	 * @param string $passport The signed PASSporT JWT token
	 *
	 * @return string The complete SIP Identity header value
	 */
	public function build_identity_header(string $passport): string {
		return $passport
			. ';info=<'
			. $this->certificate_url
			. '>'
			. ';alg='
			. $this->algorithm
			. ';ppt=shaken';
	}

	/**
	 * Sign call and create complete SIP Identity header.
	 *
	 * Calls sign() to generate the PASSporT token, then calls
	 * build_identity_header() to create the complete SIP Identity header value.
	 * Returns error from signing if it fails.
	 *
	 * @param string $orig_tn      Originator phone number
	 * @param string $dest_tn      Destination phone number
	 * @param string $attest_level Attestation level (A, B, or C); defaults to C
	 *
	 * @return array Array with keys: Identity (string), error (string|null)
	 */
	public function sign_and_build_header(string $orig_tn, string $dest_tn, string $attest_level = ''): array {
		// Sign the call
		$sign_result = $this->sign($orig_tn, $dest_tn, $attest_level);

		if (!empty($sign_result['error'])) {
			return ['Identity' => '', 'error' => $sign_result['error']];
		}

		// Build SIP Identity header
		$identity_header = $this->build_identity_header($sign_result['passport']);

		return ['Identity' => $identity_header, 'error' => null];
	}

	/**
	 * Encode data to base64url format per RFC 7515.
	 *
	 * Applies standard base64 encoding and then replaces URL-unsafe characters
	 * (+/ becomes -_) and removes padding (=).
	 *
	 * @param string $data Raw data to encode
	 *
	 * @return string Base64url-encoded string
	 */
	public function base64_url_encode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	/**
	 * Decode base64url-encoded data per RFC 7515.
	 *
	 * Restores URL-safe characters (-_ back to +/) and adds padding if needed
	 * before applying standard base64 decoding.
	 *
	 * @param string $data Base64url-encoded string
	 *
	 * @return string|false Raw decoded data or false if the input is not valid base64url
	 */
	public function base64_url_decode(string $data): string|false {
		$padding = 4 - (strlen($data) % 4);
		if ($padding !== 4) {
			$data .= str_repeat('=', $padding);
		}
		return base64_decode(strtr($data, '-_', '+/'), true);
	}

	/**
	 * Convert DER-encoded ECDSA signature to raw IEEE P1363 format.
	 *
	 * Parses ASN.1 DER structure to extract R and S components. Automatically
	 * detects P-256 (32 bytes each) or P-384 (48 bytes each) curves and pads
	 * components accordingly.
	 *
	 * @param string $der DER-encoded signature bytes
	 *
	 * @return string|false Raw R||S signature or false if parsing fails
	 */
	private function der_to_raw(string $der): string|false {
		$pos = 0;

		// Check for SEQUENCE tag (0x30)
		if (ord($der[$pos++]) !== 0x30) {
			return false;
		}

		// Read sequence length (handle both short and long form)
		$seq_len = ord($der[$pos++]);
		if ($seq_len > 127) {
			// Long form: next byte indicates number of length bytes
			$num_len_bytes = $seq_len - 128;
			$seq_len = 0;
			for ($i = 0; $i < $num_len_bytes; $i++) {
				$seq_len = ($seq_len << 8) + ord($der[$pos++]);
			}
		}

		// Read R INTEGER
		if (ord($der[$pos++]) !== 0x02) {
			return false;
		}
		$r_len = ord($der[$pos++]);
		$r = substr($der, $pos, $r_len);
		$pos += $r_len;

		// Read S INTEGER
		if (ord($der[$pos++]) !== 0x02) {
			return false;
		}
		$s_len = ord($der[$pos++]);
		$s = substr($der, $pos, $s_len);

		// Strip DER's leading 0x00 sign-bit padding before measuring
		$r = ltrim($r, "\x00");
		$s = ltrim($s, "\x00");

		// Detect curve size based on actual integer length
		// P-256: 32 bytes each, P-384: 48 bytes each
		$max_len = max(strlen($r), strlen($s));
		$curve_size = ($max_len <= 32) ? 32 : 48;

		// Pad R and S to the detected curve size
		$r = str_pad($r, $curve_size, "\x00", STR_PAD_LEFT);
		$s = str_pad($s, $curve_size, "\x00", STR_PAD_LEFT);

		if (strlen($r) !== $curve_size || strlen($s) !== $curve_size) {
			return false;
		}

		return $r . $s;
	}

	/**
	 * Convert IEEE P1363 ECDSA signature to DER/ASN.1 format.
	 *
	 * Accepts 64-byte (P-256) or 96-byte (P-384) raw signatures and encodes to
	 * DER format required by OpenSSL verification. Handles leading zero bytes
	 * for positive integer representation.
	 *
	 * @param string $signature Raw IEEE P1363 signature (R || S)
	 *
	 * @return string DER-encoded signature
	 *
	 * @throws Exception If signature length is not 64 or 96 bytes
	 */
	function p1363_to_der($signature) {
		$sig_len = strlen($signature);

		// Support both P-256 (64 bytes) and P-384 (96 bytes)
		if ($sig_len !== 64 && $sig_len !== 96) {
			throw new Exception("Expected 64-byte (P-256) or 96-byte (P-384) signature, got {$sig_len}");
		}

		// Determine bytes per component (32 for P-256, 48 for P-384)
		$bytes_per_component = $sig_len / 2;
		$r = ltrim(substr($signature, 0, $bytes_per_component), "\x00");
		$s = ltrim(substr($signature, $bytes_per_component, $bytes_per_component), "\x00");

		if ($r === '') $r = "\x00";
		if ($s === '') $s = "\x00";

		// Handle leading 0x00 for positive integers
		if (ord($r[0]) >= 0x80) $r = "\x00" . $r;
		if (ord($s[0]) >= 0x80) $s = "\x00" . $s;

		$r_len = strlen($r);
		$s_len = strlen($s);

		$seq = "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;
		return "\x30" . chr(strlen($seq)) . $seq;
	}

	/**
	 * Generate a random (version 4) UUID for the PASSporT origid.
	 *
	 * Uses random_bytes(), which is available on every platform and never
	 * runs a command or prints anything, since the output of sign.php is
	 * sent as the SIP Identity header.
	 *
	 * @return string Generated UUID as a string
	 */
	private function generate_uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}

	/**
	 * Normalize a telephone number for use in a PASSporT.
	 *
	 * Removes everything except digits. A 10 digit North American number is
	 * given the leading country code 1 because SHAKEN requires the number to
	 * include the country code.
	 *
	 * @param string $tn Telephone number in any format
	 *
	 * @return string Digits only, with the country code when it can be determined
	 */
	public function normalize_tn(string $tn): string {
		$tn = preg_replace('/[^0-9]/', '', $tn);
		if (preg_match('/^[2-9][0-9]{2}[2-9][0-9]{6}$/', $tn)) {
			$tn = '1' . $tn;
		}
		return $tn;
	}

	/**
	 * Inspect the configured private key and certificate.
	 *
	 * Reports whether each file exists and can be read, the private key file
	 * permissions and curve, the certificate subject, issuer and validity dates,
	 * and whether the private key belongs to the certificate.
	 *
	 * @return array Array with keys: private_key (array), certificate (array), key_matches_certificate (bool)
	 */
	public function certificate_status(): array {
		$status = [
			'private_key' => [
				'path' => $this->private_key_path,
				'exists' => false,
				'readable' => false,
				'mode' => '',
				'curve' => '',
			],
			'certificate' => [
				'path' => $this->certificate_path,
				'exists' => false,
				'readable' => false,
				'subject' => '',
				'issuer' => '',
				'self_signed' => false,
				'valid_from' => 0,
				'valid_to' => 0,
				'pem' => '',
			],
			'key_matches_certificate' => false,
		];

		// Private key
		$private_key = false;
		if (!empty($this->private_key_path) && file_exists($this->private_key_path)) {
			$status['private_key']['exists'] = true;
			$status['private_key']['mode'] = substr(sprintf('%o', fileperms($this->private_key_path)), -4);
			$key_content = @file_get_contents($this->private_key_path);
			if ($key_content !== false) {
				$status['private_key']['readable'] = true;
				$private_key = openssl_pkey_get_private($key_content);
				if ($private_key !== false) {
					$details = openssl_pkey_get_details($private_key);
					$status['private_key']['curve'] = $details['ec']['curve_name'] ?? '';
				}
			}
		}

		// Certificate
		$certificate = false;
		if (!empty($this->certificate_path) && file_exists($this->certificate_path)) {
			$status['certificate']['exists'] = true;
			$certificate_pem = @file_get_contents($this->certificate_path);
			if ($certificate_pem !== false) {
				$certificate = @openssl_x509_read($certificate_pem);
				if ($certificate !== false) {
					$status['certificate']['readable'] = true;
					$status['certificate']['pem'] = $certificate_pem;
					$info = openssl_x509_parse($certificate);
					$status['certificate']['subject'] = $info['name'] ?? '';
					$status['certificate']['issuer'] = isset($info['issuer']) ? $this->format_dn($info['issuer']) : '';
					$status['certificate']['self_signed'] = isset($info['subject'], $info['issuer']) && $info['subject'] == $info['issuer'];
					$status['certificate']['valid_from'] = $info['validFrom_time_t'] ?? 0;
					$status['certificate']['valid_to'] = $info['validTo_time_t'] ?? 0;
				}
			}
		}

		// Key pair
		if ($private_key !== false && $certificate !== false) {
			$status['key_matches_certificate'] = openssl_x509_check_private_key($certificate, $private_key);
		}

		// Clear any errors left by openssl so they are not reported by a later call
		while (openssl_error_string()) {}

		return $status;
	}

	/**
	 * Format an OpenSSL distinguished name array as a string.
	 *
	 * @param array $dn Distinguished name from openssl_x509_parse()
	 *
	 * @return string Distinguished name such as /C=US/O=Example/CN=Name
	 */
	private function format_dn(array $dn): string {
		$result = '';
		foreach ($dn as $key => $value) {
			$result .= '/' . $key . '=' . (is_array($value) ? implode(',', $value) : $value);
		}
		return $result;
	}

	/**
	 * Generate a self-signed certificate and private key for testing
	 *
	 * WARNING: This should only be used for development/testing.
	 * In production, use certificates signed by a trusted STIR/SHAKEN CA.
	 *
	 * Note: This method uses openssl command-line tool for key generation
	 * to support P-256 (ES256) properly across all OpenSSL versions.
	 *
	 * @param string $output_dir  Directory to save key and certificate
	 * @param string $common_name Common name for the certificate
	 * @return array ['private_key_path' => string, 'certificate_path' => string, 'error' => string|null]
	 */
	public function generate_test_certificates(string $output_dir, string $common_name = 'STIR Test'): array {
		// Create output directory if it doesn't exist
		if (!is_dir($output_dir)) {
			if (!mkdir($output_dir, 0750, true)) {
				return ['private_key_path' => '', 'certificate_path' => '',
						'error' => 'Failed to create output directory'];
			}
		}

		// Define the certificate path
		$private_key_name = basename($this->private_key_path) ?: 'identity_private.key';
		$certificate_name = basename($this->certificate_path) ?: 'identity_certificate.pem';
		$private_key_path = $output_dir . '/' . $private_key_name;
		$certificate_path = $output_dir . '/' . $certificate_name;

		// Never replace existing keys, they may be the official production keys
		if (file_exists($private_key_path) || file_exists($certificate_path)) {
			return ['private_key_path' => '', 'certificate_path' => '',
					'error' => 'A private key or certificate already exists in ' . $output_dir];
		}

		// Use OpenSSL command-line to generate EC P-256 key (more compatible)
		$cmd = sprintf(
			'umask 077 && openssl ecparam -name prime256v1 -genkey -noout -out %s 2>/dev/null',
			escapeshellarg($private_key_path)
		);
		$exec_result = exec($cmd, $output, $return_code);

		if ($return_code !== 0 || !file_exists($private_key_path)) {
			// Fallback: use PHP's openssl_pkey_new with the P-256 curve required by ES256
			$config_array = array(
				"private_key_type" => OPENSSL_KEYTYPE_EC,
				"curve_name" => "prime256v1",
				"digest_alg" => "sha256"
			);

			$private_key = openssl_pkey_new($config_array);
			if (!$private_key) {
				return ['private_key_path' => '', 'certificate_path' => '',
						'error' => 'Failed to generate private key (both command-line and PHP methods failed): ' . openssl_error_string()];
			}

			// Export private key, created with mode 0600 so it is never readable by other users
			$private_key_pem = '';
			openssl_pkey_export($private_key, $private_key_pem);
			$umask = umask(0077);
			$written = file_put_contents($private_key_path, $private_key_pem);
			umask($umask);
			if (!$written) {
				return ['private_key_path' => '', 'certificate_path' => '',
						'error' => 'Failed to write private key file'];
			}
		}

		// Only the web server user may read the private key
		if (!chmod($private_key_path, 0600)) {
			unlink($private_key_path);
			return ['private_key_path' => '', 'certificate_path' => '',
					'error' => 'Failed to set the private key file mode'];
		}

		// Load the private key for certificate generation
		$private_key_pem = file_get_contents($private_key_path);
		if (!$private_key_pem) {
			@unlink($private_key_path);
			return ['private_key_path' => '', 'certificate_path' => '',
					'error' => 'Failed to read generated private key'];
		}

		$private_key = openssl_pkey_get_private($private_key_pem);
		if (!$private_key) {
			@unlink($private_key_path);
			return ['private_key_path' => '', 'certificate_path' => '',
					'error' => 'Failed to load private key: ' . openssl_error_string()];
		}

		// Generate certificate signing request
		$csr = openssl_csr_new([
			'commonName' => $common_name,
			'organizationName' => 'Test Organization',
			'countryName' => 'US',
			'stateOrProvinceName' => 'Test State'
		], $private_key, ['digest_alg' => 'sha256']);

		if (!$csr) {
			$error = openssl_error_string();
			@unlink($private_key_path);
			return ['private_key_path' => '', 'certificate_path' => '',
					'error' => 'Failed to generate CSR: ' . $error];
		}

		// Create self-signed certificate (valid for 365 days)
		$certificate = openssl_csr_sign($csr, null, $private_key, 365, array('digest_alg' => 'sha256'));
		if (!$certificate) {
			$error = openssl_error_string();
			@unlink($private_key_path);
			return ['private_key_path' => '', 'certificate_path' => '',
					'error' => 'Failed to sign certificate: ' . $error];
		}

		// Export certificate
		$certificate_pem = openssl_x509_export($certificate, $cert_str);
		if (!file_put_contents($certificate_path, $cert_str)) {
			// Remove both so Generate can be tried again
			@unlink($certificate_path);
			@unlink($private_key_path);
			return ['private_key_path' => '', 'certificate_path' => '',
					'error' => 'Failed to write certificate file'];
		}

		return [
			'private_key_path' => $private_key_path,
			'certificate_path' => $certificate_path,
			'error' => null
		];
	}
}
