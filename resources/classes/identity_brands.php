<?php

/**
 * Branded calling registry for the identity app.
 *
 * Maps calling telephone numbers to vetted brands (display name, logo and
 * allowed call reasons) used to add Rich Call Data (RFC 9795) to signed
 * PASSporTs. The registry is a JSON file so it can be written by the brand.php
 * command line tool today and by a BCID platform sync job later.
 *
 * File format:
 * {
 *   "brands": {
 *     "acme_dental": {
 *       "nam": "Acme Dental",
 *       "icn": "https://cdn.example.com/acme.bmp",
 *       "icn_digest": "sha256-...",
 *       "call_reasons": ["Appointment reminder", "Billing question"],
 *       "default_call_reason": "",
 *       "profile": "bcid",
 *       "enabled": true,
 *       "expires": "2027-10-31",
 *       "vetting_reference": "vetting agent record id"
 *     }
 *   },
 *   "numbers": {
 *     "12025550100": "acme_dental"
 *   }
 * }
 */
class identity_brands {

	/**
	 * Largest brands file that will be loaded. sign.php parses the whole file on every call, which takes about
	 * 4 ms and 5 MB of memory per MB of file, so 8 MB (about 330,000 numbers) stays well inside a 128 MB
	 * memory_limit.
	 */
	const MAX_FILE_SIZE = 8 * 1024 * 1024;

	/**
	 * Path to the registry file.
	 *
	 * @var string
	 */
	private $file_path = '';

	/**
	 * Registry contents.
	 *
	 * @var array
	 */
	private $data = ['brands' => [], 'numbers' => []];

	/**
	 * Error message from the last load or save.
	 *
	 * @var string
	 */
	public $error_message = '';

	/**
	 * @param string $file_path Path to the registry JSON file
	 */
	public function __construct(string $file_path) {
		$this->file_path = $file_path;
	}

	/**
	 * Load the registry from disk. A missing file is treated as empty.
	 *
	 * @return bool True on success
	 */
	public function load(): bool {
		$this->data = ['brands' => [], 'numbers' => []];
		if (!file_exists($this->file_path)) {
			return true;
		}
		$size = @filesize($this->file_path);
		if ($size !== false && $size > self::MAX_FILE_SIZE) {
			$this->error_message = $this->file_path . ' is larger than ' . self::MAX_FILE_SIZE . ' bytes';
			return false;
		}
		$json = file_get_contents($this->file_path, false, null, 0, self::MAX_FILE_SIZE + 1);
		if ($json === false) {
			$this->error_message = 'Unable to read ' . $this->file_path;
			return false;
		}
		$data = json_decode($json, true);
		if (!is_array($data)) {
			$this->error_message = 'Invalid JSON in ' . $this->file_path;
			return false;
		}
		$this->data['brands'] = is_array($data['brands'] ?? null) ? $data['brands'] : [];
		$this->data['numbers'] = is_array($data['numbers'] ?? null) ? $data['numbers'] : [];
		return true;
	}

	/**
	 * Save the registry atomically (write temp file, then rename).
	 *
	 * @return bool True on success
	 */
	public function save(): bool {
		ksort($this->data['brands']);
		ksort($this->data['numbers']);
		$json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$temp_path = $this->file_path . '.tmp' . getmypid();
		if (file_put_contents($temp_path, $json . "\n", LOCK_EX) === false) {
			$this->error_message = 'Unable to write ' . $temp_path;
			return false;
		}
		// Brand data is not secret; keep it readable by the web server user and
		// preserve the owner of an existing file when running as root
		chmod($temp_path, 0644);
		if (file_exists($this->file_path)) {
			$stat = stat($this->file_path);
			if ($stat !== false) {
				@chown($temp_path, $stat['uid']);
				@chgrp($temp_path, $stat['gid']);
			}
		}
		if (!rename($temp_path, $this->file_path)) {
			@unlink($temp_path);
			$this->error_message = 'Unable to replace ' . $this->file_path;
			return false;
		}
		return true;
	}

	/**
	 * Normalize a telephone number to digits with a leading country code.
	 *
	 * @param string $tn Telephone number
	 *
	 * Uses identity::normalize_tn() so numbers are stored and looked up the
	 * same way they are signed in orig.tn.
	 *
	 * @return string Digits only; 10 digit NANP numbers get a leading 1
	 */
	public static function normalize_tn(string $tn): string {
		return (new identity())->normalize_tn($tn);
	}

