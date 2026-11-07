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

/**
 * Certificate revocation list (CRL) used to check STIR/SHAKEN certificates when inbound calls are verified.
 *
 * The STI-PA publishes one CRL for every STI-CA (ATIS-1000080). It is an indirect CRL: it is signed by an STI-PA CRL
 * certificate rather than by the issuers of the certificates it lists, and each entry names the issuer of the revoked
 * certificate in a Certificate Issuer entry extension (RFC 5280 section 5.3.3). PHP has no CRL functions, so the CRL
 * is read here with a small DER reader.
 *
 * A downloaded CRL is used only when its signer certificate, downloaded from the CRL's Authority Information Access
 * extension, has the CRL subject as its subject, may sign CRLs, and chains to the trust list, when the signature is
 * valid, when it has no critical extension that is not understood and is not a delta CRL, and while its next update
 * time has not passed. It is kept in the FusionPBX cache directory and downloaded again at most once an hour, and no
 * more than once a minute after a failed download.
 */
class identity_crl {

	/**
	 * declare constant variables
	 */
	const app_name = 'identity';
	const app_uuid = '826aa9a8-1b27-4aad-8b73-368f734ef6a2';

	/**
	 * Largest CRL that is downloaded. The STI-PA CRL is a few KB.
	 */
	const MAX_CRL_SIZE = 4194304;

	/**
	 * Seconds a downloaded CRL is used before it is downloaded again.
	 */
	const REFRESH_INTERVAL = 3600;

	/**
	 * Seconds to wait after a failed download before trying again.
	 */
	const RETRY_INTERVAL = 60;

	/**
	 * Seconds a CRL this update time may be ahead of the local clock.
	 */
	const CLOCK_SKEW = 300;

	/**
	 * CRL extensions that are understood, so they may be critical: CRL number, authority key identifier, issuing
	 * distribution point, authority information access. A delta CRL indicator (2.5.29.27) is refused.
	 */
	const KNOWN_CRL_EXTENSIONS = ['2.5.29.20', '2.5.29.35', '2.5.29.28', '1.3.6.1.5.5.7.1.1'];

	/**
	 * CRL entry extensions that are understood: reason code, hold instruction, invalidity date, certificate issuer.
	 */
	const KNOWN_ENTRY_EXTENSIONS = ['2.5.29.21', '2.5.29.23', '2.5.29.24', '2.5.29.29'];

	/**
	 * Signature algorithms of the CRL that are accepted, by OID.
	 */
	const SIGNATURE_ALGORITHMS = [
		'1.2.840.10045.4.3.2' => OPENSSL_ALGO_SHA256,
		'1.2.840.10045.4.3.3' => OPENSSL_ALGO_SHA384,
		'1.2.840.10045.4.3.4' => OPENSSL_ALGO_SHA512,
		'1.2.840.113549.1.1.11' => OPENSSL_ALGO_SHA256,
		'1.2.840.113549.1.1.12' => OPENSSL_ALGO_SHA384,
		'1.2.840.113549.1.1.13' => OPENSSL_ALGO_SHA512,
	];

	/**
	 * Error message from the last load().
	 *
	 * @var string
	 */
	public $error_message = '';

	/**
	 * This update time of the loaded CRL, as a Unix timestamp.
	 *
	 * @var int
	 */
	public $this_update = 0;

	/**
	 * Next update time of the loaded CRL, as a Unix timestamp.
	 *
	 * @var int
	 */
	public $next_update = 0;

	/**
	 * Number of revoked certificates in the loaded CRL.
	 *
	 * @var int
	 */
	public $count = 0;

	/**
	 * Identity object used to download and to check the signer certificate against the trust list.
	 *
	 * @var identity
	 */
	private $identity;

	/**
	 * URL of the CRL.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Directory the CRL is kept in.
	 *
	 * @var string
	 */
	private $cache_path;

