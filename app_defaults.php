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

//copy the identity settings from config.conf to default settings, once rather than for every domain
	if ($domains_processed == 1) {
		$identity_settings = new identity_settings(['database' => $database]);
		$copied = $identity_settings->migrate();
		if (!empty($copied)) {
			echo "[ Identity ] Copied ".implode(', ', $copied)." from config.conf to Default Settings.\n";
			echo "             Manage them in Advanced > Identity > Setup and remove them from config.conf.\n";
		}
		unset($identity_settings, $copied);
	}

//update the signing actions of the outbound routes signed by earlier versions, once rather than for every domain
	if ($domains_processed == 1) {
		$identity_setup = new identity_setup(['database' => $database, 'domain_uuid' => '']);
		$updated = $identity_setup->upgrade_signing();
		if ($updated > 0) {
			echo "[ Identity ] Updated the signing actions of ".$updated." outbound route(s): URL encoding, curl timeouts and clearing the previous response.\n";
		}
		unset($identity_setup, $updated);
	}

//the origin dialplan of an earlier version marks the outside caller's number too, once rather than for every domain
	if ($domains_processed == 1) {
		$identity_setup = new identity_setup(['database' => $database, 'domain_uuid' => '']);
		if ($identity_setup->upgrade_origin()) {
			echo "[ Identity ] The identity_origin dialplan now records the number an outside call came in with.\n";
		}
		//a new origin dialplan is in the public dialplans the switch has cached only once the cache is cleared
		$origin = $identity_setup->origin_dialplan();
		if (!empty($origin)) {
			$sql = "select count(*) from v_dialplans where dialplan_uuid = :dialplan_uuid and insert_date > now() - interval '1 hour' ";
			if ($database->select($sql, ['dialplan_uuid' => $origin['dialplan_uuid']], 'column') > 0) {
				(new cache)->flush();
			}
			unset($sql);
		}
		unset($identity_setup, $origin);
	}

//update the verification actions added by earlier versions, once rather than for every domain
	if ($domains_processed == 1) {
		$identity_setup = new identity_setup(['database' => $database, 'domain_uuid' => '']);
		$updated = $identity_setup->upgrade_verification();
		if ($updated > 0) {
			echo "[ Identity ] Updated the verification action of ".$updated." dialplan(s): the call's numbers, URL encoding, curl timeouts and clearing the previous response.\n";
		}
		unset($identity_setup, $updated);
	}

//add signing to the new outbound routes of every domain when sign all routes is on, once rather than for every domain
	if ($domains_processed == 1) {
		$identity_settings = (new identity_settings(['database' => $database]))->get();
		if ($identity_settings['sign_all_routes']) {
			$identity_setup = new identity_setup(['database' => $database, 'domain_uuid' => '']);
			$sign_all = $identity_setup->sign_all_routes();
			if ($sign_all['added'] > 0 || $sign_all['failed'] > 0 || $sign_all['skipped'] > 0) {
				echo "[ Identity ] Sign All Routes: signed ".$sign_all['added']." outbound route(s), ".$sign_all['failed']." failed, ".$sign_all['skipped']." skipped because the destination was not found in the bridge.\n";
			}
			unset($identity_setup, $sign_all);
		}
		unset($identity_settings);
	}

?>
