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

//define the identity setup class
class identity_setup {

	/**
	 * declare constant variables
	 */
	const app_name = 'identity';
	const app_uuid = '826aa9a8-1b27-4aad-8b73-368f734ef6a2';

	/**
	 * App UUID of the outbound route dialplans
	 */
	const outbound_route_app_uuid = '8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3';

	/**
	 * App UUID of the inbound verification dialplan created by this class
	 */
	const verify_app_uuid = '9cbde6c1-f0c5-456f-a847-c5e6ea16c531';

	/**
	 * The app uuid of the public dialplan that marks a call coming in from outside, from
	 * resources/switch/conf/dialplan/011_identity_origin.xml
	 *
	 * @var string
	 */
	const origin_app_uuid = '5b8f274c-5f69-4f16-94f1-243c44179d2f';

	/**
	 * URL FreeSWITCH uses to reach sign.php and verify.php
	 */
	const local_url = 'http://127.0.0.1/app/identity';

	/**
	 * Parameters of the signing request that take caller controlled values (caller ID, dialed digits, call reason). They
	 * are URL encoded so they cannot add parameters to the request.
	 */
	const encoded_parameters = ['source', 'destination', 'call_reason'];

	/**
	 * Seconds the signing request waits for sign.php, which normally answers within milliseconds. Without a timeout a
	 * stalled sign.php, for example on a slow database, would hold up call setup. The timeout is set with the
	 * curl_timeout channel variable before the request and unset after it: the timeout option of the curl application
	 * is not applied by FreeSWITCH 1.11.
	 */
	const sign_timeout = 3;

	/**
	 * Seconds the verification request waits for verify.php. It may download the certificate of the call (up to 2
	 * seconds) and, once an hour, the CRL and its signer certificate (up to 2 seconds each).
	 */
	const verify_timeout = 6;

	/**
	 * Seconds between two runs of sign_all_routes() started by sign_all_routes_if_due().
	 */
	const sign_all_interval = 3600;

	/**
	 * PostgreSQL advisory lock key held while sign_all_routes() runs, so only one run works at a time.
	 */
	const sign_all_lock = 2188028328;

	/**
	 * First key of the PostgreSQL advisory lock held on one route while signing is added to or removed from it, the
	 * second key is the hash of the route's dialplan UUID. The two key form does not overlap the single key form.
	 */
	const route_lock = 826;

	/**
	 * Seconds a run of sign_all_routes() started from the web server may take before it stops and leaves the rest
	 * for the next run, so a first run on a server with many routes is not cut off by max_execution_time.
	 */
	const sign_all_time_limit = 20;

	/**
	 * Parameters of the verification request that give verify.php the calling and called numbers of the call, which
	 * the PASSporT must match. Without them verify.php returns No-TN-Validation.
	 */
	const verify_parameters = [
		'source' => '${url_encode(${caller_id_number})}',
		'destination' => '${url_encode(${destination_number})}',
	];

	/**
	 * Set in the constructor. Must be a database object and cannot be null.
	 *
	 * @var database Database Object
	 */
	private $database;

	/**
	 * Config object set in the constructor.
	 *
	 * @var config Config Object
	 */
	private $config;

	/**
	 * Domain UUID set in the constructor. This can be passed in through the $setting_array associative array or set
	 * in the session global array
	 *
	 * @var string
	 */
	private $domain_uuid;

	/**
	 * Whether the v_identity_route_exclusions table exists, checked once per object. It is added by the upgrade.
	 *
	 * @var bool|null
	 */
	private $exclusions_table = null;

	/**
	 * Initializes the object with setting array.
	 *
	 * @param array $setting_array An array containing settings for domain, config, and database connections. Defaults
	 *                             to an empty array.
	 *
	 * @return void
	 */
	public function __construct(array $setting_array = []) {
		//set domain UUID
		$this->domain_uuid = $setting_array['domain_uuid'] ?? $_SESSION['domain_uuid'] ?? '';

		//set objects
		$this->database = $setting_array['database'] ?? database::new();
		$this->config = $setting_array['config'] ?? config::load();
	}

	/**
	 * Get the identity settings.
	 *
	 * Uses the same settings as sign.php and verify.php, see identity_settings::get().
	 *
	 * @return array The identity settings with the key and certificate paths
	 */
	public function settings(): array {
		$identity_settings = new identity_settings(['database' => $this->database, 'config' => $this->config]);
		return $identity_settings->get();
	}

	/**
	 * Find out why the web server cannot use the certificate directory, and the commands that fix it.
	 *
	 * problem is empty when the web server can write to the directory, otherwise one of: missing (the directory does
	 * not exist), owner (it is not owned by the web server user, or its mode does not let the owner write), restart (a
	 * systemd drop-in lists it in ReadWritePaths but PHP-FPM was not restarted since), protected (it is owned and
	 * writable but mounted read-only, as PHP-FPM's ProtectSystem does to /etc), or unknown (the cause cannot be found
	 * without the posix extension). command is the shell command to run as root, with the path quoted.
	 *
	 * @param string $path Certificate directory
	 *
	 * @return array Array with keys: problem, command, user (web server user), owner (user:group of the directory),
	 *               mode (octal permissions), service (PHP-FPM systemd service)
	 */
	public static function directory_problem(string $path): array {
		//the web server user and group, and the php-fpm service this page runs in
		$posix = function_exists('posix_geteuid') && function_exists('posix_getpwuid');
		$user = $posix ? (posix_getpwuid(posix_geteuid())['name'] ?? 'www-data') : 'www-data';
		$group = $posix ? (posix_getgrgid(posix_getegid())['name'] ?? $user) : $user;
		$service = 'php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'-fpm';
		if (!file_exists('/lib/systemd/system/'.$service.'.service') && !file_exists('/usr/lib/systemd/system/'.$service.'.service') && !file_exists('/etc/systemd/system/'.$service.'.service')) {
			$service = 'php-fpm';
		}
		$quoted = escapeshellarg($path);
		$drop_in_directory = '/etc/systemd/system/'.$service.'.service.d';
		$restart = 'systemctl daemon-reload && systemctl restart '.$service;
		$drop_in = 'mkdir -p '.$drop_in_directory.' && printf '.escapeshellarg('[Service]\nReadWritePaths=%s\n').' '.$quoted.' > '.$drop_in_directory.'/identity.conf && '.$restart;
		$ownership = 'chown '.$user.':'.$group.' '.$quoted.' && chmod 0750 '.$quoted;
		$result = ['problem' => '', 'command' => '', 'user' => $user, 'owner' => '', 'mode' => '', 'service' => $service];

		//missing or usable
		if (!is_dir($path)) {
			$result['problem'] = 'missing';
			$result['command'] = 'install -d -o '.$user.' -g '.$group.' -m 0750 '.$quoted;
			return $result;
		}
		if (is_writable($path)) {
			return $result;
		}

		//not owned by the web server user, or the owner may not write
		$result['mode'] = substr(sprintf('%o', fileperms($path)), -4);
		if (!$posix) {
			$result['problem'] = 'unknown';
			$result['command'] = $ownership.' && '.$drop_in;
			return $result;
		}
		$owner = fileowner($path);
		$owner_group = filegroup($path);
		$result['owner'] = (posix_getpwuid($owner)['name'] ?? $owner).':'.(posix_getgrgid($owner_group)['name'] ?? $owner_group);
		if ($owner !== posix_geteuid() || (fileperms($path) & 0200) === 0) {
			$result['problem'] = 'owner';
			$result['command'] = $ownership;
			return $result;
		}

		//owned and writable but read-only: a drop-in that php-fpm has not loaded yet, or none
		foreach (glob($drop_in_directory.'/*.conf') ?: [] as $file) {
			preg_match_all('/^\s*ReadWritePaths\s*=(.*)$/m', (string)@file_get_contents($file), $matches);
			foreach ($matches[1] as $paths) {
				foreach (preg_split('/\s+/', trim($paths)) as $allowed) {
					$allowed = rtrim(ltrim($allowed, '-+'), '/');
					if ($allowed !== '' && ($allowed === rtrim($path, '/') || strpos(rtrim($path, '/').'/', $allowed.'/') === 0)) {
						$result['problem'] = 'restart';
						$result['command'] = $restart;
						return $result;
					}
				}
			}
		}
		$result['problem'] = 'protected';
		$result['command'] = $drop_in;
		return $result;
	}

	/**
	 * Import the private key and certificate issued by the STI-CA.
	 *
	 * Either file can be imported on its own. The key must be an EC P-256 (prime256v1) key; an encrypted key is
	 * decrypted with the passphrase and saved without one, because sign.php cannot enter a passphrase. The certificate
	 * may be PEM or DER and may include the chain. The certificate that belongs to the key is saved first, followed by
	 * the rest of the chain. The key and certificate in use afterwards must belong together, so a key that does not
	 * match the existing certificate (or the reverse) is refused. Existing files are kept as <name>.<date>.bak.
	 *
	 * @param string $private_key_pem  Private key in PEM format, or empty to keep the current key
	 * @param string $certificate_data Certificate in PEM or DER format, or empty to keep the current certificate
	 * @param string $passphrase       Passphrase of an encrypted private key
	 *
	 * @return string Empty on success, otherwise an error code: empty, path, key_invalid, key_curve,
	 *                certificate_invalid, certificate_curve, certificate_expired, mismatch, write
	 */
	public function import_certificates(string $private_key_pem, string $certificate_data, string $passphrase = ''): string {
		$settings = $this->settings();
		$result = $this->import_validate($settings, $private_key_pem, $certificate_data, $passphrase);

		//clear any errors left by openssl so they are not reported by a later call
		while (openssl_error_string()) {}

		if (!empty($result['error'])) {
			return $result['error'];
		}

		//create the directory, only the web server user needs to read it
		if (!is_dir($settings['path']) && !@mkdir($settings['path'], 0750, true)) {
			return 'path';
		}
		if (!is_writable($settings['path'])) {
			return 'path';
		}

		//write the files, all or none, so the key and certificate in use always belong together
		$files = [];
		if (isset($result['private_key'])) {
			$files[] = ['path' => $settings['private_key_path'], 'content' => $result['private_key'], 'mode' => 0600];
		}
		if (isset($result['certificate'])) {
			$files[] = ['path' => $settings['certificate_path'], 'content' => $result['certificate'], 'mode' => 0644];
		}
		return $this->import_write($files) ? '' : 'write';
	}

