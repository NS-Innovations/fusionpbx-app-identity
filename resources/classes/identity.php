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
	 * Verify a PASSporT token signature and extract attestation level.
	 *
	 * Validates the JWT format, downloads the public certificate from the header
	 * URL, extracts the public key, and verifies the ECDSA signature. Sets
	 * error_message and attest_level properties on success or failure.
	 *
	 * @param string $identity_header The signed PASSporT token (JWT format)
	 * @param string $certificate_pem Optional certificate to verify against instead of downloading x5u
	 *
	 * @return bool True if signature is valid, false otherwise
	 */
	public function verify($identity_header, string $certificate_pem = ''): bool {

		// Reset the results from any previous call
		$this->attest_level = '';
		$this->passport = ['header' => [], 'payload' => []];
		$this->rcd = [];
		$this->rcdi = [];
		$this->crn = '';

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
		if (json_last_error() !== JSON_ERROR_NONE) {
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

		// Get the certificate from the header
		$header_certificate_url = $header_json['x5u'];
		$this->error_message = "Certificate URL: " . $header_certificate_url;

		// Use the provided certificate or download it from the header URL
		if (!empty($certificate_pem)) {
			$header_certificate_string = $certificate_pem;
		} else {
			// Only allow http and https, the URL comes from the caller and must not reach local files or other wrappers
			$scheme = strtolower(parse_url($header_certificate_url, PHP_URL_SCHEME) ?? '');
			if ($scheme !== 'https' && $scheme !== 'http') {
				$this->error_message = "Certificate URL must be http or https: " . $header_certificate_url;
				return false;
			}

			// Download certificate with a timeout so a slow server does not hold up the call
			$context = stream_context_create([
				'http' => ['timeout' => $this->certificate_timeout, 'follow_location' => 1, 'max_redirects' => 3]
			]);
			$header_certificate_string = @file_get_contents($header_certificate_url, false, $context);
			if ($header_certificate_string === false) {
				$this->error_message = "Failed to download certificate from: " . $header_certificate_url;
				return false;
			}
		}

		// Decode payload
		$payload_json = json_decode($payload, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->error_message = "Decode payload failed: " . json_last_error_msg();
			return false;
		}

		// Attestation missing
		if (empty($payload_json['attest'])) {
			$this->error_message = "Attestation missing";
			return false;
		}

		// Verify signature - The JWT signature is computed over ASCII(base64url(header) || '.' || base64url(payload))
		$this->error_message = "Verifying signature...";
		$secret = $header_certificate_string;
		$signing_input = $header_b64 . '.' . $payload_b64;

		// Read certificate from secret (which is the certificate content)
		$certificate = openssl_x509_read($secret);
		if (!$certificate) {
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
			$this->error_message = "";
			return true;
		} elseif ($result === 0) {
			$this->error_message = "Signature is invalid.";
			return false;
		} else {
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
			$error = openssl_error_string();
			openssl_pkey_free($private_key);
			return ['passport' => '', 'error' => 'Signing failed: ' . $error];
		}

		// Convert DER signature to raw R||S format (IEEE P1363)
		$raw_signature = $this->der_to_raw($signature);
		if ($raw_signature === false) {
			openssl_pkey_free($private_key);
			return ['passport' => '', 'error' => 'Failed to convert signature format'];
		}

		// Clean up private key
		openssl_pkey_free($private_key);

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
	 * Generate unique identifier using platform-specific methods.
	 *
	 * Attempts to generate a UUID using OS-specific methods: uuidgen on FreeBSD
	 * and Linux (/proc/sys/kernel/random/uuid then uuidgen), com_create_guid on
	 * Windows. Exits with error message if generation fails.
	 *
	 * @return string Generated UUID as a string
	 */
	private function generate_uuid(): string {
		$uuid = null;
		if (PHP_OS === 'FreeBSD') {
			$uuid = trim(shell_exec("uuidgen"));
			if (is_uuid($uuid)) {
				return $uuid;
			} else {
				echo "Please install uuidgen.\n";
				exit;
			}
		}
		if (PHP_OS === 'Linux') {
			$uuid = trim(file_get_contents('/proc/sys/kernel/random/uuid'));
			if (is_uuid($uuid)) {
				return $uuid;
			} else {
				$uuid = trim(shell_exec("uuidgen"));
				if (is_uuid($uuid)) {
					return $uuid;
				} else {
					echo "Please install uuidgen.\n";
					exit;
				}
			}
		}
		if ((strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') && function_exists('com_create_guid')) {
			$uuid = trim(com_create_guid(), '{}');
			if (is_uuid($uuid)) {
				return $uuid;
			} else {
				echo "The com_create_guid() function failed to create a uuid.\n";
				exit;
			}
		}
		return '';
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
			if (!mkdir($output_dir, 0755, true)) {
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

			// Export private key
			$private_key_pem = '';
			openssl_pkey_export($private_key, $private_key_pem);
			if (!file_put_contents($private_key_path, $private_key_pem)) {
				return ['private_key_path' => '', 'certificate_path' => '',
						'error' => 'Failed to write private key file'];
			}
		}

		// Only the web server user may read the private key
		chmod($private_key_path, 0600);

		// Load the private key for certificate generation
		$private_key_pem = file_get_contents($private_key_path);
		if (!$private_key_pem) {
			return ['private_key_path' => '', 'certificate_path' => '',
					'error' => 'Failed to read generated private key'];
		}

		$private_key = openssl_pkey_get_private($private_key_pem);
		if (!$private_key) {
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
			openssl_pkey_free($private_key);
			return ['private_key_path' => $private_key_path, 'certificate_path' => '',
					'error' => 'Failed to generate CSR: ' . openssl_error_string()];
		}

		// Create self-signed certificate (valid for 365 days)
		$certificate = openssl_csr_sign($csr, null, $private_key, 365, array('digest_alg' => 'sha256'));
		if (!$certificate) {
			openssl_pkey_free($private_key);
			return ['private_key_path' => $private_key_path, 'certificate_path' => '',
					'error' => 'Failed to sign certificate: ' . openssl_error_string()];
		}

		openssl_pkey_free($private_key);

		// Export certificate
		$certificate_pem = openssl_x509_export($certificate, $cert_str);
		if (!file_put_contents($certificate_path, $cert_str)) {
			return ['private_key_path' => $private_key_path, 'certificate_path' => '',
					'error' => 'Failed to write certificate file'];
		}

		return [
			'private_key_path' => $private_key_path,
			'certificate_path' => $certificate_path,
			'error' => null
		];
	}
}
