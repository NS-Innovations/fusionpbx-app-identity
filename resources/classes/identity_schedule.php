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

//define the identity schedule class
class identity_schedule {

	/**
	 * declare constant variables
	 */
	const app_name = 'identity';
	const app_uuid = '826aa9a8-1b27-4aad-8b73-368f734ef6a2';

	/**
	 * Check whether a periodic task is due, and if so record that it runs now.
	 *
	 * sign.php and verify.php run periodic work after the response was sent, so no cron job is needed. The time of the
	 * last run is the modification time of a file in the FusionPBX cache directory, one file per system user, so a
	 * file left by the command line (root) never stops the web server. A time in the future (the clock went back)
	 * counts as due.
	 *
	 * @param string $name       Name of the task, the file is <name>_<uid>
	 * @param int    $interval   Seconds between two runs
	 * @param string $cache_path Directory of the file, the cache location setting
	 *
	 * @return bool|null True if the task is due and the time was recorded, false if it is not due, or null if it is
	 *                   due but the time cannot be recorded
	 */
	public static function due(string $name, int $interval, string $cache_path = '/var/cache/fusionpbx'): ?bool {
		$stamp = self::stamp($name, $cache_path);
		$last = @filemtime($stamp);
		$age = $last === false ? PHP_INT_MAX : time() - $last;
		if ($age >= 0 && $age < $interval) {
			return false;
		}
		return @touch($stamp) ? true : null;
	}

	/**
	 * Make a periodic task due again, for a run that stopped before its work was done.
	 *
	 * @param string $name       Name of the task
	 * @param int    $interval   Seconds between two runs
	 * @param string $cache_path Directory of the file, the cache location setting
	 *
	 * @return void
	 */
	public static function reset(string $name, int $interval, string $cache_path = '/var/cache/fusionpbx') {
		@touch(self::stamp($name, $cache_path), time() - $interval);
	}

	/**
	 * Get the path of the file that holds the time of the last run of a task.
	 *
	 * @param string $name       Name of the task
	 * @param string $cache_path Directory of the file
	 *
	 * @return string Path of the file
	 */
	private static function stamp(string $name, string $cache_path): string {
		$user = function_exists('posix_geteuid') ? (string)posix_geteuid() : preg_replace('/[^a-z0-9_-]/i', '', get_current_user());
		return rtrim($cache_path, '/').'/'.$name.'_'.$user;
	}

}