	/**
	 * Revoked certificates of the loaded CRL, as keys of normalized issuer name and serial number.
	 *
	 * @var array
	 */
	private $revoked = [];

	/**
	 * @param identity $identity   Identity object with the trust list
	 * @param string   $url        URL of the CRL
	 * @param string   $cache_path Directory to keep the CRL in, the FusionPBX cache location
	 */
	public function __construct(identity $identity, string $url, string $cache_path) {
		$this->identity = $identity;
		$this->url = $url;
		$this->cache_path = rtrim($cache_path, '/');
	}

	/**
	 * Load a current CRL, from the cache or by downloading it.
	 *
	 * @return bool True if a valid CRL whose next update time has not passed is loaded, otherwise false with
	 *              error_message set
	 */
	public function load(): bool {
		$this->error_message = '';
		$user = function_exists('posix_geteuid') ? (string)posix_geteuid() : preg_replace('/[^a-z0-9_-]/i', '', get_current_user());
		$base = $this->cache_path . '/identity_crl_' . $user . '_' . substr(hash('sha256', $this->url), 0, 16);
		$crl_file = $base . '.crl';
		$attempt_file = $base . '.attempt';

		// The cached CRL was checked when it was downloaded
		$cached = null;
		$cached_age = PHP_INT_MAX;
		if (is_file($crl_file)) {
			$cached = self::parse((string)@file_get_contents($crl_file));
			$modified = @filemtime($crl_file);
			$cached_age = $modified === false ? PHP_INT_MAX : time() - $modified;
			if ($cached !== null && $cached_age >= 0 && $cached_age < self::REFRESH_INTERVAL && $this->current($cached)) {
				return $this->keep($cached);
			}
		}

		// Download it again, but not more than once a minute after a failed download. The time of the last failure is
		// kept in a file, removed when a download succeeds.
		$failed = @filemtime($attempt_file);
		$error = '';
		if ($failed === false || time() - $failed < 0 || time() - $failed >= self::RETRY_INTERVAL) {
			$data = $this->identity->fetch_url($this->url, self::MAX_CRL_SIZE, 'CRL');
			if ($data === false) {
				$error = $this->identity->error_message;
			}
			else {
				$crl = self::parse($data);
				if ($crl === null) {
					$error = "The CRL at " . $this->url . " cannot be read or uses an extension that is not supported";
				}
				else if ($this->validate($crl)) {
					@unlink($attempt_file);
					$this->save($crl_file, $data);
					return $this->keep($crl);
				}
				else {
					$error = $this->error_message;
				}
			}
			@touch($attempt_file);
		}
		else {
			$error = "The last download of the CRL failed less than " . self::RETRY_INTERVAL . " seconds ago";
		}

		// Keep using the cached CRL until its next update time
		if ($cached !== null && $this->current($cached)) {
			error_log('identity: using the cached CRL, it could not be downloaded again: ' . $error);
			return $this->keep($cached);
		}
		$this->error_message = $error !== '' ? $error : "No current CRL";
		return false;
	}

	/**
	 * Check whether a certificate is in the loaded CRL.
	 *
	 * @param \OpenSSLCertificate $certificate Certificate
	 *
	 * @return bool True if the certificate is revoked
	 */
	public function is_revoked($certificate): bool {
		if (!openssl_x509_export($certificate, $pem)) {
			return false;
		}
		$fields = self::certificate_fields(self::pem_to_der($pem, 'CERTIFICATE'));
		if ($fields === null) {
			return false;
		}
		return isset($this->revoked[$fields['issuer'] . '|' . $fields['serial']]);
	}

	/**
	 * Check that a CRL may be used now: its this update time is not in the future and its next update time has not
	 * passed.
	 *
	 * @param array $crl Parsed CRL
	 *
	 * @return bool True if the CRL is current
	 */
	private function current(array $crl): bool {
		$now = time();
		if ($crl['this_update'] > $now + self::CLOCK_SKEW) {
			$this->error_message = "The CRL this update time is in the future";
			return false;
		}
		if ($crl['next_update'] <= $now) {
			$this->error_message = "The CRL next update time " . gmdate('Y-m-d H:i:s', $crl['next_update']) . " UTC has passed";
			return false;
		}
		return true;
	}

