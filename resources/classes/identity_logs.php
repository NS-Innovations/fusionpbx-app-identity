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

				//build the delete array
				$x = 0;
				foreach ($records as $record) {
					if (!empty($record['checked']) && $record['checked'] == 'true' && is_uuid($record['uuid'])) {
						$array[$this->table][$x][$this->uuid_prefix . 'uuid'] = $record['uuid'];
						$x++;
					}
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

}
