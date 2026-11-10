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

//define the identity logs class
class identity_logs {

	/**
	 * declare constant variables
	 */
	const app_name = 'identity';
	const app_uuid = '826aa9a8-1b27-4aad-8b73-368f734ef6a2';

	/**
	 * Set in the constructor. Must be a database object and cannot be null.
	 *
	 * @var database Database Object
	 */
	private $database;

	/**
	 * Domain UUID set in the constructor. This can be passed in through the $setting_array associative array or set
	 * in the session global array
	 *
	 * @var string
	 */
	private $domain_uuid;

	/**
	 * declare private variables
	 */
	private $permission_prefix;
	private $list_page;
	private $table;
	private $uuid_prefix;

	/**
	 * Initializes the object with setting array.
	 *
	 * @param array $setting_array An array containing settings for domain, user, and database connections. Defaults to
	 *                             an empty array.
	 *
	 * @return void
	 */
	public function __construct(array $setting_array = []) {
		//set domain and user UUIDs
		$this->domain_uuid = $setting_array['domain_uuid'] ?? $_SESSION['domain_uuid'] ?? '';

		//set objects
		$this->database = $setting_array['database'] ?? database::new();

		//assign private variables
		$this->permission_prefix = 'identity_log_';
		$this->list_page = 'identity_logs.php';
		$this->table = 'identity_logs';
		$this->uuid_prefix = 'identity_log_';
	}

	/**
	 * Add a sign or verify result to the log.
	 *
	 * Values that do not match the expected format are discarded. Called by sign.php and verify.php without a
	 * session, so the add permission is granted temporarily. Failures are ignored so that logging never affects
	 * the call.
	 *
	 * @param array $log Associative array of v_identity_logs field names and values
	 *
	 * @return bool True if the log was saved, false otherwise
	 */
	public function add(array $log): bool {
		try {
			//validate the values
			$type = $log['identity_log_type'] ?? '';
			$status = $log['identity_log_status'] ?? '';
			if (!in_array($type, ['sign', 'verify'], true) || !in_array($status, ['signed', 'passed', 'failed'], true)) {
				return false;
			}
			$attest = $log['identity_log_attest'] ?? '';
			$iat = $log['identity_log_iat'] ?? '';

			//build the array
			$array[$this->table][0]['domain_uuid'] = is_uuid($log['domain_uuid'] ?? '') ? $log['domain_uuid'] : null;
			$array[$this->table][0]['identity_log_call_uuid'] = is_uuid($log['identity_log_call_uuid'] ?? '') ? $log['identity_log_call_uuid'] : null;
			$array[$this->table][0]['identity_log_type'] = $type;
			$array[$this->table][0]['identity_log_status'] = $status;
			$array[$this->table][0]['identity_log_attest'] = in_array($attest, ['A', 'B', 'C'], true) ? $attest : null;
			$array[$this->table][0]['identity_log_source'] = substr($log['identity_log_source'] ?? '', 0, 32);
			$array[$this->table][0]['identity_log_destination'] = substr($log['identity_log_destination'] ?? '', 0, 32);
			$array[$this->table][0]['identity_log_certificate_url'] = substr($log['identity_log_certificate_url'] ?? '', 0, 1024);
			$array[$this->table][0]['identity_log_origid'] = substr($log['identity_log_origid'] ?? '', 0, 64);
			$array[$this->table][0]['identity_log_iat'] = is_numeric($iat) ? (int)$iat : null;
			$array[$this->table][0]['identity_log_brand'] = is_string($log['identity_log_brand'] ?? null) ? mb_substr($log['identity_log_brand'], 0, 64) : null;
			$array[$this->table][0]['identity_log_message'] = substr($log['identity_log_message'] ?? '', 0, 1024);

			//grant temporary permissions
			$p = permissions::new();
			$p->add('identity_log_add', 'temp');

			//save without the transaction log, this runs on every call
			$this->database->save($array, false);
			unset($array);

			//revoke temporary permissions
			$p->delete('identity_log_add', 'temp');

			return ($this->database->message['code'] ?? '') == '200';
		}
		catch (Throwable $e) {
			return false;
		}
	}

	/**
	 * Log a request that sign.php or verify.php refused because its address is not in the Allowed CIDR setting.
	 *
	 * Without it a call is neither signed nor verified and nothing says why. The empty response is sent first so the
	 * call does not wait. At most one refused request of each type is logged a minute, so a client that keeps sending
	 * requests cannot fill the log or the PHP error log. The entry has no domain, because the domain of a refused
	 * request is not trusted, so it is shown to users with identity_log_all.
	 *
	 * @param database $database       Database object
	 * @param string   $type           sign or verify
	 * @param string   $remote_address Address of the client
	 * @param string   $cache_path     Directory of the file that holds the time of the last entry, the cache location
	 *                                 setting
	 *
	 * @return bool True if the request was logged
	 */
	public static function add_refused(database $database, string $type, string $remote_address, string $cache_path = '/var/cache/fusionpbx'): bool {
		//send the empty response before logging
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		}

		//log at most one refused request of each type a minute
		if (!in_array($type, ['sign', 'verify'], true) || identity_schedule::due('identity_'.$type.'_refused', 60, $cache_path) !== true) {
			return false;
		}
		$message = 'Refused: '.substr($remote_address, 0, 64).' is not in the Allowed CIDR setting. At most one refused request is logged a minute.';
		error_log('identity: '.$type.' request '.lcfirst($message));