	/**
	 * Use a parsed CRL for is_revoked().
	 *
	 * @param array $crl Parsed CRL
	 *
	 * @return bool Always true
	 */
	private function keep(array $crl): bool {
		$this->revoked = $crl['revoked'];
		$this->this_update = $crl['this_update'];
		$this->next_update = $crl['next_update'];
		$this->count = count($crl['revoked']);
		$this->error_message = '';
		return true;
	}

	/**
	 * Check a downloaded CRL: its signer certificate, its signature and its dates.
	 *
	 * The signer certificate is downloaded from the CA Issuers URL of the CRL's Authority Information Access extension
	 * with the same restrictions as other downloads. It must have the CRL issuer as its subject, the cRLSign key usage,
	 * and chain to the trust list.
	 *
	 * @param array $crl Parsed CRL
	 *
	 * @return bool True if the CRL can be used, otherwise false with error_message set
	 */
	private function validate(array $crl): bool {
		if (!$this->current($crl)) {
			return false;
		}
		if (empty($crl['ca_issuers'])) {
			$this->error_message = "The CRL does not give the URL of its signer certificate (Authority Information Access)";
			return false;
		}

		// Try each signer certificate URL until one is valid
		$error = '';
		foreach ($crl['ca_issuers'] as $url) {
			$data = $this->identity->fetch_url($url, identity::MAX_CERTIFICATE_SIZE, 'CRL signer certificate');
			if ($data === false) {
				$error = $this->identity->error_message;
				continue;
			}
			$pem = strpos($data, '-----BEGIN CERTIFICATE-----') !== false ? $data : "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($data), 64, "\n") . "-----END CERTIFICATE-----\n";
			$signer = @openssl_x509_read($pem);
			while (openssl_error_string()) {}
			if ($signer === false) {
				$error = "The CRL signer certificate at " . $url . " cannot be read";
				continue;
			}
			openssl_x509_export($signer, $signer_pem);
			$fields = self::certificate_fields(self::pem_to_der($signer_pem, 'CERTIFICATE'));
			if ($fields === null || $fields['subject'] !== $crl['issuer']) {
				$error = "The certificate at " . $url . " is not the CRL issuer";
				continue;
			}
			$key_usage = openssl_x509_parse($signer)['extensions']['keyUsage'] ?? '';
			if (stripos($key_usage, 'CRL Sign') === false) {
				$error = "The CRL signer certificate at " . $url . " may not sign CRLs";
				continue;
			}
			if (!$this->identity->chains_to_trust_list($signer)) {
				$error = "The CRL signer certificate does not chain to the trust list, add the root certificate of the STI-PA to it: " . $this->identity->error_message;
				continue;
			}
			$public_key = openssl_pkey_get_public($signer);
			$verified = $public_key !== false ? openssl_verify($crl['tbs'], $crl['signature'], $public_key, self::SIGNATURE_ALGORITHMS[$crl['algorithm']]) : -1;
			while (openssl_error_string()) {}
			if ($verified !== 1) {
				$error = "The CRL signature is invalid";
				continue;
			}
			return true;
		}
		$this->error_message = $error;
		return false;
	}

	/**
	 * Write the CRL to the cache, replacing the file in one step so other calls never read a partial file.
	 *
	 * @param string $crl_file Cache file
	 * @param string $data     CRL as downloaded
	 *
	 * @return void
	 */
	private function save(string $crl_file, string $data) {
		$temp = @tempnam($this->cache_path, '.identity_crl');
		if ($temp === false || dirname($temp) !== realpath($this->cache_path) || @file_put_contents($temp, $data) !== strlen($data) || !@rename($temp, $crl_file)) {
			if ($temp !== false) {
				@unlink($temp);
			}
			error_log('identity: unable to write the CRL to ' . $crl_file . ', it is downloaded again on the next call');
		}
	}

