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

//url encode the caller id and dialed number in the outbound routes signed by earlier versions, once rather than for every domain
	if ($domains_processed == 1) {
		$identity_setup = new identity_setup(['database' => $database, 'domain_uuid' => '']);
		$updated = $identity_setup->upgrade_signing();
		if ($updated > 0) {
			echo "[ Identity ] URL encoded the caller ID and dialed number in the signing actions of ".$updated." outbound route(s).\n";
		}
		unset($identity_setup, $updated);
	}

?>