	/**
	 * Validate the files to import and convert them to the format that is saved.
	 *
	 * @param array  $settings         Identity settings from settings()
	 * @param string $private_key_pem  Private key in PEM format, or empty
	 * @param string $certificate_data Certificate in PEM or DER format, or empty
	 * @param string $passphrase       Passphrase of an encrypted private key
	 *
	 * @return array Array with keys: error, and private_key and certificate (PEM) for the files to write
	 */
	private function import_validate(array $settings, string $private_key_pem, string $certificate_data, string $passphrase): array {
		if (trim($private_key_pem) === '' && trim($certificate_data) === '') {
			return ['error' => 'empty'];
		}
		$result = ['error' => ''];

		//private key, the uploaded one or the one in use
		$private_key = false;
		if (trim($private_key_pem) !== '') {
			$private_key = @openssl_pkey_get_private($private_key_pem, $passphrase !== '' ? $passphrase : null);
			if ($private_key === false) {
				return ['error' => 'key_invalid'];
			}
			$details = openssl_pkey_get_details($private_key);
			if (($details['ec']['curve_name'] ?? '') != 'prime256v1') {
				return ['error' => 'key_curve'];
			}
			if (!openssl_pkey_export($private_key, $result['private_key'])) {
				return ['error' => 'key_invalid'];
			}
		}
		else if (is_readable($settings['private_key_path'])) {
			$private_key = @openssl_pkey_get_private((string)file_get_contents($settings['private_key_path']));
		}

		//certificates, the uploaded ones or the one in use
		$leaf = false;
		if (trim($certificate_data) !== '') {
			//convert a der certificate to pem
			if (strpos($certificate_data, '-----BEGIN') === false) {
				$certificate_data = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($certificate_data), 64, "\n")."-----END CERTIFICATE-----\n";
			}
			$certificates = [];
			preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $certificate_data, $matches);
			foreach ($matches[0] as $block) {
				$certificate = @openssl_x509_read($block);
				if ($certificate === false || !openssl_x509_export($certificate, $pem)) {
					return ['error' => 'certificate_invalid'];
				}
				$certificates[] = ['x509' => $certificate, 'pem' => $pem];
			}
			if (empty($certificates)) {
				return ['error' => 'certificate_invalid'];
			}

			//put the certificate of the private key first, the rest is the chain
			if ($private_key !== false) {
				foreach ($certificates as $i => $certificate) {
					if (openssl_x509_check_private_key($certificate['x509'], $private_key)) {
						unset($certificates[$i]);
						array_unshift($certificates, $certificate);
						break;
					}
				}
			}