	/**
	 * Read a CRL in PEM or DER format.
	 *
	 * @param string $data CRL
	 *
	 * @return array|null Array with keys: tbs (signed DER), algorithm (OID), signature, issuer (normalized), this_update,
	 *                    next_update, revoked (normalized issuer|serial => true), ca_issuers (URLs); null if the CRL
	 *                    cannot be read, has no next update time, is a delta CRL, or has a critical extension that is
	 *                    not understood
	 */
	public static function parse(string $data): ?array {
		$der = strpos($data, '-----BEGIN X509 CRL-----') !== false ? self::pem_to_der($data, 'X509 CRL') : $data;
		$certificate_list = self::element($der, 0);
		if ($der === '' || $certificate_list === null || $certificate_list['tag'] !== 0x30) {
			return null;
		}
		$parts = self::children($der, $certificate_list);
		if ($parts === null || count($parts) !== 3 || $parts[0]['tag'] !== 0x30 || $parts[1]['tag'] !== 0x30 || $parts[2]['tag'] !== 0x03) {
			return null;
		}

		// Signature algorithm and value
		$algorithm = self::algorithm($der, $parts[1]);
		$signature = substr($der, $parts[2]['content'], $parts[2]['length']);
		if ($algorithm === null || !isset(self::SIGNATURE_ALGORITHMS[$algorithm]) || $signature === '' || $signature[0] !== "\0") {
			return null;
		}
		$crl = [
			'tbs' => substr($der, $parts[0]['start'], $parts[0]['end'] - $parts[0]['start']),
			'algorithm' => $algorithm,
			'signature' => substr($signature, 1),
			'revoked' => [],
			'ca_issuers' => [],
		];

		// TBSCertList: version, signature, issuer, thisUpdate, nextUpdate, revokedCertificates, crlExtensions
		$fields = self::children($der, $parts[0]);
		if ($fields === null) {
			return null;
		}
		$i = 0;
		if (($fields[$i]['tag'] ?? null) === 0x02) {
			$i++;
		}
		if (($fields[$i]['tag'] ?? null) !== 0x30 || self::algorithm($der, $fields[$i]) !== $algorithm) {
			return null;
		}
		$i++;
		$crl['issuer'] = isset($fields[$i]) ? self::name($der, $fields[$i]) : null;
		$i++;
		$crl['this_update'] = isset($fields[$i]) ? self::time($der, $fields[$i]) : null;
		$i++;
		$crl['next_update'] = isset($fields[$i]) ? self::time($der, $fields[$i]) : null;
		$i++;
		if ($crl['issuer'] === null || $crl['this_update'] === null || $crl['next_update'] === null) {
			return null;
		}

		// Revoked certificates, each entry belongs to the CRL issuer or to the last certificate issuer given
		if (($fields[$i]['tag'] ?? null) === 0x30) {
			$entries = self::children($der, $fields[$i]);
			if ($entries === null) {
				return null;
			}
			$issuer = $crl['issuer'];
			foreach ($entries as $entry) {
				$entry_fields = $entry['tag'] === 0x30 ? self::children($der, $entry) : null;
				if ($entry_fields === null || count($entry_fields) < 2 || $entry_fields[0]['tag'] !== 0x02) {
					return null;
				}
				if (isset($entry_fields[2])) {
					$extensions = self::extensions($der, $entry_fields[2]);
					if ($extensions === null) {
						return null;
					}
					foreach ($extensions as $extension) {
						if ($extension['critical'] && !in_array($extension['oid'], self::KNOWN_ENTRY_EXTENSIONS, true)) {
							return null;
						}
						if ($extension['oid'] === '2.5.29.29') {
							$issuer = self::general_names_directory($extension['value']);
							if ($issuer === null) {
								return null;
							}
						}
					}
				}
				$serial = self::serial(substr($der, $entry_fields[0]['content'], $entry_fields[0]['length']));
				$crl['revoked'][$issuer . '|' . $serial] = true;
			}
			$i++;
		}

		// CRL extensions
		if (($fields[$i]['tag'] ?? null) === 0xA0) {
			$wrapped = self::children($der, $fields[$i]);
			$extensions = ($wrapped !== null && count($wrapped) === 1) ? self::extensions($der, $wrapped[0]) : null;
			if ($extensions === null) {
				return null;
			}
			foreach ($extensions as $extension) {
				if ($extension['oid'] === '2.5.29.27') {
					return null;
				}
				if ($extension['critical'] && !in_array($extension['oid'], self::KNOWN_CRL_EXTENSIONS, true)) {
					return null;
				}
				if ($extension['oid'] === '1.3.6.1.5.5.7.1.1') {
					$crl['ca_issuers'] = self::ca_issuers($extension['value']);
				}
			}
			$i++;
		}
		if (isset($fields[$i])) {
			return null;
		}
		return $crl;
	}

