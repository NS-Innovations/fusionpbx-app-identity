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

//define the identity settings class
class identity_settings {

	/**
	 * declare constant variables
	 */
	const app_name = 'identity';
	const app_uuid = '826aa9a8-1b27-4aad-8b73-368f734ef6a2';

	/**
	 * Default setting category of the identity settings
	 */
	const category = 'identity';

	/**
	 * The identity settings: subcategory => default_setting_uuid, type, default value and the config.conf key used
	 * before the settings moved to Default Settings. The UUIDs and values match the default settings in app_config.php.
	 */
	const definitions = [
		'path' => ['uuid' => '7f15081a-cde7-4037-9efd-9ade5e1daed0', 'type' => 'text', 'default' => '/etc/fusionpbx/certs', 'config' => 'identity.path'],
		'private_key_name' => ['uuid' => '5283c349-2227-45dc-b568-73e7720edbbd', 'type' => 'text', 'default' => 'identity_private.key', 'config' => 'identity.private_key_name'],
		'certificate_name' => ['uuid' => '3928a771-8f51-488d-aa06-c60326c07425', 'type' => 'text', 'default' => 'identity_certificate.pem', 'config' => 'identity.certificate_name'],
		'certificate_url' => ['uuid' => '9ccd6f44-0f7d-4734-b1b8-4a322083cfc7', 'type' => 'text', 'default' => '', 'config' => 'identity.certificate_url'],
		'allowed_cidr' => ['uuid' => 'b3566df4-e1ca-42a9-b194-6ec50edf7fbe', 'type' => 'text', 'default' => '127.0.0.1/32', 'config' => 'identity.allowed_cidr'],
		'attestation_check' => ['uuid' => 'db68c2b5-672f-4c14-b211-99ca81b6a96a', 'type' => 'boolean', 'default' => 'true', 'config' => 'identity.attestation_check'],
		'sign_all_routes' => ['uuid' => '609d8fbf-15e3-40b7-ae70-be47ff728d80', 'type' => 'boolean', 'default' => 'false', 'config' => 'identity.sign_all_routes'],
		'debug' => ['uuid' => 'da8e8b9a-9e89-4f96-aa4c-820fdc1e7f36', 'type' => 'boolean', 'default' => 'false', 'config' => 'identity.debug'],
		'database_retention_days' => ['uuid' => 'de87d56a-3e51-44e6-a074-7c6ed836fc81', 'type' => 'numeric', 'default' => '90', 'config' => 'identity.database_retention_days'],
		'trust_list' => ['uuid' => 'fc775706-f3e0-49ba-92c0-4161ec7150bc', 'type' => 'text', 'default' => '', 'config' => 'identity.trust_list'],
		'crl_url' => ['uuid' => '78f40f36-5899-447f-9f2b-f58f1d4f35e3', 'type' => 'text', 'default' => 'https://authenticate-api.iconectiv.com/download/v1/crl', 'config' => 'identity.crl_url'],
		'brands_file' => ['uuid' => '8dcda27b-415e-4f4b-a425-533d78c67c71', 'type' => 'text', 'default' => '', 'config' => 'identity.brands_file'],
		'bcid_private_key_name' => ['uuid' => '9c5845a3-78ec-4b48-a19e-a38fd399a7f1', 'type' => 'text', 'default' => 'bcid_private.key', 'config' => 'identity.bcid.private_key_name'],
		'bcid_certificate_name' => ['uuid' => '6405b1c6-2150-4d57-be5a-39832e812812', 'type' => 'text', 'default' => 'bcid_certificate.pem', 'config' => 'identity.bcid.certificate_name'],
		'bcid_certificate_url' => ['uuid' => 'ecd4acff-cbfd-43f8-8385-e3b7063070b5', 'type' => 'text', 'default' => '', 'config' => 'identity.bcid.certificate_url'],
		'bcid_report_log' => ['uuid' => 'fa85590d-2c53-47c2-b1d0-e7b24c51d627', 'type' => 'text', 'default' => '', 'config' => 'identity.bcid.report_log'],
	];