		//add the log
		$logs = new identity_logs(['database' => $database]);
		return $logs->add([
			'domain_uuid' => null,
			'identity_log_type' => $type,
			'identity_log_status' => 'failed',
			'identity_log_message' => $message,
		]);
	}

	/**
	 * Deletes one or multiple records.
	 *
	 * @param array $records An array of record IDs to delete, where each ID is an associative array
	 *                       containing 'uuid' and 'checked' keys. The 'checked' value indicates
	 *                       whether the corresponding checkbox was checked for deletion.
	 *
	 * @return void No return value; this method modifies the database state and sets a message.
	 */
	public function delete($records) {
		if (permission_exists($this->permission_prefix . 'delete')) {

			//add multi-lingual support
			$language = new text;
			$text = $language->get();

			//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: ' . $this->list_page);
				exit;
			}

			//delete multiple records
			if (is_array($records) && @sizeof($records) != 0) {

				//get the checked uuids
				$uuids = [];
				foreach ($records as $record) {
					if (!empty($record['checked']) && $record['checked'] == 'true' && is_uuid($record['uuid'] ?? '')) {
						$uuids[] = $record['uuid'];
					}
				}

				//without the all permission only the entries of the current domain can be deleted, not those of other
				//domains or the verify entries that have no domain; every uuid passed is_uuid() above
				if (!empty($uuids) && !permission_exists($this->permission_prefix . 'all')) {
					$sql = "select identity_log_uuid from v_identity_logs ";
					$sql .= "where domain_uuid = :domain_uuid ";
					$sql .= "and identity_log_uuid in ('".implode("','", $uuids)."') ";
					$parameters['domain_uuid'] = $this->domain_uuid;
					$rows = is_uuid($this->domain_uuid) ? $this->database->select($sql, $parameters, 'all') : [];
					unset($sql, $parameters);
					$uuids = is_array($rows) ? array_column($rows, 'identity_log_uuid') : [];
				}

				//build the delete array
				$x = 0;
				foreach ($uuids as $uuid) {
					$array[$this->table][$x][$this->uuid_prefix . 'uuid'] = $uuid;
					$x++;
				}

				//delete the checked rows
				if (!empty($array) && is_array($array) && @sizeof($array) != 0) {

					//execute delete
					$this->database->delete($array);
					unset($array);

					//set message
					message::add($text['message-delete']);
				}
				unset($records);
			}
		}
	}

	/**
	 * Seconds between two purges started by purge_if_due().
	 */
	const purge_interval = 3600;

	/**
	 * Chance, one in this many calls, that a purge runs when the time of the last purge cannot be recorded.
	 */
	const purge_fallback_chance = 1000;

	/**
	 * Delete the logs older than the retention period, at most once per purge_interval.
	 *
	 * sign.php and verify.php call this after the response was sent, so the maintenance app is not needed. The time of
	 * the last purge is kept in identity_logs_purge_<uid> in the FusionPBX cache directory (see identity_schedule::due()).
	 * When the file cannot be written, the purge runs on about one call in purge_fallback_chance instead, so the logs
	 * are still limited and the error is logged now and then.
	 *
	 * @param database $database       Database object
	 * @param int      $retention_days Days to keep the logs, 0 keeps them forever
	 * @param string   $cache_path     Directory for the time of the last purge, the cache location setting
	 *
	 * @return bool True if a purge ran and succeeded
	 */
	public static function purge_if_due(database $database, int $retention_days, string $cache_path = '/var/cache/fusionpbx'): bool {
		if ($retention_days <= 0) {
			return false;
		}
		$due = identity_schedule::due('identity_logs_purge', self::purge_interval, $cache_path);
		if ($due === false) {
			return false;
		}
		if ($due === null) {
			if (random_int(1, self::purge_fallback_chance) !== 1) {
				return false;
			}
			error_log('identity: cannot write identity_logs_purge in '.$cache_path.', the identity logs are purged on about one call in '.self::purge_fallback_chance);
		}
		return self::purge($database, $retention_days);
	}

	/**
	 * Delete the logs older than the retention period. Called by the maintenance app when it is installed.
	 *
	 * @param settings $settings Settings object
	 *
	 * @return void
	 */
	public static function database_maintenance(settings $settings): void {
		$database = $settings->database();
		$identity_settings = (new identity_settings(['database' => $database]))->get();
		$retention_days = (int)$identity_settings['database_retention_days'];
		if ($retention_days <= 0) {
			return;
		}
		$purged = self::purge($database, $retention_days);
		if (class_exists('maintenance_service')) {
			if ($purged) {
				maintenance_service::log_write(self::class, "Removed the identity logs older than ".$retention_days." days");
			}
			else {
				maintenance_service::log_write(self::class, "Unable to remove the old identity logs: ".($database->message['message'] ?? 'unknown error'), null, maintenance_service::LOG_ERROR);
			}
		}
	}

	/**
	 * Delete the logs older than the retention period.
	 *
	 * @param database $database       Database object
	 * @param int      $retention_days Days to keep the logs, 0 keeps them forever
	 *
	 * @return bool True if the delete succeeded
	 */
	public static function purge(database $database, int $retention_days): bool {
		if ($retention_days <= 0) {
			return false;
		}
		$sql = "delete from v_identity_logs ";
		$sql .= "where insert_date < :cutoff ";
		$parameters['cutoff'] = date('Y-m-d H:i:sO', time() - $retention_days * 86400);
		$result = $database->execute($sql, $parameters);
		unset($sql, $parameters);
		if ($result === false) {
			error_log('identity: the logs older than '.$retention_days.' days could not be deleted: '.($database->message['message'] ?? ''));
			return false;
		}
		return true;
	}

}