	/**
	 * Get the normalized issuer, subject and serial number of a certificate.
	 *
	 * @param string $der Certificate in DER format
	 *
	 * @return array|null Array with keys: issuer, subject, serial; null if the certificate cannot be read
	 */
	private static function certificate_fields(string $der): ?array {
		$certificate = self::element($der, 0);
		$parts = $certificate !== null ? self::children($der, $certificate) : null;
		$fields = ($parts !== null && isset($parts[0]) && $parts[0]['tag'] === 0x30) ? self::children($der, $parts[0]) : null;
		if ($fields === null) {
			return null;
		}
		$i = (($fields[0]['tag'] ?? null) === 0xA0) ? 1 : 0;
		if (($fields[$i]['tag'] ?? null) !== 0x02 || !isset($fields[$i + 2], $fields[$i + 4])) {
			return null;
		}
		$issuer = self::name($der, $fields[$i + 2]);
		$subject = self::name($der, $fields[$i + 4]);
		if ($issuer === null || $subject === null) {
			return null;
		}
		return [
			'serial' => self::serial(substr($der, $fields[$i]['content'], $fields[$i]['length'])),
			'issuer' => $issuer,
			'subject' => $subject,
		];
	}

	/**
	 * Read one DER element.
	 *
	 * @param string $der    DER data
	 * @param int    $offset Offset of the element
	 *
	 * @return array|null Array with keys: tag, start, content, length, end; null if it is not valid DER
	 */
	private static function element(string $der, int $offset): ?array {
		$size = strlen($der);
		if ($offset + 2 > $size) {
			return null;
		}
		$tag = ord($der[$offset]);
		if (($tag & 0x1F) === 0x1F) {
			return null;
		}
		$length = ord($der[$offset + 1]);
		$position = $offset + 2;
		if ($length & 0x80) {
			$bytes = $length & 0x7F;
			if ($bytes === 0 || $bytes > 4 || $position + $bytes > $size) {
				return null;
			}
			$length = 0;
			for ($n = 0; $n < $bytes; $n++) {
				$length = ($length << 8) | ord($der[$position++]);
			}
		}
		if ($position + $length > $size) {
			return null;
		}
		return ['tag' => $tag, 'start' => $offset, 'content' => $position, 'length' => $length, 'end' => $position + $length];
	}

	/**
	 * Read the elements inside a constructed DER element.
	 *
	 * @param string $der     DER data
	 * @param array  $element Element from element()
	 *
	 * @return array|null List of elements; null if the content is not valid DER
	 */
	private static function children(string $der, array $element): ?array {
		if (($element['tag'] & 0x20) === 0) {
			return null;
		}
		$children = [];
		$offset = $element['content'];
		while ($offset < $element['end']) {
			$child = self::element($der, $offset);
			if ($child === null || $child['end'] > $element['end']) {
				return null;
			}
			$children[] = $child;
			$offset = $child['end'];
		}
		return $children;
	}