	/**
	 * Set in the constructor. Must be a database object and cannot be null.
	 *
	 * @var database Database Object
	 */
	private $database;

	/**
	 * Config object set in the constructor, used to read the settings of a server that has not been upgraded yet.
	 *
	 * @var config Config Object
	 */
	private $config;

	/**
	 * Initializes the object with setting array.
	 *
	 * @param array $setting_array An array containing the database and config objects. Defaults to an empty array.
	 *
	 * @return void
	 */
	public function __construct(array $setting_array = []) {
		$this->database = $setting_array['database'] ?? database::new();
		$this->config = $setting_array['config'] ?? config::load();
	}

	/**
	 * Get the identity settings.
	 *
	 * The settings are system wide, so only the default settings are used, never domain or user settings. A setting
	 * that is disabled or empty uses its default. Until the upgrade has added the identity default settings, the
	 * values are read from config.conf so signing keeps working between updating the code and running the upgrade.
	 *
	 * @return array The value of each subcategory in definitions (debug is a bool), private_key_path,
	 *               certificate_path, bcid_private_key_path, bcid_certificate_path, and source: settings or config
	 */
	public function get(): array {
		$settings = new settings(['database' => $this->database, 'category' => self::category]);
		$source = $settings->get(self::category) === null ? 'config' : 'settings';

		$values = [];
		foreach (self::definitions as $name => $definition) {
			if ($source == 'config') {
				$value = $this->config->get($definition['config'], $definition['default']);
			}
			else {
				$value = $settings->get(self::category, $name, $definition['default']);
			}
			if ($definition['type'] == 'boolean') {
				$value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
			}
			$values[$name] = is_bool($value) ? $value : trim((string)$value);
		}
		$values['private_key_path'] = rtrim($values['path'], '/').'/'.$values['private_key_name'];
		$values['certificate_path'] = rtrim($values['path'], '/').'/'.$values['certificate_name'];
		$values['bcid_private_key_path'] = rtrim($values['path'], '/').'/'.$values['bcid_private_key_name'];
		$values['bcid_certificate_path'] = rtrim($values['path'], '/').'/'.$values['bcid_certificate_name'];
		$values['source'] = $source;
		return $values;
	}

