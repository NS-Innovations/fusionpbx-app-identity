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

//define the identity numbers class
class identity_numbers {

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
	 * Initializes the object with setting array.
	 *
	 * @param array $setting_array An array containing the database object. Defaults to an empty array.
	 *
	 * @return void
	 */
	public function __construct(array $setting_array = []) {
		$this->database = $setting_array['database'] ?? database::new();
	}

	/**
	 * Check whether a telephone number belongs to a domain.
	 *
	 * A number belongs to the domain when it is an enabled inbound destination of that domain. The destination is
	 * compared with its prefix and area code, with its prefix, and on its own, each normalized the same way as signed
	 * numbers, so 2025550100 with prefix 1 matches the calling number 12025550100 or +12025550100.
	 *
	 * @param string $tn          Calling number, normalized with identity::normalize_tn()
	 * @param string $domain_uuid Domain UUID
	 *
	 * @return bool True if the number is a destination of the domain
	 */
	public function owns(string $tn, string $domain_uuid): bool {
		if (!is_uuid($domain_uuid) || strlen($tn) < 7 || !ctype_digit($tn)) {
			return false;
		}

		//get the destinations that end with the same digits
		$sql = "select destination_prefix, destination_area_code, destination_number ";
		$sql .= "from v_destinations ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and destination_type = 'inbound' ";
		$sql .= "and destination_enabled = true ";
		$sql .= "and destination_number like :suffix ";
		$parameters['domain_uuid'] = $domain_uuid;
		$parameters['suffix'] = '%'.substr($tn, -7);
		$rows = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);
		if (!is_array($rows)) {
			return false;
		}

		//compare each form of the destination with the calling number
		$identity = new identity();
		foreach ($rows as $row) {
			$prefix = (string)($row['destination_prefix'] ?? '');
			$area_code = (string)($row['destination_area_code'] ?? '');
			$number = (string)($row['destination_number'] ?? '');
			foreach ([$prefix.$area_code.$number, $prefix.$number, $area_code.$number, $number] as $candidate) {
				if ($candidate !== '' && $identity->normalize_tn($candidate) === $tn) {
					return true;
				}
			}
		}
		return false;
	}

}