	/**
	 * Decode a DER object identifier.
	 *
	 * @param string $bytes Content of the OBJECT IDENTIFIER
	 *
	 * @return string|null Dotted OID; null if it is not valid
	 */
	private static function oid(string $bytes): ?string {
		if ($bytes === '') {
			return null;
		}
		$values = [];
		$value = 0;
		for ($n = 0; $n < strlen($bytes); $n++) {
			$byte = ord($bytes[$n]);
			$value = ($value << 7) | ($byte & 0x7F);
			if (($byte & 0x80) === 0) {
				$values[] = $value;
				$value = 0;
			}
		}
		if (($byte & 0x80) !== 0) {
			return null;
		}
		$first = $values[0] < 80 ? intdiv($values[0], 40) : 2;
		array_splice($values, 0, 1, [$first, $values[0] - $first * 40]);
		return implode('.', $values);
	}

	/**
	 * Get the OID of an AlgorithmIdentifier.
	 *
	 * @param string $der     DER data
	 * @param array  $element AlgorithmIdentifier element
	 *
	 * @return string|null OID; null if it cannot be read
	 */
	private static function algorithm(string $der, array $element): ?string {
		$children = self::children($der, $element);
		if ($children === null || ($children[0]['tag'] ?? null) !== 0x06) {
			return null;
		}
		return self::oid(substr($der, $children[0]['content'], $children[0]['length']));
	}