	/**
	 * Validate the settings before they are saved.
	 *
	 * @param array $values Values by subcategory, as strings
	 *
	 * @return array The subcategories with an invalid value
	 */
	public function validate(array $values): array {
		$invalid = [];
		foreach (self::definitions as $name => $definition) {
			$value = trim((string)($values[$name] ?? ''));
			switch ($name) {
				case 'path':
					$valid = self::is_path($value);
					break;
				case 'private_key_name':
				case 'certificate_name':
				case 'bcid_private_key_name':
				case 'bcid_certificate_name':
					$valid = (bool)preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]{0,254}$/', $value);
					break;
				case 'certificate_url':
				case 'bcid_certificate_url':
				case 'crl_url':
					$valid = $value === '' || self::is_url($value);
					break;
				case 'allowed_cidr':
					$valid = $value !== '' && empty(self::invalid_cidr($value));
					break;
				case 'database_retention_days':
					$valid = ctype_digit($value) && strlen($value) <= 5;
					break;
				case 'attestation_check':
				case 'sign_all_routes':
				case 'debug':
					$valid = in_array($value, ['true', 'false'], true);
					break;
				case 'trust_list':
				case 'brands_file':
				case 'bcid_report_log':
					$valid = $value === '' || self::is_path($value);
					break;
				default:
					$valid = false;
			}
			if (!$valid) {
				$invalid[] = $name;
			}
		}
		return $invalid;
	}

	/**
	 * Save the settings to Default Settings.
	 *
	 * Updates every default setting of each subcategory, so a setting that was disabled in Default Settings is
	 * enabled with the new value. A missing setting (the upgrade has not run yet) is added first with its default
	 * value and the UUID from app_config.php, the same as the upgrade would, then updated like the others.
	 *
	 * @param array $values Values by subcategory, as strings, already checked with validate()
	 *
	 * @return bool True if the settings were saved
	 */
	public function save(array $values): bool {
		$p = permissions::new();
		$p->add('default_setting_add', 'temp');
		$p->add('default_setting_edit', 'temp');

		//add the missing settings
		$rows = $this->rows();
		$x = 0;
		$array = [];
		foreach (self::definitions as $name => $definition) {
			if (empty($rows[$name])) {
				$array['default_settings'][$x] = $this->new_row($name, $definition['default']);
				$x++;
			}
		}
		if (!empty($array)) {
			$this->database->save($array);
			unset($array);
			$rows = $this->rows();
		}

		//update the settings
		$x = 0;
		$array = [];
		foreach (self::definitions as $name => $definition) {
			foreach ($rows[$name] ?? [] as $row) {
				$array['default_settings'][$x]['default_setting_uuid'] = $row['default_setting_uuid'];
				$array['default_settings'][$x]['default_setting_value'] = trim((string)($values[$name] ?? ''));
				$array['default_settings'][$x]['default_setting_enabled'] = true;
				$x++;
			}
		}
		//saved when every row was updated (200) or left as it was (000, no changes); with no changes at all the
		//database class sets no overall code, so the codes of the rows are checked
		$result = $this->database->save($array);
		$codes = array_column($this->database->message['details'] ?? [], 'code');
		$saved = $result !== false && !empty($codes) && empty(array_diff($codes, ['000', '200']));
		$saved = $saved && count(array_intersect_key($rows, self::definitions)) == count(self::definitions);
		unset($array);
		$p->delete('default_setting_add', 'temp');
		$p->delete('default_setting_edit', 'temp');

		//clear the cached settings
		settings::clear_cache();

		return $saved;
	}

	/**
	 * Copy the identity settings from config.conf to Default Settings.
	 *
	 * Runs on upgrade after the default settings from app_config.php were added. Nothing is copied once any identity
	 * setting has been changed, by an earlier migration or in the web interface, so an old config.conf value never
	 * replaces a saved setting. A setting is also only copied while it still has its default value.
	 *
	 * @return array The config.conf keys that were copied
	 */
	public function migrate(): array {
		$rows = $this->rows();
		foreach ($rows as $subcategory_rows) {
			foreach ($subcategory_rows as $row) {
				if (!empty($row['update_date'])) {
					return [];
				}
			}
		}
		$copied = [];
		$x = 0;
		$array = [];
		foreach (self::definitions as $name => $definition) {
			$value = trim((string)$this->config->get($definition['config'], ''));
			if ($value === '' || count($rows[$name] ?? []) != 1) {
				continue;
			}
			if ($definition['type'] == 'boolean') {
				$value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
			}
			$row = $rows[$name][0];
			if ((string)$row['default_setting_value'] !== $definition['default'] || $value === $definition['default']) {
				continue;
			}
			$array['default_settings'][$x]['default_setting_uuid'] = $row['default_setting_uuid'];
			$array['default_settings'][$x]['default_setting_value'] = $value;
			$x++;
			$copied[] = $definition['config'];
		}
		if (!empty($array)) {
			$p = permissions::new();
			$p->add('default_setting_edit', 'temp');
			$this->database->save($array, false);
			unset($array);
			$p->delete('default_setting_edit', 'temp');
			settings::clear_cache();
		}
		return $copied;
	}

	/**
	 * Get the identity default settings.
	 *
	 * @return array Rows by subcategory, each with keys: default_setting_uuid, default_setting_value, update_date
	 */
	private function rows(): array {
		$sql = "select default_setting_uuid, default_setting_subcategory, default_setting_value, update_date ";
		$sql .= "from v_default_settings ";
		$sql .= "where default_setting_category = :category ";
		$sql .= "order by default_setting_order asc ";
		$parameters['category'] = self::category;
		$result = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);
		$rows = [];
		if (is_array($result)) {
			foreach ($result as $row) {
				$rows[$row['default_setting_subcategory']][] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Build a new default setting row.
	 *
	 * @param string $name  Subcategory
	 * @param string $value Value
	 *
	 * @return array Default setting row
	 */
	private function new_row(string $name, string $value): array {
		//use the uuid from app_config.php unless it belongs to another setting
		$sql = "select count(*) from v_default_settings where default_setting_uuid = :uuid ";
		$parameters['uuid'] = self::definitions[$name]['uuid'];
		$exists = $this->database->select($sql, $parameters, 'column');
		unset($sql, $parameters);

		$row['default_setting_uuid'] = empty($exists) ? self::definitions[$name]['uuid'] : uuid();
		$row['app_uuid'] = self::app_uuid;
		$row['default_setting_category'] = self::category;
		$row['default_setting_subcategory'] = $name;
		$row['default_setting_name'] = self::definitions[$name]['type'];
		$row['default_setting_value'] = $value;
		$row['default_setting_enabled'] = true;
		return $row;
	}

	/**
	 * Check that a value is an absolute path without . or .. segments.
	 *
	 * @param string $path Path
	 *
	 * @return bool True if the path is valid
	 */
	public static function is_path(string $path): bool {
		if ($path === '' || $path[0] !== '/' || strlen($path) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $path)) {
			return false;
		}
		foreach (explode('/', $path) as $segment) {
			if ($segment === '.' || $segment === '..') {
				return false;
			}
		}
		return true;
	}

	/**
	 * Check that a value is an https URL. Receivers, including verify.php, only download the certificate over https.
	 *
	 * @param string $url URL
	 *
	 * @return bool True if the URL is valid
	 */
	public static function is_url(string $url): bool {
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		return filter_var($url, FILTER_VALIDATE_URL) !== false && $scheme === 'https';
	}

	/**
	 * Get the invalid entries of a comma separated list of IPv4 CIDR ranges.
	 *
	 * @param string $allowed_cidr Comma separated list such as 127.0.0.1/32, 10.0.0.0/8
	 *
	 * @return array The entries that are not address/mask
	 */
	public static function invalid_cidr(string $allowed_cidr): array {
		$invalid = [];
		foreach (array_map('trim', explode(',', $allowed_cidr)) as $cidr) {
			[$subnet, $mask] = array_pad(explode('/', $cidr, 2), 2, '');
			if (ip2long($subnet) === false || !ctype_digit($mask) || (int)$mask > 32) {
				$invalid[] = $cidr;
			}
		}
		return $invalid;
	}

	/**
	 * Check whether an address may use sign.php and verify.php.
	 *
	 * Only valid address/mask entries are used. An empty or invalid entry never matches, and an empty list allows no
	 * address, so a stray comma or a typo cannot open signing to everyone. check_cidr() is given one range at a time
	 * because it allows every address for an empty value.
	 *
	 * @param string $allowed_cidr Comma separated list such as 127.0.0.1/32, 10.0.0.0/8
	 * @param string $ip_address   Address of the client
	 *
	 * @return bool True if the address is in one of the valid ranges
	 */
	public static function is_allowed(string $allowed_cidr, string $ip_address): bool {
		if (ip2long($ip_address) === false) {
			return false;
		}
		foreach (array_map('trim', explode(',', $allowed_cidr)) as $cidr) {
			if ($cidr === '' || !empty(self::invalid_cidr($cidr))) {
				continue;
			}
			if (check_cidr($cidr, $ip_address)) {
				return true;
			}
		}
		return false;
	}

}