			//check the certificate
			$public_key = openssl_pkey_get_public($certificates[0]['x509']);
			$details = $public_key !== false ? openssl_pkey_get_details($public_key) : [];
			if (($details['ec']['curve_name'] ?? '') != 'prime256v1') {
				return ['error' => 'certificate_curve'];
			}
			$info = openssl_x509_parse($certificates[0]['x509']);
			if (($info['validTo_time_t'] ?? 0) < time()) {
				return ['error' => 'certificate_expired'];
			}
			$result['certificate'] = implode('', array_column($certificates, 'pem'));
			$leaf = $certificates[0]['x509'];
		}
		else if (is_readable($settings['certificate_path'])) {
			$leaf = @openssl_x509_read((string)file_get_contents($settings['certificate_path']));
		}

		//the key and certificate in use afterwards must belong together
		if ($private_key !== false && $leaf !== false && !openssl_x509_check_private_key($leaf, $private_key)) {
			return ['error' => 'mismatch'];
		}

		return $result;
	}

	/**
	 * Replace files, all or none, keeping each existing file as <name>.<date>.bak.
	 *
	 * Every new file is written to a temporary file in the same directory first, then renamed over the file in use, so
	 * sign.php never reads a partly written key or certificate. If a rename fails, the files already replaced are put
	 * back from their backups, or removed if they did not exist before.
	 *
	 * @param array $files List of files, each with keys: path, content, mode
	 *
	 * @return bool True if every file was written
	 */
	private function import_write(array $files): bool {
		//write the temporary files, tempnam() creates them with mode 0600
		$staged = [];
		foreach ($files as $file) {
			$temp = $this->import_temp($file['path'], $file['content'], $file['mode']);
			if ($temp === false) {
				$this->import_cleanup($staged);
				return false;
			}
			$staged[] = ['path' => $file['path'], 'temp' => $temp, 'backup' => ''];
		}

		//keep the existing files, copy() creates the backup with the default mode so set it to the mode of the file
		foreach ($staged as $i => $file) {
			if (!file_exists($file['path'])) {
				continue;
			}
			$backup = $file['path'].'.'.date('YmdHis').'.bak';
			for ($n = 1; file_exists($backup); $n++) {
				$backup = $file['path'].'.'.date('YmdHis').'-'.$n.'.bak';
			}
			$old = umask(0077);
			$copied = @copy($file['path'], $backup);
			umask($old);
			if (!$copied) {
				$this->import_cleanup($staged);
				return false;
			}
			@chmod($backup, fileperms($file['path']) & 0777);
			$staged[$i]['backup'] = $backup;
		}

		//replace the files, and put back the ones already replaced if one fails
		foreach ($staged as $i => $file) {
			if (@rename($file['temp'], $file['path'])) {
				continue;
			}
			for ($j = 0; $j < $i; $j++) {
				if ($staged[$j]['backup'] === '') {
					@unlink($staged[$j]['path']);
					continue;
				}
				$content = @file_get_contents($staged[$j]['backup']);
				$temp = $content === false ? false : $this->import_temp($staged[$j]['path'], $content, fileperms($staged[$j]['backup']) & 0777);
				if ($temp === false || !@rename($temp, $staged[$j]['path'])) {
					if ($temp !== false) {
						@unlink($temp);
					}
					error_log('identity: could not restore '.$staged[$j]['path'].' from '.$staged[$j]['backup']);
				}
			}
			$this->import_cleanup(array_slice($staged, $i));
			return false;
		}
		return true;
	}

	/**
	 * Write content to a new temporary file in the directory of a path.
	 *
	 * @param string $path    Path of the file the temporary file will replace
	 * @param string $content Content
	 * @param int    $mode    File mode
	 *
	 * @return string|false Path of the temporary file, or false if it could not be written
	 */
	private function import_temp(string $path, string $content, int $mode) {
		$temp = @tempnam(dirname($path), '.identity');
		if ($temp === false) {
			return false;
		}
		if (dirname($temp) !== realpath(dirname($path)) || @file_put_contents($temp, $content) !== strlen($content) || !@chmod($temp, $mode)) {
			@unlink($temp);
			return false;
		}
		return $temp;
	}

	/**
	 * Remove the temporary files of files that were not replaced.
	 *
	 * @param array $staged Files with key temp
	 *
	 * @return void
	 */
	private function import_cleanup(array $staged) {
		foreach ($staged as $file) {
			@unlink($file['temp']);
		}
	}

	/**
	 * Get the outbound routes and whether each one signs calls.
	 *
	 * Only routes with a bridge action are returned. The suggested destination is taken from the first bridge so the
	 * signed number matches the number sent to the carrier. Global routes (no domain) are only returned with $all.
	 * The routes are filtered, sorted and paged in the database, and the actions are only read for the routes returned.
	 *
	 * @param bool  $all     Return the routes of all domains, and the global routes, instead of the current domain
	 * @param array $options Optional keys: search (part of the route or domain name), status (signed, unsigned: not
	 *                       signed and not excluded, excluded: not signed and excluded), order_by
	 *                       (domain_name, dialplan_name, dialplan_enabled or signed), order (asc or desc), limit and
	 *                       offset
	 *
	 * @return array List of routes with keys: dialplan_uuid, domain_uuid, domain_name, dialplan_name, dialplan_context,
	 *               dialplan_enabled, signed, destination, destination_found (false when the destination is $1
	 *               because it could not be taken from the bridge), attest_level, excluded (signing was removed
	 *               from the route, so Sign All Routes leaves it unsigned)
	 */
	public function outbound_routes(bool $all = false, array $options = []): array {
		//get the outbound routes
		$columns = $this->route_columns();
		$sql = "select d.dialplan_uuid, d.domain_uuid, m.domain_name, d.dialplan_name, d.dialplan_context, ";
		$sql .= "cast(d.dialplan_enabled as text) as dialplan_enabled, ";
		$sql .= $columns['signed']." as signed, ";
		$sql .= $columns['excluded']." as excluded ";
		$sql .= "from v_dialplans as d ";
		$sql .= "left join v_domains as m on m.domain_uuid = d.domain_uuid ";
		$sql .= $this->routes_where($all, $options, $parameters);
		$order = ($options['order'] ?? '') === 'desc' ? 'desc' : 'asc';
		//every order ends with the uuid, so the pages do not overlap or skip routes that sort the same
		switch ($options['order_by'] ?? '') {
			case 'domain_name':
				$sql .= "order by m.domain_name ".$order.", d.dialplan_order asc, d.dialplan_name asc, d.dialplan_uuid asc ";
				break;
			case 'dialplan_name':
				$sql .= "order by d.dialplan_name ".$order.", m.domain_name asc, d.dialplan_uuid asc ";
				break;
			case 'dialplan_enabled':
				$sql .= "order by d.dialplan_enabled ".$order.", m.domain_name asc, d.dialplan_order asc, d.dialplan_name asc, d.dialplan_uuid asc ";
				break;
			case 'signed':
				$sql .= "order by signed ".$order.", excluded ".$order.", m.domain_name asc, d.dialplan_order asc, d.dialplan_name asc, d.dialplan_uuid asc ";
				break;
			default:
				$sql .= "order by m.domain_name asc, d.dialplan_order asc, d.dialplan_name asc, d.dialplan_uuid asc ";
		}
		if (!empty($options['limit'])) {
			$sql .= limit_offset((int)$options['limit'], (int)($options['offset'] ?? 0));
		}
		$rows = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);
		if (!is_array($rows) || @sizeof($rows) == 0) {
			return [];
		}

		//get the actions of these routes, the uuids come from the database and are checked before they are inlined
		$uuids = [];
		foreach ($rows as $row) {
			if (is_uuid($row['dialplan_uuid'])) {
				$uuids[] = "'".$row['dialplan_uuid']."'";
			}
		}
		$sql = "select dialplan_uuid, dialplan_detail_type, dialplan_detail_data ";
		$sql .= "from v_dialplan_details ";
		$sql .= "where dialplan_detail_tag = 'action' ";
		$sql .= "and dialplan_uuid in (".implode(', ', $uuids).") ";
		$sql .= "order by dialplan_uuid asc, dialplan_detail_group asc, dialplan_detail_order asc ";
		$details = $this->database->select($sql, null, 'all');
		unset($sql, $uuids);

		//find the first bridge and any signing action of each route
		$bridges = [];
		$signing = [];
		if (is_array($details)) {
			foreach ($details as $detail) {
				$uuid = $detail['dialplan_uuid'];
				if ($detail['dialplan_detail_type'] == 'bridge' && !isset($bridges[$uuid])) {
					$bridges[$uuid] = $detail['dialplan_detail_data'];
				}
				if ($detail['dialplan_detail_type'] == 'curl' && strpos($detail['dialplan_detail_data'], '/app/identity/sign.php') !== false) {
					$signing[$uuid] = $detail['dialplan_detail_data'];
				}
			}
		}

		//build the list
		$routes = [];
		foreach ($rows as $row) {
			$uuid = $row['dialplan_uuid'];
			$row['signed'] = isset($signing[$uuid]);
			$row['excluded'] = in_array($row['excluded'], [true, 't', 'true', 1, '1'], true);
			$row['destination'] = $this->bridge_destination($bridges[$uuid] ?? '');
			$row['destination_found'] = $row['destination'] !== '';
			if (!$row['destination_found']) {
				$row['destination'] = '$1';
			}
			$row['attest_level'] = 'A';
			if (isset($signing[$uuid])) {
				//show the values currently in use
				if (preg_match('/[?&\s]destination=(?:\$\{url_encode\(([^&\s]*)\)\}|([^&\s]*))/', $signing[$uuid], $matches)) {
					$row['destination'] = ($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? '');
				}
				if (preg_match('/[?&\s]attest_level=([ABC])/', $signing[$uuid], $matches)) {
					$row['attest_level'] = $matches[1];
				}
			}
			$routes[] = $row;
		}
		return $routes;
	}

	/**
	 * Count the outbound routes that outbound_routes() returns, for paging.
	 *
	 * @param bool  $all     Count the routes of all domains, and the global routes, instead of the current domain
	 * @param array $options Optional keys: search and status, as for outbound_routes()
	 *
	 * @return int Number of routes
	 */
	public function outbound_route_count(bool $all = false, array $options = []): int {
		$sql = "select count(d.dialplan_uuid) ";
		$sql .= "from v_dialplans as d ";
		$sql .= "left join v_domains as m on m.domain_uuid = d.domain_uuid ";
		$sql .= $this->routes_where($all, $options, $parameters);
		$count = $this->database->select($sql, $parameters, 'column');
		unset($sql, $parameters);
		return (int)$count;
	}

	/**
	 * Count the outbound routes by signing status, for the checks and the overview.
	 *
	 * Counting the skipped routes reads the actions of every route that is not signed and not excluded, which with
	 * Sign All Routes on are only the routes it could not sign, so it is only done when asked for.
	 *
	 * @param bool $all           Count the routes of all domains, and the global routes, instead of the current domain
	 * @param bool $count_skipped Also count the skipped routes
	 *
	 * @return array Counts with keys: total, signed, excluded (not signed and excluded from Sign All Routes), skipped
	 *               (not signed, not excluded, and the destination cannot be taken from the bridge, 0 unless counted)
	 */
	public function outbound_route_counts(bool $all = false, bool $count_skipped = false): array {
		$columns = $this->route_columns();
		$sql = "select count(*) as total, ";
		$sql .= "count(*) filter (where r.signed) as signed, ";
		$sql .= "count(*) filter (where not r.signed and r.excluded) as excluded ";
		$sql .= "from (select ".$columns['signed']." as signed, ".$columns['excluded']." as excluded ";
		$sql .= "from v_dialplans as d ";
		$sql .= "left join v_domains as m on m.domain_uuid = d.domain_uuid ";
		$sql .= $this->routes_where($all, [], $parameters);
		$sql .= ") as r ";
		$row = $this->database->select($sql, $parameters, 'row');
		unset($sql, $parameters);

		$counts['total'] = (int)($row['total'] ?? 0);
		$counts['signed'] = (int)($row['signed'] ?? 0);
		$counts['excluded'] = (int)($row['excluded'] ?? 0);
		$counts['skipped'] = 0;
		if ($count_skipped && $counts['total'] > $counts['signed'] + $counts['excluded']) {
			foreach ($this->outbound_routes($all, ['status' => 'unsigned']) as $route) {
				if (!$route['destination_found']) {
					$counts['skipped']++;
				}
			}
		}
		return $counts;
	}

	/**
	 * Get the SQL expressions of the signing status of a route, for a query of v_dialplans as d.
	 *
	 * @return array Expressions with keys: signed, excluded (false until the upgrade has added the exclusions table)
	 */
	private function route_columns(): array {
		$columns['signed'] = "exists (select 1 from v_dialplan_details as s where s.dialplan_uuid = d.dialplan_uuid and s.dialplan_detail_tag = 'action' and s.dialplan_detail_type = 'curl' and s.dialplan_detail_data like '%/app/identity/sign.php%')";
		if ($this->has_exclusions_table()) {
			$columns['excluded'] = "exists (select 1 from v_identity_route_exclusions as e where e.dialplan_uuid = d.dialplan_uuid)";
		}
		else {
			$columns['excluded'] = "false";
		}
		return $columns;
	}

	/**
	 * Build the where clause of the outbound routes, for a query of v_dialplans as d joined with v_domains as m.
	 *
	 * @param bool       $all        Include the routes of all domains, and the global routes
	 * @param array      $options    Optional keys: search and status, as for outbound_routes()
	 * @param array|null $parameters Set to the parameters of the clause
	 *
	 * @return string Where clause
	 */
	private function routes_where(bool $all, array $options, ?array &$parameters): string {
		$columns = $this->route_columns();
		$parameters = [];
		$sql = "where d.app_uuid = :app_uuid ";
		$parameters['app_uuid'] = self::outbound_route_app_uuid;
		$sql .= "and exists (select 1 from v_dialplan_details as b where b.dialplan_uuid = d.dialplan_uuid and b.dialplan_detail_tag = 'action' and b.dialplan_detail_type = 'bridge') ";
		if (!$all) {
			$sql .= "and d.domain_uuid = :domain_uuid ";
			$parameters['domain_uuid'] = $this->domain_uuid;
		}
		if (!empty($options['search'])) {
			$sql .= "and (lower(d.dialplan_name) like :search_name or lower(m.domain_name) like :search_domain) ";
			$parameters['search_name'] = self::like_pattern(mb_strtolower($options['search']));
			$parameters['search_domain'] = self::like_pattern(mb_strtolower($options['search']));
		}
		switch ($options['status'] ?? '') {
			case 'signed':
				$sql .= "and ".$columns['signed']." ";
				break;
			case 'unsigned':
				$sql .= "and not ".$columns['signed']." and not ".$columns['excluded']." ";
				break;
			case 'excluded':
				$sql .= "and not ".$columns['signed']." and ".$columns['excluded']." ";
				break;
		}
		return $sql;
	}

	/**
	 * Get the number dialed by a bridge action.
	 *
	 * Uses the first leg of the bridge, removes channel variables and the host, and returns what follows the last
	 * slash. For example sofia/gateway/uuid/1$1 returns 1$1.
	 *
	 * @param string $bridge_data Data of the bridge action
	 *
	 * @return string Destination expression such as 1$1, or an empty string if it cannot be determined
	 */
	private function bridge_destination(string $bridge_data): string {
		$leg = preg_split('/[|,]/', preg_replace('/^(\{[^}]*\}|\[[^\]]*\])+/', '', trim($bridge_data)))[0] ?? '';
		$leg = preg_replace('/^(\{[^}]*\}|\[[^\]]*\])+/', '', $leg);
		$destination = substr($leg, strrpos($leg, '/') !== false ? strrpos($leg, '/') + 1 : 0);
		$destination = explode('@', $destination)[0];
		if (!preg_match('/^\+?[0-9]*\$[0-9]$/', $destination)) {
			return '';
		}
		return $destination;
	}

	/**
	 * Add signing to an outbound route.
	 *
	 * Inserts the actions that request a SIP Identity header from sign.php before the first bridge, renumbers the
	 * actions of that condition group, then rebuilds the dialplan XML. Removes the route's exclusion, so Sign All
	 * Routes keeps it signed. Holds the route's lock, so it cannot run at the same time as another change to the
	 * route's signing.
	 *
	 * @param string $dialplan_uuid Outbound route dialplan UUID
	 * @param string $destination   Destination expression such as 1$1
	 * @param string $attest_level  Attestation level A, B, or C
	 * @param bool   $all           Allow a route of any domain instead of only the current domain
	 *
	 * @return bool True if signing was added
	 */
	public function add_signing(string $dialplan_uuid, string $destination, string $attest_level, bool $all = false): bool {
		if (!is_uuid($dialplan_uuid)) {
			return false;
		}
		$this->lock_route($dialplan_uuid);
		try {
			return $this->add_signing_locked($dialplan_uuid, $destination, $attest_level, $all);
		}
		finally {
			$this->unlock_route($dialplan_uuid);
		}
	}

	/**
	 * Add signing to an outbound route while its lock is held, see add_signing().
	 *
	 * @param string $dialplan_uuid Outbound route dialplan UUID
	 * @param string $destination   Destination expression such as 1$1
	 * @param string $attest_level  Attestation level A, B, or C
	 * @param bool   $all           Allow a route of any domain instead of only the current domain
	 *
	 * @return bool True if signing was added
	 */
	private function add_signing_locked(string $dialplan_uuid, string $destination, string $attest_level, bool $all): bool {
		//validate the values
		if (!is_uuid($dialplan_uuid) || !preg_match('/^\+?[0-9]*\$[0-9]$/', $destination) || !in_array($attest_level, ['A', 'B', 'C'], true)) {
			return false;
		}

		//get the route
		$route = $this->route($dialplan_uuid, $all);
		if (empty($route)) {
			return false;
		}

		//get the details
		$sql = "select dialplan_detail_uuid, dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data, dialplan_detail_group, dialplan_detail_order ";
		$sql .= "from v_dialplan_details ";
		$sql .= "where dialplan_uuid = :dialplan_uuid ";
		$sql .= "order by dialplan_detail_group asc, dialplan_detail_order asc ";
		$parameters['dialplan_uuid'] = $dialplan_uuid;
		$details = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);
		if (!is_array($details)) {
			return false;
		}

		//find the group of the first bridge, and stop if the route already signs, clearing an exclusion left from signing
		//that was added back in the dialplan editor
		$group = null;
		foreach ($details as $detail) {
			if ($detail['dialplan_detail_tag'] == 'action' && $detail['dialplan_detail_type'] == 'curl' && strpos($detail['dialplan_detail_data'], '/app/identity/sign.php') !== false) {
				$this->include_route($dialplan_uuid);
				return false;
			}
			if ($group === null && $detail['dialplan_detail_tag'] == 'action' && $detail['dialplan_detail_type'] == 'bridge') {
				$group = $detail['dialplan_detail_group'];
			}
		}
		if ($group === null) {
			return false;
		}

		//the actions to add
		$data = 'source=${effective_caller_id_number}&destination='.$destination.'&attest_level='.$attest_level.'&domain_uuid=${domain_uuid}&call_uuid=${uuid}';
		$actions = [
			['multiunset', 'sip_h_Identity curl_response_data'],
			['set', 'curl_timeout='.self::sign_timeout],
			['curl', self::encode_signing_data(self::local_url.'/sign.php POST '.$data)],
			['unset', 'curl_timeout'],
			['export', 'exclude_outgoing_extra_header=sip_h_Identity'],
			['set', 'sip_h_Identity=${curl_response_data}'],
		];

		//build the array, renumber the group and insert the actions before the bridge
		$x = 0;
		$order = 0;
		$inserted = false;
		foreach ($details as $detail) {
			if ($detail['dialplan_detail_group'] != $group) {
				continue;
			}
			if (!$inserted && $detail['dialplan_detail_tag'] == 'action' && $detail['dialplan_detail_type'] == 'bridge') {
				foreach ($actions as $action) {
					$order += 10;
					$array['dialplan_details'][$x]['dialplan_detail_uuid'] = uuid();
					$array['dialplan_details'][$x]['domain_uuid'] = $route['domain_uuid'];
					$array['dialplan_details'][$x]['dialplan_uuid'] = $dialplan_uuid;
					$array['dialplan_details'][$x]['dialplan_detail_tag'] = 'action';
					$array['dialplan_details'][$x]['dialplan_detail_type'] = $action[0];
					$array['dialplan_details'][$x]['dialplan_detail_data'] = $action[1];
					$array['dialplan_details'][$x]['dialplan_detail_group'] = $group;
					$array['dialplan_details'][$x]['dialplan_detail_order'] = $order;
					$array['dialplan_details'][$x]['dialplan_detail_enabled'] = true;
					$x++;
				}
				$inserted = true;
			}
			$order += 10;
			if ($detail['dialplan_detail_order'] != $order) {
				$array['dialplan_details'][$x]['dialplan_detail_uuid'] = $detail['dialplan_detail_uuid'];
				$array['dialplan_details'][$x]['dialplan_detail_order'] = $order;
				$x++;
			}
		}

		//save the details
		$p = permissions::new();
		$p->add('dialplan_detail_add', 'temp');
		$p->add('dialplan_detail_edit', 'temp');
		$this->database->save($array);
		unset($array);
		$p->delete('dialplan_detail_add', 'temp');
		$p->delete('dialplan_detail_edit', 'temp');

		//stop if the actions were not saved, the exclusion is kept
		if (!$this->route_signs($dialplan_uuid)) {
			return false;
		}

		//rebuild the dialplan xml and clear the cache
		$this->update_dialplan($dialplan_uuid, $route['dialplan_context']);

		//sign all routes no longer leaves the route unsigned
		$this->include_route($dialplan_uuid);

		return true;
	}

	/**
	 * Remove signing from an outbound route.
	 *
	 * Deletes the actions added by add_signing() and rebuilds the dialplan XML. The route is excluded from Sign All
	 * Routes first, so a run of Sign All Routes never sees it unsigned and not excluded; nothing is deleted when the
	 * exclusion cannot be saved. A route that does not sign is only excluded. Holds the route's lock, so it cannot run
	 * at the same time as another change to the route's signing.
	 *
	 * @param string $dialplan_uuid Outbound route dialplan UUID
	 * @param bool   $all           Allow a route of any domain instead of only the current domain
	 *
	 * @return bool True if the route no longer signs and is excluded (or the upgrade has not added the exclusions
	 *              table and signing was removed)
	 */
	public function remove_signing(string $dialplan_uuid, bool $all = false): bool {
		if (!is_uuid($dialplan_uuid)) {
			return false;
		}
		$this->lock_route($dialplan_uuid);
		try {
			return $this->remove_signing_locked($dialplan_uuid, $all);
		}
		finally {
			$this->unlock_route($dialplan_uuid);
		}
	}

	/**
	 * Remove signing from an outbound route while its lock is held, see remove_signing().
	 *
	 * @param string $dialplan_uuid Outbound route dialplan UUID
	 * @param bool   $all           Allow a route of any domain instead of only the current domain
	 *
	 * @return bool True if signing was removed
	 */
	private function remove_signing_locked(string $dialplan_uuid, bool $all): bool {
		//get the route
		$route = $this->route($dialplan_uuid, $all);
		if (empty($route)) {
			return false;
		}

		//a route that does not sign is only excluded, so sign all routes leaves it unsigned; nothing is deleted, since
		//some of the actions below, such as unset curl_timeout, may be the route's own
		if (!$this->route_signs($dialplan_uuid)) {
			return $this->has_exclusions_table() && $this->exclude_route($dialplan_uuid, (string)$route['domain_uuid']);
		}

		//find the actions added by add_signing
		$sql = "select dialplan_detail_uuid from v_dialplan_details ";
		$sql .= "where dialplan_uuid = :dialplan_uuid ";
		$sql .= "and dialplan_detail_tag = 'action' ";
		$sql .= "and ( ";
		$sql .= "	(dialplan_detail_type = 'unset' and dialplan_detail_data = 'sip_h_Identity') ";
		$sql .= "	or (dialplan_detail_type = 'multiunset' and dialplan_detail_data = 'sip_h_Identity curl_response_data') ";
		$sql .= "	or (dialplan_detail_type = 'set' and dialplan_detail_data = :timeout) ";
		$sql .= "	or (dialplan_detail_type = 'unset' and dialplan_detail_data = 'curl_timeout') ";
		$sql .= "	or (dialplan_detail_type = 'curl' and dialplan_detail_data like :sign_url) ";
		$sql .= "	or (dialplan_detail_type = 'export' and dialplan_detail_data = 'exclude_outgoing_extra_header=sip_h_Identity') ";
		$sql .= "	or (dialplan_detail_type = 'set' and dialplan_detail_data = 'sip_h_Identity=\${curl_response_data}') ";
		$sql .= ") ";
		$parameters['dialplan_uuid'] = $dialplan_uuid;
		$parameters['sign_url'] = '%/app/identity/sign.php%';
		$parameters['timeout'] = 'curl_timeout='.self::sign_timeout;
		$rows = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);
		if (!is_array($rows) || @sizeof($rows) == 0) {
			return false;
		}

		//keep sign all routes from signing the route again, before the actions are deleted; stop if the exclusion was
		//not saved, so the route is never left unsigned and not excluded
		if (!$this->exclude_route($dialplan_uuid, (string)$route['domain_uuid'])) {
			return false;
		}

		//delete the actions
		foreach ($rows as $x => $row) {
			$array['dialplan_details'][$x]['dialplan_detail_uuid'] = $row['dialplan_detail_uuid'];
			$array['dialplan_details'][$x]['dialplan_uuid'] = $dialplan_uuid;
		}
		$p = permissions::new();
		$p->add('dialplan_detail_delete', 'temp');
		$this->database->delete($array);
		unset($array);
		$p->delete('dialplan_detail_delete', 'temp');

		//rebuild the dialplan xml and clear the cache, also when the delete failed part way
		$this->update_dialplan($dialplan_uuid, $route['dialplan_context']);

		//when the route still signs, undo the exclusion so the route is never left signed and excluded
		if ($this->route_signs($dialplan_uuid)) {
			$this->include_route($dialplan_uuid);
			return false;
		}
		return true;
	}

	/**
	 * Add signing to the checked routes of a list. Checked routes that already sign are left as they are.
	 *
	 * @param array $routes Rows posted by the routes list, each with keys: checked, uuid, destination, attest_level
	 * @param bool  $all    Allow the routes of any domain, and global routes, instead of only the current domain
	 *
	 * @return array Counts with keys: added, skipped (no destination entered), failed
	 */
	public function sign_routes(array $routes, bool $all = false): array {
		$result = ['added' => 0, 'skipped' => 0, 'failed' => 0];
		foreach ($routes as $route) {
			if (!is_array($route) || ($route['checked'] ?? '') !== 'true' || !is_uuid($route['uuid'] ?? '')) {
				continue;
			}
			if ($this->route_signs($route['uuid'])) {
				continue;
			}
			$destination = is_string($route['destination'] ?? null) ? trim($route['destination']) : '';
			if ($destination === '') {
				$result['skipped']++;
				continue;
			}
			$attest_level = is_string($route['attest_level'] ?? null) ? $route['attest_level'] : '';
			if ($this->add_signing($route['uuid'], $destination, $attest_level, $all)) {
				$result['added']++;
			}
			else {
				$result['failed']++;
			}
		}
		return $result;
	}

	/**
	 * Remove signing from the checked routes of a list, which excludes them from Sign All Routes. Checked routes that
	 * do not sign are excluded, so Sign All Routes does not sign them; those already excluded are left as they are.
	 *
	 * @param array $routes Rows posted by the routes list, each with keys: checked, uuid
	 * @param bool  $all    Allow the routes of any domain, and global routes, instead of only the current domain
	 *
	 * @return array Counts with keys: removed, excluded (routes that did not sign and were excluded), failed
	 */
	public function unsign_routes(array $routes, bool $all = false): array {
		$result = ['removed' => 0, 'excluded' => 0, 'failed' => 0];
		foreach ($routes as $route) {
			if (!is_array($route) || ($route['checked'] ?? '') !== 'true' || !is_uuid($route['uuid'] ?? '')) {
				continue;
			}
			$signs = $this->route_signs($route['uuid']);
			if (!$signs && (!$this->has_exclusions_table() || $this->is_excluded($route['uuid']))) {
				continue;
			}
			if ($this->remove_signing($route['uuid'], $all)) {
				$result[$signs ? 'removed' : 'excluded']++;
			}
			else {
				$result['failed']++;
			}
		}
		return $result;
	}

	/**
	 * Add signing to every route that does not sign yet and matches the search, on every page of the list.
	 *
	 * A route shown on the current page is signed with the destination and attestation posted for it, and skipped
	 * when the destination was left empty. Other routes use the destination taken from the bridge and attestation A,
	 * and are skipped when the destination cannot be taken from the bridge, rather than signed with a guess.
	 *
	 * @param array $routes  Rows posted by the routes list, each with keys: uuid, destination, attest_level
	 * @param bool  $all     Include the routes of all domains, and global routes, instead of only the current domain
	 * @param array $options    Keys: search
	 * @param int   $time_limit Seconds after which no more routes are signed, so a web request is not cut off by
	 *                          PHP's max_execution_time; 0 for no limit
	 *
	 * @return array Counts with keys: added, failed, skipped, remaining (not reached before the time limit)
	 */
	public function sign_unsigned_routes(array $routes, bool $all = false, array $options = [], int $time_limit = 0): array {
		$start = microtime(true);
		$this->extend_time_limit($time_limit);

		//index the posted rows by route
		$posted = [];
		foreach ($routes as $route) {
			if (is_array($route) && is_uuid($route['uuid'] ?? '')) {
				$posted[$route['uuid']] = $route;
			}
		}

		//sign each route
		$result = ['added' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => 0];
		$list = $this->outbound_routes($all, ['search' => $options['search'] ?? '', 'status' => 'unsigned']);
		foreach ($list as $i => $route) {
			if ($time_limit > 0 && microtime(true) - $start > $time_limit) {
				$result['remaining'] = count($list) - $i;
				break;
			}
			$uuid = $route['dialplan_uuid'];
			if (isset($posted[$uuid])) {
				$destination = is_string($posted[$uuid]['destination'] ?? null) ? trim($posted[$uuid]['destination']) : '';
				$attest_level = is_string($posted[$uuid]['attest_level'] ?? null) ? $posted[$uuid]['attest_level'] : '';
				if ($destination === '') {
					$result['skipped']++;
					continue;
				}
			}
			else if ($route['destination_found']) {
				$destination = $route['destination'];
				$attest_level = $route['attest_level'];
			}
			else {
				$result['skipped']++;
				continue;
			}
			if ($this->add_signing($uuid, $destination, $attest_level, $all)) {
				$result['added']++;
			}
			else {
				$result['failed']++;
			}
		}
		return $result;
	}

	/**
	 * Add signing to every outbound route of every domain that does not sign yet, for the Sign All Routes setting.
	 *
	 * Uses the destination taken from the first bridge and attestation A, which the attestation check lowers to B
	 * when the domain does not own the calling number. Skips the routes that signing was removed from (excluded) and
	 * the routes whose destination cannot be taken from the bridge, which must be added on the Routes page. Removes
	 * the exclusions of routes that were deleted. Only one run at a time, others return without doing anything. Each
	 * route is checked again while its lock is held, so a route that signing was just removed from is not signed again.
	 *
	 * @param int $time_limit Seconds the run may take before it stops and counts the routes left as remaining, 0 for
	 *                        no limit
	 *
	 * @return array Counts with keys: added, failed, skipped (destination not found), remaining (not reached before
	 *               the time limit)
	 */
	public function sign_all_routes(int $time_limit = 0): array {
		$result = ['added' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => 0];
		$start = microtime(true);
		$this->extend_time_limit($time_limit);

		//the exclusions table is added by the upgrade, without it removed routes would be signed again
		if (!$this->has_exclusions_table()) {
			return $result;
		}

		//let one run at a time
		$sql = "select cast(pg_try_advisory_lock(:lock) as text) ";
		$parameters['lock'] = self::sign_all_lock;
		$locked = $this->database->select($sql, $parameters, 'column');
		unset($sql, $parameters);
		if ($locked !== 'true') {
			return $result;
		}

		try {
			//remove the exclusions of routes that were deleted, and of routes whose signing was added back in the dialplan
			//editor, so signing deleted later is added back
			$this->purge_exclusions();
			$this->include_signed_routes();

			//sign each route that does not sign yet, the time limit counts from the start of the run
			$routes = $this->outbound_routes(true, ['status' => 'unsigned']);
			foreach ($routes as $i => $route) {
				if ($time_limit > 0 && microtime(true) - $start > $time_limit) {
					$result['remaining'] = count($routes) - $i;
					break;
				}
				if (!$route['destination_found']) {
					$result['skipped']++;
					continue;
				}
				$uuid = $route['dialplan_uuid'];
				$this->lock_route($uuid);
				try {
					//signing may have been added or removed since the list was read
					if ($this->route_signs($uuid) || $this->is_excluded($uuid)) {
						continue;
					}
					if ($this->add_signing_locked($uuid, $route['destination'], $route['attest_level'], true)) {
						$result['added']++;
					}
					else {
						$result['failed']++;
					}
				}
				finally {
					$this->unlock_route($uuid);
				}
			}
		}
		finally {
			//release the lock
			$sql = "select pg_advisory_unlock(:lock) ";
			$parameters['lock'] = self::sign_all_lock;
			$this->database->select($sql, $parameters, 'column');
			unset($sql, $parameters);
		}

		return $result;
	}

	/**
	 * Run sign_all_routes() at most once per sign_all_interval, limited to sign_all_time_limit seconds.
	 *
	 * sign.php and verify.php call this after the response was sent, and Setup and the Routes page call it for
	 * platform administrators, so new outbound routes are signed within an hour without a cron job. The time of the
	 * last run is kept in identity_sign_all_routes_<uid> in the FusionPBX cache directory (see
	 * identity_schedule::due()). When the file cannot be written nothing runs, and the routes are signed when the
	 * setting is saved or on upgrade. A run that stops at the time limit makes the next one due at once.
	 *
	 * @param database $database   Database object
	 * @param string   $cache_path Directory for the time of the last run, the cache location setting
	 * @param bool     $force      Run even if it is not due, such as when the setting was just turned on
	 *
	 * @return array|null Counts from sign_all_routes(), or null if it did not run
	 */
	public static function sign_all_routes_if_due(database $database, string $cache_path = '/var/cache/fusionpbx', bool $force = false): ?array {
		$due = identity_schedule::due('identity_sign_all_routes', self::sign_all_interval, $cache_path);
		if (!$force && $due !== true) {
			return null;
		}
		$setup = new identity_setup(['database' => $database, 'domain_uuid' => '']);
		$result = $setup->sign_all_routes(self::sign_all_time_limit);
		if ($result['remaining'] > 0) {
			identity_schedule::reset('identity_sign_all_routes', self::sign_all_interval, $cache_path);
		}
		if ($result['failed'] > 0) {
			error_log('identity: Sign All Routes could not add signing to '.$result['failed'].' outbound route(s)');
		}
		return $result;
	}

	/**
	 * Give PHP's max_execution_time room past a run's own time limit, so the run stops itself between two routes rather
	 * than being cut off after a route's actions were saved but before its dialplan XML was rebuilt. Only ever extends
	 * the limit, and leaves no limit (the command line) as it is.
	 *
	 * @param int $time_limit Seconds the run may take, 0 for no limit
	 *
	 * @return void
	 */
	private function extend_time_limit(int $time_limit) {
		$max = (int)ini_get('max_execution_time');
		if ($time_limit > 0 && $max > 0) {
			@set_time_limit(max($max, $time_limit + 60));
		}
	}

	/**
	 * Get a LIKE pattern that matches text containing the search, with the search's own %, _ and \ matched literally.
	 *
	 * @param string $search Text to search for, already lower case when the column is
	 *
	 * @return string The pattern, for a bound parameter
	 */
	public static function like_pattern(string $search): string {
		return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
	}

	/**
	 * Create a button that opens a page, like button::create() with a link, with the label also on the link.
	 *
	 * button::create() wraps the button in a link that has no accessible name of its own, so with icon only buttons a
	 * screen reader announces an unlabeled link. Margin styles are not supported, the button is wrapped as it is.
	 *
	 * @param array $button Options for button::create(), with keys label and link
	 *
	 * @return string HTML of the link and button
	 */
	public static function link_button(array $button): string {
		$link = (string)($button['link'] ?? '');
		unset($button['link']);
		return "<a href='".escape($link)."' target='_self' aria-label=\"".escape($button['label'] ?? '')."\">".button::create($button)."</a>";
	}

	/**
	 * Show the result of Add or Add All on the routes page as messages.
	 *
	 * @param array $result Counts from sign_routes() or sign_unsigned_routes(), with keys: added, failed, skipped, and
	 *                      remaining for Add All
	 * @param array $text   Translations of the page
	 *
	 * @return void
	 */
	public static function signing_messages(array $result, array $text) {
		if ($result['added'] > 0) {
			message::add($text['message-signing_added_all'].' '.$result['added']);
		}
		if ($result['failed'] > 0) {
			message::add($text['message-signing_failed_all'].' '.$result['failed'], 'negative', 5000);
		}
		if ($result['skipped'] > 0) {
			message::add($text['message-signing_skipped_all'].' '.$result['skipped'], 'negative', 5000);
		}
		if (($result['remaining'] ?? 0) > 0) {
			message::add($text['message-signing_remaining_all'].' '.$result['remaining'], 'alert', 5000);
		}
	}

	/**
	 * Show the result of a run of Sign All Routes as messages.
	 *
	 * @param array|null $result Counts from sign_all_routes(), or null if it did not run
	 * @param array      $text   Translations of the page
	 *
	 * @return void
	 */
	public static function sign_all_messages(?array $result, array $text) {
		if (empty($result)) {
			return;
		}
		if ($result['added'] > 0) {
			message::add($text['message-signing_added_all'].' '.$result['added']);
		}
		if ($result['failed'] > 0) {
			message::add($text['message-sign_all_failed'].' '.$result['failed'], 'negative', 5000);
		}
		if ($result['remaining'] > 0) {
			message::add($text['message-sign_all_remaining'].' '.$result['remaining'], 'alert', 5000);
		}
	}

	/**
	 * Check whether the exclusions table exists, once per object. It is added by the upgrade.
	 *
	 * @return bool True if v_identity_route_exclusions exists
	 */
	private function has_exclusions_table(): bool {
		if ($this->exclusions_table === null) {
			$this->exclusions_table = (bool)$this->database->table_exists('v_identity_route_exclusions');
		}
		return $this->exclusions_table;
	}

	/**
	 * Take the advisory lock of a route, waiting while another change to its signing holds it. The lock belongs to
	 * the database connection, so it is released if the request ends without unlock_route().
	 *
	 * @param string $dialplan_uuid Outbound route dialplan UUID
	 *
	 * @return void
	 */
	private function lock_route(string $dialplan_uuid) {
		$sql = "select pg_advisory_lock(".self::route_lock.", hashtext(:dialplan_uuid)) ";
		$parameters['dialplan_uuid'] = $dialplan_uuid;
		$this->database->select($sql, $parameters, 'column');
		unset($sql, $parameters);
	}

	/**
	 * Release the advisory lock of a route taken by lock_route().
	 *
	 * @param string $dialplan_uuid Outbound route dialplan UUID
	 *
	 * @return void
	 */
	private function unlock_route(string $dialplan_uuid) {
		$sql = "select pg_advisory_unlock(".self::route_lock.", hashtext(:dialplan_uuid)) ";
		$parameters['dialplan_uuid'] = $dialplan_uuid;
		$this->database->select($sql, $parameters, 'column');
		unset($sql, $parameters);
	}

	/**
	 * Check whether a route has a signing action.
	 *
	 * @param string $dialplan_uuid Outbound route dialplan UUID
	 *
	 * @return bool True if the route signs calls
	 */
	private function route_signs(string $dialplan_uuid): bool {
		$sql = "select count(*) from v_dialplan_details ";
		$sql .= "where dialplan_uuid = :dialplan_uuid ";
		$sql .= "and dialplan_detail_tag = 'action' ";
		$sql .= "and dialplan_detail_type = 'curl' ";
		$sql .= "and dialplan_detail_data like :sign_url ";
		$parameters['dialplan_uuid'] = $dialplan_uuid;
		$parameters['sign_url'] = '%/app/identity/sign.php%';
		$count = $this->database->select($sql, $parameters, 'column');
		unset($sql, $parameters);
		return (int)$count > 0;
	}

	/**
	 * Check whether a route is excluded from Sign All Routes.
	 *
	 * @param string $dialplan_uuid Outbound route dialplan UUID
	 *
	 * @return bool True if the route is excluded, false if not or the upgrade has not added the table
	 */
	private function is_excluded(string $dialplan_uuid): bool {
		if (!$this->has_exclusions_table()) {
			return false;
		}
		$sql = "select count(*) from v_identity_route_exclusions ";
		$sql .= "where dialplan_uuid = :dialplan_uuid ";
		$parameters['dialplan_uuid'] = $dialplan_uuid;
		$count = $this->database->select($sql, $parameters, 'column');
		unset($sql, $parameters);
		return (int)$count > 0;
	}

	/**
	 * Exclude an outbound route from Sign All Routes.
	 *
	 * @param string $dialplan_uuid Outbound route dialplan UUID
	 * @param string $domain_uuid   Domain UUID of the route, empty for a global route
	 *
	 * @return bool True if the route is excluded or the upgrade has not added the table yet, false if the exclusion
	 *              could not be saved
	 */
	private function exclude_route(string $dialplan_uuid, string $domain_uuid): bool {
		if (!$this->has_exclusions_table() || $this->is_excluded($dialplan_uuid)) {
			return true;
		}
		$array['identity_route_exclusions'][0]['identity_route_exclusion_uuid'] = uuid();
		$array['identity_route_exclusions'][0]['domain_uuid'] = is_uuid($domain_uuid) ? $domain_uuid : null;
		$array['identity_route_exclusions'][0]['dialplan_uuid'] = $dialplan_uuid;
		$p = permissions::new();
		$p->add('identity_route_exclusion_add', 'temp');
		$this->database->save($array, false);
		unset($array);
		$p->delete('identity_route_exclusion_add', 'temp');
		return $this->is_excluded($dialplan_uuid);
	}

	/**
	 * Remove the exclusion of an outbound route from Sign All Routes, if it has one.
	 *
	 * @param string $dialplan_uuid Outbound route dialplan UUID
	 *
	 * @return void
	 */
	private function include_route(string $dialplan_uuid) {
		if (!$this->is_excluded($dialplan_uuid)) {
			return;
		}
		$array['identity_route_exclusions'][0]['dialplan_uuid'] = $dialplan_uuid;
		$p = permissions::new();
		$p->add('identity_route_exclusion_delete', 'temp');
		$this->database->delete($array);
		unset($array);
		$p->delete('identity_route_exclusion_delete', 'temp');
	}

	/**
	 * Remove the exclusions of outbound routes that no longer exist.
	 *
	 * @return void
	 */
	private function purge_exclusions() {
		$sql = "select e.identity_route_exclusion_uuid from v_identity_route_exclusions as e ";
		$sql .= "where not exists (select 1 from v_dialplans as d where d.dialplan_uuid = e.dialplan_uuid and d.app_uuid = :app_uuid) ";
		$parameters['app_uuid'] = self::outbound_route_app_uuid;
		$rows = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);
		if (!is_array($rows) || @sizeof($rows) == 0) {
			return;
		}
		foreach ($rows as $x => $row) {
			$array['identity_route_exclusions'][$x]['identity_route_exclusion_uuid'] = $row['identity_route_exclusion_uuid'];
		}
		$p = permissions::new();
		$p->add('identity_route_exclusion_delete', 'temp');
		$this->database->delete($array);
		unset($array);
		$p->delete('identity_route_exclusion_delete', 'temp');
	}

	/**
	 * Remove the exclusions of routes that sign, such as a route whose signing was added back in the dialplan editor
	 * after Remove. Each route is locked and checked again, since Remove saves the exclusion before it deletes the
	 * actions.
	 *
	 * @return void
	 */
	private function include_signed_routes() {
		$sql = "select e.dialplan_uuid from v_identity_route_exclusions as e ";
		$sql .= "where exists (select 1 from v_dialplan_details as s where s.dialplan_uuid = e.dialplan_uuid and s.dialplan_detail_tag = 'action' and s.dialplan_detail_type = 'curl' and s.dialplan_detail_data like :sign_url) ";
		$parameters['sign_url'] = '%/app/identity/sign.php%';
		$rows = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);
		if (!is_array($rows)) {
			return;
		}
		foreach ($rows as $row) {
			$this->lock_route($row['dialplan_uuid']);
			try {
				if ($this->route_signs($row['dialplan_uuid'])) {
					$this->include_route($row['dialplan_uuid']);
				}
			}
			finally {
				$this->unlock_route($row['dialplan_uuid']);
			}
		}
	}

	/**
	 * URL encode the caller controlled values of a signing action.
	 *
	 * Wraps the source, destination and call_reason values of the POST data in ${url_encode(...)} unless they already
	 * are, whatever their order, so neither the caller ID nor the dialed digits nor the call reason can add parameters
	 * to the sign.php request. FreeSWITCH substitutes the route captures ($1) before the url_encode API runs. Adds
	 * domain_uuid=${domain_uuid} when it is missing, so the attestation check knows the domain of the call.
	 *
	 * @param string $data Data of the curl action, "<url> POST <parameters>"
	 *
	 * @return string The data with the values encoded
	 */
	public static function encode_signing_data(string $data): string {
		$position = strpos($data, ' POST ');
		if ($position === false) {
			return $data;
		}
		$parameters = explode('&', substr($data, $position + 6));
		foreach ($parameters as $i => $parameter) {
			[$name, $value] = array_pad(explode('=', $parameter, 2), 2, null);
			if ($value === null || $value === '' || !in_array($name, self::encoded_parameters, true) || strpos($value, '${url_encode(') === 0) {
				continue;
			}
			$parameters[$i] = $name.'=${url_encode('.$value.')}';
		}
		$names = array_map(function($parameter) { return explode('=', $parameter, 2)[0]; }, $parameters);
		if (!in_array('domain_uuid', $names, true)) {
			$parameters[] = 'domain_uuid=${domain_uuid}';
		}
		return substr($data, 0, $position + 6).implode('&', $parameters);
	}

	/**
	 * Update the signing actions added by earlier versions or by hand.
	 *
	 * URL encodes the caller controlled values, which earlier versions sent without encoding so either could add
	 * parameters to the sign.php request, and adds the steps around the request (see prepare_curl()). Runs on upgrade
	 * for the routes of every domain and rebuilds the XML of each route changed.
	 *
	 * @return int Number of routes updated
	 */
	public function upgrade_signing(): int {
		return $this->upgrade_actions('%/app/identity/sign.php%', self::sign_timeout, function (string $data): string {
			return self::encode_signing_data($data);
		});
	}

	/**
	 * Update the curl actions to sign.php or verify.php, and add the steps around each request.
	 *
	 * @param string   $url_pattern LIKE pattern of the curl data, such as %/app/identity/sign.php%
	 * @param int      $timeout     Seconds the request may take
	 * @param callable $update      Returns the new data of a curl action from its current data
	 *
	 * @return int Number of dialplans updated
	 */
	private function upgrade_actions(string $url_pattern, int $timeout, callable $update): int {
		//find the actions
		$sql = "select d.dialplan_detail_uuid, d.dialplan_uuid, d.dialplan_detail_data, p.dialplan_context ";
		$sql .= "from v_dialplan_details as d ";
		$sql .= "inner join v_dialplans as p on p.dialplan_uuid = d.dialplan_uuid ";
		$sql .= "where d.dialplan_detail_tag = 'action' ";
		$sql .= "and d.dialplan_detail_type = 'curl' ";
		$sql .= "and d.dialplan_detail_data like :url ";
		$parameters['url'] = $url_pattern;
		$rows = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);
		if (!is_array($rows)) {
			return 0;
		}

		//update the data
		$x = 0;
		$array = [];
		$dialplans = [];
		foreach ($rows as $row) {
			$data = $update($row['dialplan_detail_data']);
			if ($data === $row['dialplan_detail_data']) {
				continue;
			}
			$array['dialplan_details'][$x]['dialplan_detail_uuid'] = $row['dialplan_detail_uuid'];
			$array['dialplan_details'][$x]['dialplan_uuid'] = $row['dialplan_uuid'];
			$array['dialplan_details'][$x]['dialplan_detail_data'] = $data;
			$dialplans[$row['dialplan_uuid']] = $row['dialplan_context'];
			$x++;
		}
		if (!empty($array)) {
			$p = permissions::new();
			$p->add('dialplan_detail_edit', 'temp');
			$this->database->save($array, false);
			unset($array);
			$p->delete('dialplan_detail_edit', 'temp');
		}

		//add the steps around each request
		foreach ($rows as $row) {
			if ($this->prepare_curl($row['dialplan_uuid'], $row['dialplan_detail_uuid'], $timeout)) {
				$dialplans[$row['dialplan_uuid']] = $row['dialplan_context'];
			}
		}

		//rebuild the dialplan xml and clear the cache
		foreach ($dialplans as $dialplan_uuid => $dialplan_context) {
			$this->update_dialplan($dialplan_uuid, $dialplan_context);
		}

		return count($dialplans);
	}

	/**
	 * Add the steps around a curl action to sign.php or verify.php that are missing.
	 *
	 * Before the request curl_response_data is cleared, because mod_curl does not change it when a request fails or
	 * times out, so the next action would otherwise use the response of an earlier curl action on the same channel,
	 * and could send it as the Identity header. An unset sip_h_Identity action just before the request is changed to
	 * multiunset sip_h_Identity curl_response_data; otherwise unset curl_response_data is added. Then curl_timeout is
	 * set before the request and unset after it, so the timeout does not apply to later requests of the call. The
	 * actions of the group are renumbered when steps are added.
	 *
	 * @param string $dialplan_uuid    Dialplan UUID
	 * @param string $curl_detail_uuid Dialplan detail UUID of the curl action
	 * @param int    $timeout          Seconds the request may take
	 *
	 * @return bool True if the dialplan was changed
	 */
	private function prepare_curl(string $dialplan_uuid, string $curl_detail_uuid, int $timeout): bool {
		//get the details of the dialplan
		$sql = "select d.dialplan_detail_uuid, d.dialplan_detail_tag, d.dialplan_detail_type, d.dialplan_detail_data, ";
		$sql .= "d.dialplan_detail_group, d.dialplan_detail_order, p.domain_uuid ";
		$sql .= "from v_dialplan_details as d ";
		$sql .= "inner join v_dialplans as p on p.dialplan_uuid = d.dialplan_uuid ";
		$sql .= "where d.dialplan_uuid = :dialplan_uuid ";
		$sql .= "order by d.dialplan_detail_group asc, d.dialplan_detail_order asc ";
		$parameters['dialplan_uuid'] = $dialplan_uuid;
		$details = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);
		if (!is_array($details)) {
			return false;
		}

		//the actions of the group of the request, before and after it
		$curl = null;
		foreach ($details as $detail) {
			if ($detail['dialplan_detail_uuid'] === $curl_detail_uuid) {
				$curl = $detail;
			}
		}
		if ($curl === null) {
			return false;
		}
		$group = array_values(array_filter($details, function($detail) use ($curl) {
			return $detail['dialplan_detail_group'] == $curl['dialplan_detail_group'];
		}));
		$position = array_search($curl_detail_uuid, array_column($group, 'dialplan_detail_uuid'), true);
		$is_action = function($detail, $type, $pattern) {
			return $detail['dialplan_detail_tag'] === 'action' && $detail['dialplan_detail_type'] === $type && preg_match($pattern, trim($detail['dialplan_detail_data']));
		};
		$cleared = false;
		$timeout_set = false;
		$last_action = null;
		foreach (array_slice($group, 0, $position) as $detail) {
			$cleared = $cleared || $is_action($detail, 'unset', '/^curl_response_data$/') || $is_action($detail, 'multiunset', '/(^|[\s:])curl_response_data($|[\s:])/');
			$timeout_set = $timeout_set || $is_action($detail, 'set', '/^curl_timeout=/');
			if ($detail['dialplan_detail_tag'] === 'action') {
				$last_action = $detail;
			}
		}
		$timeout_unset = false;
		foreach (array_slice($group, $position + 1) as $detail) {
			$timeout_unset = $timeout_unset || $is_action($detail, 'unset', '/^curl_timeout$/');
		}
		if ($cleared && $timeout_set && $timeout_unset) {
			return false;
		}

		//change the unset sip_h_Identity action just before the request to also clear the response
		$x = 0;
		$array = [];
		if (!$cleared && $last_action !== null && $is_action($last_action, 'unset', '/^sip_h_Identity$/')) {
			$array['dialplan_details'][$x]['dialplan_detail_uuid'] = $last_action['dialplan_detail_uuid'];
			$array['dialplan_details'][$x]['dialplan_uuid'] = $dialplan_uuid;
			$array['dialplan_details'][$x]['dialplan_detail_type'] = 'multiunset';
			$array['dialplan_details'][$x]['dialplan_detail_data'] = 'sip_h_Identity curl_response_data';
			$x++;
			$cleared = true;
		}

		//add the missing steps and renumber the group
		$order = 0;
		$add = function($type, $data) use (&$array, &$x, &$order, $curl, $dialplan_uuid) {
			$order += 10;
			$array['dialplan_details'][$x]['dialplan_detail_uuid'] = uuid();
			$array['dialplan_details'][$x]['domain_uuid'] = $curl['domain_uuid'];
			$array['dialplan_details'][$x]['dialplan_uuid'] = $dialplan_uuid;
			$array['dialplan_details'][$x]['dialplan_detail_tag'] = 'action';
			$array['dialplan_details'][$x]['dialplan_detail_type'] = $type;
			$array['dialplan_details'][$x]['dialplan_detail_data'] = $data;
			$array['dialplan_details'][$x]['dialplan_detail_group'] = $curl['dialplan_detail_group'];
			$array['dialplan_details'][$x]['dialplan_detail_order'] = $order;
			$array['dialplan_details'][$x]['dialplan_detail_enabled'] = true;
			$x++;
		};
		foreach ($group as $i => $detail) {
			if ($i === $position) {
				if (!$cleared) {
					$add('unset', 'curl_response_data');
				}
				if (!$timeout_set) {
					$add('set', 'curl_timeout='.$timeout);
				}
			}
			$order += 10;
			if ($detail['dialplan_detail_order'] != $order) {
				$array['dialplan_details'][$x]['dialplan_detail_uuid'] = $detail['dialplan_detail_uuid'];
				$array['dialplan_details'][$x]['dialplan_uuid'] = $dialplan_uuid;
				$array['dialplan_details'][$x]['dialplan_detail_order'] = $order;
				$x++;
			}
			if ($i === $position && !$timeout_unset) {
				$add('unset', 'curl_timeout');
			}
		}

		//save the actions
		$p = permissions::new();
		$p->add('dialplan_detail_add', 'temp');
		$p->add('dialplan_detail_edit', 'temp');
		$this->database->save($array, false);
		unset($array);
		$p->delete('dialplan_detail_add', 'temp');
		$p->delete('dialplan_detail_edit', 'temp');
		return true;
	}

	/**
	 * Add the call's numbers to the data of a verification action that does not send them, and URL encode the caller
	 * controlled values.
	 *
	 * The identity, source and destination values are wrapped in ${url_encode(...)} unless they already are, so the
	 * Identity header or the caller ID cannot add parameters to the request, and a missing source or destination is
	 * added.
	 *
	 * @param string $data Data of the curl action, "<url> POST <parameters>"
	 *
	 * @return string The data with every parameter of verify_parameters, encoded
	 */
	public static function add_verify_parameters(string $data): string {
		$position = strpos($data, ' POST ');
		if ($position === false) {
			return $data;
		}
		$parameters = explode('&', substr($data, $position + 6));
		$names = [];
		foreach ($parameters as $i => $parameter) {
			[$name, $value] = array_pad(explode('=', $parameter, 2), 2, null);
			$names[] = $name;
			if ($value === null || $value === '' || !in_array($name, ['identity', 'source', 'destination'], true) || strpos($value, '${url_encode(') === 0) {
				continue;
			}
			$parameters[$i] = $name.'=${url_encode('.$value.')}';
		}
		foreach (self::verify_parameters as $name => $value) {
			if (!in_array($name, $names, true)) {
				$parameters[] = $name.'='.$value;
			}
		}
		return substr($data, 0, $position + 6).implode('&', $parameters);
	}

	/**
	 * Update the verification actions added by earlier versions or by hand.
	 *
	 * Adds the call's numbers, which earlier versions did not send, so verify.php could not check that a PASSporT was
	 * signed for the call and returns No-TN-Validation without them, URL encodes the caller controlled values, and adds
	 * the steps around the request (see prepare_curl()). Runs on upgrade and rebuilds the XML of each dialplan changed.
	 *
	 * @return int Number of dialplans updated
	 */
	public function upgrade_verification(): int {
		return $this->upgrade_actions('%/app/identity/verify.php%', self::verify_timeout, function (string $data): string {
			return self::add_verify_parameters($data);
		});
	}

	/**
	 * Get the dialplan that marks a call coming in from outside.
	 *
	 * @return array dialplan_uuid, dialplan_enabled and number_exported, or an empty array if it does not exist
	 */
	public function origin_dialplan(): array {
		$sql = "select dialplan_uuid, cast(dialplan_enabled as text) as dialplan_enabled, ";
		$sql .= "(select count(*) from v_dialplan_details as d where d.dialplan_uuid = p.dialplan_uuid and d.dialplan_detail_data like 'identity_origin_number=%' and coalesce(cast(d.dialplan_detail_enabled as text), 'true') = 'true') as number_exported ";
		$sql .= "from v_dialplans as p where app_uuid = :app_uuid ";
		$sql .= "order by p.insert_date asc limit 1 ";
		$parameters['app_uuid'] = self::origin_app_uuid;
		$row = $this->database->select($sql, $parameters, 'row');
		unset($sql, $parameters);
		return is_array($row) ? $row : [];
	}

	/**
	 * Adds the outside caller's number to the origin dialplan an earlier version made, which marked only that the
	 * call came in, as the core import does not change a dialplan it made before.
	 *
	 * @return bool True when the dialplan was changed
	 */
	public function upgrade_origin(): bool {
		$dialplan = $this->origin_dialplan();
		if (empty($dialplan) || (int)$dialplan['number_exported'] > 0) {
			return false;
		}
		$array['dialplan_details'][0]['dialplan_detail_uuid'] = uuid();
		$array['dialplan_details'][0]['dialplan_uuid'] = $dialplan['dialplan_uuid'];
		$array['dialplan_details'][0]['domain_uuid'] = null;
		$array['dialplan_details'][0]['dialplan_detail_tag'] = 'action';
		$array['dialplan_details'][0]['dialplan_detail_type'] = 'export';
		$array['dialplan_details'][0]['dialplan_detail_data'] = 'identity_origin_number=${caller_id_number}';
		$array['dialplan_details'][0]['dialplan_detail_inline'] = 'true';
		$array['dialplan_details'][0]['dialplan_detail_group'] = 0;
		$array['dialplan_details'][0]['dialplan_detail_order'] = 20;
		$array['dialplan_details'][0]['dialplan_detail_enabled'] = 'true';
		$p = permissions::new();
		$p->add('dialplan_detail_add', 'temp');
		$this->database->save($array);
		$p->delete('dialplan_detail_add', 'temp');
		$this->update_dialplan($dialplan['dialplan_uuid'], 'public');
		return true;
	}

	/**
	 * Get the inbound verification dialplan.
	 *
	 * @return array Array with keys: dialplan_uuid, dialplan_enabled, or an empty array if it does not exist
	 */
	public function verify_dialplan(): array {
		$sql = "select dialplan_uuid, cast(dialplan_enabled as text) as dialplan_enabled from v_dialplans ";
		$sql .= "where app_uuid = :app_uuid ";
		$parameters['app_uuid'] = self::verify_app_uuid;
		$row = $this->database->select($sql, $parameters, 'row');
		unset($sql, $parameters);
		return is_array($row) ? $row : [];
	}

	/**
	 * Add the inbound verification dialplan.
	 *
	 * Creates a global dialplan in the public context that sends the Identity header of each inbound call to
	 * verify.php and stores the result in the identity_verstat channel variable.
	 *
	 * @return bool True if the dialplan was added
	 */
	public function add_verify_dialplan(): bool {
		//stop if it already exists
		if (!empty($this->verify_dialplan())) {
			return false;
		}

		//build the array
		$dialplan_uuid = uuid();
		$array['dialplans'][0]['dialplan_uuid'] = $dialplan_uuid;
		$array['dialplans'][0]['domain_uuid'] = null;
		$array['dialplans'][0]['app_uuid'] = self::verify_app_uuid;
		$array['dialplans'][0]['dialplan_name'] = 'identity-verify';
		$array['dialplans'][0]['dialplan_context'] = 'public';
		$array['dialplans'][0]['dialplan_continue'] = 'true';
		$array['dialplans'][0]['dialplan_order'] = 20;
		$array['dialplans'][0]['dialplan_enabled'] = true;
		$array['dialplans'][0]['dialplan_description'] = 'Verify the STIR/SHAKEN Identity header of inbound calls.';
		$details = [
			['condition', '${sip_identity}', '^.+$'],
			['action', 'unset', 'curl_response_data'],
			['action', 'set', 'curl_timeout='.self::verify_timeout],
			['action', 'curl', self::add_verify_parameters(self::local_url.'/verify.php POST identity=${url_encode(${sip_identity})}&call_uuid=${uuid}')],
			['action', 'unset', 'curl_timeout'],
			['action', 'set', 'identity_verstat=${curl_response_data}'],
		];
		foreach ($details as $y => $detail) {
			$array['dialplans'][0]['dialplan_details'][$y]['dialplan_detail_uuid'] = uuid();
			$array['dialplans'][0]['dialplan_details'][$y]['domain_uuid'] = null;
			$array['dialplans'][0]['dialplan_details'][$y]['dialplan_uuid'] = $dialplan_uuid;
			$array['dialplans'][0]['dialplan_details'][$y]['dialplan_detail_tag'] = $detail[0];
			$array['dialplans'][0]['dialplan_details'][$y]['dialplan_detail_type'] = $detail[1];
			$array['dialplans'][0]['dialplan_details'][$y]['dialplan_detail_data'] = $detail[2];
			$array['dialplans'][0]['dialplan_details'][$y]['dialplan_detail_group'] = 0;
			$array['dialplans'][0]['dialplan_details'][$y]['dialplan_detail_order'] = ($y + 1) * 10;
			$array['dialplans'][0]['dialplan_details'][$y]['dialplan_detail_enabled'] = true;
		}

		//save the dialplan
		$p = permissions::new();
		$p->add('dialplan_add', 'temp');
		$p->add('dialplan_detail_add', 'temp');
		$this->database->save($array);
		unset($array);
		$p->delete('dialplan_add', 'temp');
		$p->delete('dialplan_detail_add', 'temp');

		//rebuild the dialplan xml and clear the cache
		$this->update_dialplan($dialplan_uuid, 'public');

		return true;
	}

	/**
	 * Remove the inbound verification dialplan.
	 *
	 * @return bool True if the dialplan was removed
	 */
	public function remove_verify_dialplan(): bool {
		$dialplan = $this->verify_dialplan();
		if (empty($dialplan)) {
			return false;
		}

		//delete the dialplan and its details
		$array['dialplan_details'][0]['dialplan_uuid'] = $dialplan['dialplan_uuid'];
		$array['dialplans'][0]['dialplan_uuid'] = $dialplan['dialplan_uuid'];
		$p = permissions::new();
		$p->add('dialplan_delete', 'temp');
		$p->add('dialplan_detail_delete', 'temp');
		$this->database->delete($array);
		unset($array);
		$p->delete('dialplan_delete', 'temp');
		$p->delete('dialplan_detail_delete', 'temp');

		//clear the cache
		$cache = new cache;
		self::clear_public_cache($this->database);

		return true;
	}

	/**
	 * Get an outbound route by UUID.
	 *
	 * @param string $dialplan_uuid Dialplan UUID
	 * @param bool   $all           Find a route of any domain instead of only the current domain
	 *
	 * @return array Array with keys: dialplan_uuid, domain_uuid, dialplan_context, or an empty array if not found
	 */
	private function route(string $dialplan_uuid, bool $all = false): array {
		$sql = "select dialplan_uuid, domain_uuid, dialplan_context from v_dialplans ";
		$sql .= "where dialplan_uuid = :dialplan_uuid ";
		$sql .= "and app_uuid = :app_uuid ";
		if (!$all) {
			$sql .= "and domain_uuid = :domain_uuid ";
			$parameters['domain_uuid'] = $this->domain_uuid;
		}
		$parameters['dialplan_uuid'] = $dialplan_uuid;
		$parameters['app_uuid'] = self::outbound_route_app_uuid;
		$row = $this->database->select($sql, $parameters, 'row');
		unset($sql, $parameters);
		return is_array($row) ? $row : [];
	}

	/**
	 * Rebuild the XML of a dialplan from its details and clear the dialplan cache.
	 *
	 * @param string $dialplan_uuid    Dialplan UUID
	 * @param string $dialplan_context Dialplan context
	 *
	 * @return void
	 */
	/**
	 * Clears the cached public dialplan: dialplan:public in multiple dialplan mode, and in single mode the key of each
	 * inbound number, dialplan:public:<number>, which the file cache clears by its pattern and memcache one by one.
	 * Only these keys go, so the replay claims, the certificate and CRL caches and other apps' entries stay.
	 *
	 * @param database $database The database
	 *
	 * @return void
	 */
	public static function clear_public_cache(database $database) {
		$cache = new cache;
		$cache->delete("dialplan:public");
		$cache->delete("dialplan:public:*");
		$sql = "select distinct n from (";
		$sql .= "select destination_number as n from v_destinations ";
		$sql .= "union select coalesce(destination_prefix, '') || destination_number from v_destinations ";
		$sql .= "union select '+' || coalesce(destination_prefix, '') || destination_number from v_destinations ";
		$sql .= "union select coalesce(destination_area_code, '') || destination_number from v_destinations ";
		$sql .= "union select coalesce(destination_prefix, '') || coalesce(destination_area_code, '') || destination_number from v_destinations ";
		$sql .= ") as numbers where n is not null and n <> '' ";
		foreach ($database->select($sql, null, 'all') ?: [] as $row) {
			if (preg_match('/^\+?[0-9*#]+$/D', $row['n'])) {
				$cache->delete("dialplan:public:".$row['n']);
			}
		}
		unset($sql);
	}

	private function update_dialplan(string $dialplan_uuid, string $dialplan_context) {
		//rebuild the dialplan xml
		$dialplans = new dialplan;
		$dialplans->source = "details";
		$dialplans->destination = "database";
		$dialplans->uuid = $dialplan_uuid;
		$dialplans->xml();

		//clear the cache, of every domain for a global dialplan, as the dialplan editor does
		if ($dialplan_context == '${domain_name}' || $dialplan_context == 'global') {
			$dialplan_context = '*';
		}
		if ($dialplan_context == 'public') {
			self::clear_public_cache($this->database);
		}
		else {
			$cache = new cache;
			$cache->delete("dialplan:".$dialplan_context);
		}

		//set the reload flag
		$_SESSION["reload_xml"] = true;
	}

}