	/**
	 * Decode a UTCTime or GeneralizedTime.
	 *
	 * @param string $der     DER data
	 * @param array  $element Time element
	 *
	 * @return int|null Unix timestamp; null if it is not a UTC time in the DER format
	 */
	private static function time(string $der, array $element): ?int {
		$value = substr($der, $element['content'], $element['length']);
		if ($element['tag'] === 0x17 && preg_match('/^(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})Z$/', $value, $m)) {
			$year = (int)$m[1] + ((int)$m[1] < 50 ? 2000 : 1900);
		}
		else if ($element['tag'] === 0x18 && preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})Z$/', $value, $m)) {
			$year = (int)$m[1];
		}
		else {
			return null;
		}
		return gmmktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], $year);
	}

	/**
	 * Normalize a DER Name so that names written with different string types or letter case compare equal.
	 *
	 * Each attribute value is decoded to UTF-8, its white space collapsed and it is lower cased. The order of the
	 * relative distinguished names is kept; the attributes within one are sorted.
	 *
	 * @param string $der     DER data
	 * @param array  $element Name element
	 *
	 * @return string|null Normalized name; null if it cannot be read
	 */
	private static function name(string $der, array $element): ?string {
		$rdns = $element['tag'] === 0x30 ? self::children($der, $element) : null;
		if ($rdns === null) {
			return null;
		}
		$name = [];
		foreach ($rdns as $rdn) {
			$attributes = $rdn['tag'] === 0x31 ? self::children($der, $rdn) : null;
			if ($attributes === null) {
				return null;
			}
			$values = [];
			foreach ($attributes as $attribute) {
				$pair = $attribute['tag'] === 0x30 ? self::children($der, $attribute) : null;
				if ($pair === null || count($pair) !== 2 || $pair[0]['tag'] !== 0x06) {
					return null;
				}
				$oid = self::oid(substr($der, $pair[0]['content'], $pair[0]['length']));
				$value = substr($der, $pair[1]['content'], $pair[1]['length']);
				if ($pair[1]['tag'] === 0x1E) {
					$value = mb_convert_encoding($value, 'UTF-8', 'UTF-16BE');
				}
				else if ($pair[1]['tag'] === 0x1C) {
					$value = mb_convert_encoding($value, 'UTF-8', 'UTF-32BE');
				}
				$value = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value), 'UTF-8');
				$values[] = [$oid, $value];
			}
			sort($values);
			$name[] = $values;
		}
		return json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * Normalize a serial number: hexadecimal without leading zeros.
	 *
	 * @param string $bytes Content of the INTEGER
	 *
	 * @return string Serial number
	 */
	private static function serial(string $bytes): string {
		$hex = ltrim(bin2hex($bytes), '0');
		return $hex === '' ? '0' : $hex;
	}

	/**
	 * Read a list of extensions.
	 *
	 * @param string $der     DER data
	 * @param array  $element Extensions element (SEQUENCE OF Extension)
	 *
	 * @return array|null List of extensions with keys: oid, critical, value (content of extnValue); null if invalid
	 */
	private static function extensions(string $der, array $element): ?array {
		$items = $element['tag'] === 0x30 ? self::children($der, $element) : null;
		if ($items === null) {
			return null;
		}
		$extensions = [];
		foreach ($items as $item) {
			$fields = $item['tag'] === 0x30 ? self::children($der, $item) : null;
			if ($fields === null || count($fields) < 2 || $fields[0]['tag'] !== 0x06) {
				return null;
			}
			$critical = false;
			$value = $fields[1];
			if ($fields[1]['tag'] === 0x01) {
				$critical = substr($der, $fields[1]['content'], $fields[1]['length']) !== "\0";
				$value = $fields[2] ?? null;
			}
			if ($value === null || $value['tag'] !== 0x04) {
				return null;
			}
			$extensions[] = [
				'oid' => self::oid(substr($der, $fields[0]['content'], $fields[0]['length'])),
				'critical' => $critical,
				'value' => substr($der, $value['content'], $value['length']),
			];
		}
		return $extensions;
	}

	/**
	 * Get the normalized directory name from GeneralNames (the Certificate Issuer entry extension).
	 *
	 * @param string $der GeneralNames in DER format
	 *
	 * @return string|null Normalized name; null if there is no directory name
	 */
	private static function general_names_directory(string $der): ?string {
		$names = self::element($der, 0);
		$children = ($names !== null && $names['tag'] === 0x30) ? self::children($der, $names) : null;
		foreach ($children ?? [] as $child) {
			if ($child['tag'] === 0xA4) {
				$inner = self::children($der, $child);
				if ($inner !== null && count($inner) === 1) {
					return self::name($der, $inner[0]);
				}
			}
		}
		return null;
	}

	/**
	 * Get the CA Issuers URLs from an Authority Information Access extension.
	 *
	 * @param string $der AuthorityInfoAccessSyntax in DER format
	 *
	 * @return array https URLs
	 */
	private static function ca_issuers(string $der): array {
		$urls = [];
		$access = self::element($der, 0);
		$descriptions = ($access !== null && $access['tag'] === 0x30) ? self::children($der, $access) : null;
		foreach ($descriptions ?? [] as $description) {
			$fields = $description['tag'] === 0x30 ? self::children($der, $description) : null;
			if ($fields === null || count($fields) !== 2 || $fields[0]['tag'] !== 0x06 || $fields[1]['tag'] !== 0x86) {
				continue;
			}
			$url = substr($der, $fields[1]['content'], $fields[1]['length']);
			if (self::oid(substr($der, $fields[0]['content'], $fields[0]['length'])) === '1.3.6.1.5.5.7.48.2' && stripos($url, 'https://') === 0) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	/**
	 * Get the DER data of the first PEM block of a type.
	 *
	 * @param string $pem  PEM data
	 * @param string $type Block type, such as CERTIFICATE or X509 CRL
	 *
	 * @return string DER data, empty if there is no such block
	 */
	private static function pem_to_der(string $pem, string $type): string {
		if (!preg_match('/-----BEGIN ' . preg_quote($type, '/') . '-----(.+?)-----END ' . preg_quote($type, '/') . '-----/s', $pem, $m)) {
			return '';
		}
		$der = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
		return $der === false ? '' : $der;
	}
}
