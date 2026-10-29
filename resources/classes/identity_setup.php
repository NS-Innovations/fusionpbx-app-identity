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
	 * URL FreeSWITCH uses to reach sign.php and verify.php
	 */
	const local_url = 'http://127.0.0.1/app/identity';

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
	 * Get the identity settings from config.conf.
	 *
	 * Uses the same keys and defaults as sign.php and verify.php. The configured key shows whether the value is set
	 * in config.conf or is the default.
	 *
	 * @return array Array with keys: path, private_key_path, certificate_path, certificate_url, allowed_cidr, debug, configured (array)
	 */
	public function settings(): array {
		$identity_path = $this->config->get('identity.path', '/etc/fusionpbx/certs');
		$settings = [
			'path' => $identity_path,
			'private_key_path' => $identity_path . '/' . $this->config->get('identity.private_key_name', 'identity_private.key'),
			'certificate_path' => $identity_path . '/' . $this->config->get('identity.certificate_name', 'identity_certificate.pem'),
			'certificate_url' => $this->config->get('identity.certificate_url', ''),
			'allowed_cidr' => $this->config->get('identity.allowed_cidr', '127.0.0.1/32'),
			'debug' => $this->config->get('identity.debug', 'false'),
			'configured' => [],
		];
		foreach (['path', 'private_key_name', 'certificate_name', 'certificate_url', 'allowed_cidr', 'debug'] as $name) {
			$settings['configured'][$name] = !empty($this->config->get('identity.' . $name, ''));
		}
		return $settings;
	}

	/**
	 * Get the outbound routes and whether each one signs calls.
	 *
	 * Only routes with a bridge action are returned. The suggested destination is taken from the first bridge so the
	 * signed number matches the number sent to the carrier.
	 *
	 * @param bool $all Return routes for all domains instead of the current domain
	 *
	 * @return array List of routes with keys: dialplan_uuid, domain_uuid, domain_name, dialplan_name, dialplan_context,
	 *               dialplan_enabled, signed, destination, attest_level
	 */
	public function outbound_routes(bool $all = false): array {
		//get the outbound routes
		$sql = "select d.dialplan_uuid, d.domain_uuid, m.domain_name, d.dialplan_name, d.dialplan_context, ";
		$sql .= "cast(d.dialplan_enabled as text) as dialplan_enabled ";
		$sql .= "from v_dialplans as d ";
		$sql .= "left join v_domains as m on m.domain_uuid = d.domain_uuid ";
		$sql .= "where d.app_uuid = :app_uuid ";
		$parameters['app_uuid'] = self::outbound_route_app_uuid;
		if (!$all) {
			$sql .= "and d.domain_uuid = :domain_uuid ";
			$parameters['domain_uuid'] = $this->domain_uuid;
		}
		$sql .= "order by m.domain_name asc, d.dialplan_order asc, d.dialplan_name asc ";
		$rows = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);
		if (!is_array($rows) || @sizeof($rows) == 0) {
			return [];
		}

		//get the actions of the routes
		$sql = "select dialplan_uuid, dialplan_detail_type, dialplan_detail_data ";
		$sql .= "from v_dialplan_details ";
		$sql .= "where dialplan_detail_tag = 'action' ";
		$sql .= "and dialplan_uuid in (select dialplan_uuid from v_dialplans where app_uuid = :app_uuid) ";
		$sql .= "order by dialplan_uuid asc, dialplan_detail_group asc, dialplan_detail_order asc ";
		$parameters['app_uuid'] = self::outbound_route_app_uuid;
		$details = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);

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
			if (!isset($bridges[$uuid])) {
				continue;
			}
			$row['signed'] = isset($signing[$uuid]);
			$row['destination'] = $this->bridge_destination($bridges[$uuid]);
			$row['attest_level'] = 'A';
			if (isset($signing[$uuid])) {
				//show the values currently in use
				if (preg_match('/[?&\s]destination=([^&\s]*)/', $signing[$uuid], $matches)) {
					$row['destination'] = $matches[1];
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
	 * Get the number dialed by a bridge action.
	 *
	 * Uses the first leg of the bridge, removes channel variables and the host, and returns what follows the last
	 * slash. For example sofia/gateway/uuid/1$1 returns 1$1.
	 *
	 * @param string $bridge_data Data of the bridge action
	 *
	 * @return string Destination expression such as 1$1, or $1 if it cannot be determined
	 */
	private function bridge_destination(string $bridge_data): string {
		$leg = preg_split('/[|,]/', preg_replace('/^(\{[^}]*\}|\[[^\]]*\])+/', '', trim($bridge_data)))[0] ?? '';
		$leg = preg_replace('/^(\{[^}]*\}|\[[^\]]*\])+/', '', $leg);
		$destination = substr($leg, strrpos($leg, '/') !== false ? strrpos($leg, '/') + 1 : 0);
		$destination = explode('@', $destination)[0];
		if (!preg_match('/^\+?[0-9]*\$[0-9]$/', $destination)) {
			return '$1';
		}
		return $destination;
	}

	/**
	 * Add signing to an outbound route.
	 *
	 * Inserts the actions that request a SIP Identity header from sign.php before the first bridge, renumbers the
	 * actions of that condition group, then rebuilds the dialplan XML.
	 *
	 * @param string $dialplan_uuid Outbound route dialplan UUID
	 * @param string $destination   Destination expression such as 1$1
	 * @param string $attest_level  Attestation level A, B, or C
	 *
	 * @return bool True if signing was added
	 */
	public function add_signing(string $dialplan_uuid, string $destination, string $attest_level): bool {
		//validate the values
		if (!is_uuid($dialplan_uuid) || !preg_match('/^\+?[0-9]*\$[0-9]$/', $destination) || !in_array($attest_level, ['A', 'B', 'C'], true)) {
			return false;
		}

		//get the route
		$route = $this->route($dialplan_uuid);
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

		//find the group of the first bridge, and stop if the route already signs
		$group = null;
		foreach ($details as $detail) {
			if ($detail['dialplan_detail_type'] == 'curl' && strpos($detail['dialplan_detail_data'], '/app/identity/sign.php') !== false) {
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
			['unset', 'sip_h_Identity'],
			['curl', self::local_url.'/sign.php POST '.$data],
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

		//rebuild the dialplan xml and clear the cache
		$this->update_dialplan($dialplan_uuid, $route['dialplan_context']);

		return true;
	}

	/**
	 * Remove signing from an outbound route.
	 *
	 * Deletes the actions added by add_signing() and rebuilds the dialplan XML.
	 *
	 * @param string $dialplan_uuid Outbound route dialplan UUID
	 *
	 * @return bool True if signing was removed
	 */
	public function remove_signing(string $dialplan_uuid): bool {
		//get the route
		$route = is_uuid($dialplan_uuid) ? $this->route($dialplan_uuid) : [];
		if (empty($route)) {
			return false;
		}

		//find the actions added by add_signing
		$sql = "select dialplan_detail_uuid from v_dialplan_details ";
		$sql .= "where dialplan_uuid = :dialplan_uuid ";
		$sql .= "and dialplan_detail_tag = 'action' ";
		$sql .= "and ( ";
		$sql .= "	(dialplan_detail_type = 'unset' and dialplan_detail_data = 'sip_h_Identity') ";
		$sql .= "	or (dialplan_detail_type = 'curl' and dialplan_detail_data like :sign_url) ";
		$sql .= "	or (dialplan_detail_type = 'export' and dialplan_detail_data = 'exclude_outgoing_extra_header=sip_h_Identity') ";
		$sql .= "	or (dialplan_detail_type = 'set' and dialplan_detail_data = 'sip_h_Identity=\${curl_response_data}') ";
		$sql .= ") ";
		$parameters['dialplan_uuid'] = $dialplan_uuid;
		$parameters['sign_url'] = '%/app/identity/sign.php%';
		$rows = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);
		if (!is_array($rows) || @sizeof($rows) == 0) {
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

		//rebuild the dialplan xml and clear the cache
		$this->update_dialplan($dialplan_uuid, $route['dialplan_context']);

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
			['action', 'curl', self::local_url.'/verify.php POST identity=${url_encode(${sip_identity})}&call_uuid=${uuid}'],
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
		$cache->delete("dialplan:public");

		return true;
	}

	/**
	 * Get an outbound route by UUID.
	 *
	 * @param string $dialplan_uuid Dialplan UUID
	 *
	 * @return array Array with keys: dialplan_uuid, domain_uuid, dialplan_context, or an empty array if not found
	 */
	private function route(string $dialplan_uuid): array {
		$sql = "select dialplan_uuid, domain_uuid, dialplan_context from v_dialplans ";
		$sql .= "where dialplan_uuid = :dialplan_uuid ";
		$sql .= "and app_uuid = :app_uuid ";
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
	private function update_dialplan(string $dialplan_uuid, string $dialplan_context) {
		//rebuild the dialplan xml
		$dialplans = new dialplan;
		$dialplans->source = "details";
		$dialplans->destination = "database";
		$dialplans->uuid = $dialplan_uuid;
		$dialplans->xml();

		//clear the cache
		$cache = new cache;
		$cache->delete("dialplan:".$dialplan_context);

		//set the reload flag
		$_SESSION["reload_xml"] = true;
	}

}