	/**
	 * Find the active brand for a calling number.
	 *
	 * Returns null when the number is not assigned, or the brand is disabled,
	 * expired or incomplete.
	 *
	 * @param string $tn Calling telephone number
	 *
	 * @return array|null Brand array with an added "brand_id" key
	 */
	public function lookup(string $tn): ?array {
		$tn = self::normalize_tn($tn);
		$brand_id = $this->data['numbers'][$tn] ?? null;
		if ((!is_string($brand_id) && !is_int($brand_id)) || !isset($this->data['brands'][$brand_id])) {
			return null;
		}
		$brand = $this->data['brands'][$brand_id];
		if (!is_array($brand) || empty($brand['enabled']) || empty($brand['nam']) || !is_string($brand['nam'])) {
			return null;
		}
		foreach (['icn', 'icn_digest', 'default_call_reason', 'profile', 'expires'] as $key) {
			if (isset($brand[$key]) && !is_string($brand[$key])) {
				return null;
			}
		}
		if (!empty($brand['expires']) && strtotime($brand['expires'] . ' 23:59:59') < time()) {
			return null;
		}
		$brand['brand_id'] = (string)$brand_id;
		return $brand;
	}

	/**
	 * Choose the call reason for a call.
	 *
	 * Only vetted call reasons are allowed. A requested reason that is not in
	 * the brand's list is ignored and the default is used instead.
	 *
	 * @param array  $brand     Brand from lookup()
	 * @param string $requested Requested call reason (may be empty)
	 *
	 * @return string Call reason, or an empty string for none
	 */
	public static function resolve_call_reason(array $brand, string $requested = ''): string {
		$allowed = is_array($brand['call_reasons'] ?? null) ? array_filter($brand['call_reasons'], 'is_string') : [];
		$requested = trim($requested);
		if ($requested !== '' && in_array($requested, $allowed, true)) {
			return $requested;
		}
		$default = is_string($brand['default_call_reason'] ?? null) ? trim($brand['default_call_reason']) : '';
		if ($default !== '' && in_array($default, $allowed, true)) {
			return $default;
		}
		return '';
	}

	/**
	 * Build the rich_call_data array expected by identity::sign().
	 *
	 * @param array  $brand       Brand from lookup()
	 * @param string $call_reason Call reason from resolve_call_reason()
	 *
	 * @return array Keys: nam, icn, icn_digest, crn
	 */
	public static function to_rich_call_data(array $brand, string $call_reason = ''): array {
		$rich_call_data = ['nam' => $brand['nam']];
		if (!empty($brand['icn'])) {
			$rich_call_data['icn'] = $brand['icn'];
			$rich_call_data['icn_digest'] = $brand['icn_digest'] ?? '';
		}
		if ($call_reason !== '') {
			$rich_call_data['crn'] = $call_reason;
		}
		return $rich_call_data;
	}

	/**
	 * Get a brand by id.
	 *
	 * @param string $brand_id Brand id
	 *
	 * @return array|null Brand or null
	 */
	public function get_brand(string $brand_id): ?array {
		return $this->data['brands'][$brand_id] ?? null;
	}

	/**
	 * Create or replace a brand.
	 *
	 * @param string $brand_id Brand id (letters, digits, underscore, dash)
	 * @param array  $brand    Brand fields
	 *
	 * @return void
	 */
	public function set_brand(string $brand_id, array $brand) {
		if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $brand_id)) {
			throw new InvalidArgumentException('Brand id may only contain letters, digits, underscore and dash');
		}
		$this->data['brands'][$brand_id] = $brand;
	}

	/**
	 * Remove a brand and any numbers assigned to it.
	 *
	 * @param string $brand_id Brand id
	 *
	 * @return void
	 */
	public function remove_brand(string $brand_id) {
		unset($this->data['brands'][$brand_id]);
		foreach ($this->data['numbers'] as $tn => $assigned) {
			if ($assigned === $brand_id) {
				unset($this->data['numbers'][$tn]);
			}
		}
	}

	/**
	 * Assign a telephone number to a brand.
	 *
	 * @param string $tn       Telephone number
	 * @param string $brand_id Brand id
	 *
	 * @return void
	 */
	public function assign_number(string $tn, string $brand_id) {
		if (!isset($this->data['brands'][$brand_id])) {
			throw new InvalidArgumentException("Unknown brand: {$brand_id}");
		}
		$tn = self::normalize_tn($tn);
		if (strlen($tn) < 8 || strlen($tn) > 15) {
			throw new InvalidArgumentException('Telephone number must be E.164 digits');
		}
		$this->data['numbers'][$tn] = $brand_id;
	}

	/**
	 * Remove a telephone number assignment.
	 *
	 * @param string $tn Telephone number
	 *
	 * @return void
	 */
	public function unassign_number(string $tn) {
		unset($this->data['numbers'][self::normalize_tn($tn)]);
	}

	/**
	 * Get all brands.
	 *
	 * @return array Brands keyed by id
	 */
	public function get_brands(): array {
		return $this->data['brands'];
	}

	/**
	 * Get all number assignments.
	 *
	 * @return array Brand ids keyed by telephone number
	 */
	public function get_numbers(): array {
		return $this->data['numbers'];
	}
}
