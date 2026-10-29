<?php

/**
 * Records branded call signing events for BCID reporting and billing.
 *
 * A BCID Signing Agent must report each branded call it signs to the BCID
 * platform. The platform interface is provided under the BCID participation
 * agreement, so this class currently writes one JSON line per event to a local
 * log. A sender that forwards these events to the platform can read the log,
 * or this class can be extended once the interface specification is available.
 */
class identity_bcid_reporter {

	/**
	 * Path to the event log, or empty to disable.
	 *
	 * @var string
	 */
	private $log_path = '';

	/**
	 * @param string $log_path Path to the JSON lines log file
	 */
	public function __construct(string $log_path = '') {
		$this->log_path = $log_path;
	}

	/**
	 * Record a signed branded call.
	 *
	 * Failures are swallowed so reporting can never block call setup.
	 *
	 * @param array $event Keys: origid, orig, dest, brand_id, crn, profile, iat
	 *
	 * @return bool True if the event was written
	 */
	public function record_signing(array $event): bool {
		if ($this->log_path === '') {
			return false;
		}
		$event = ['event' => 'signed', 'logged_at' => gmdate('c')] + $event;
		$line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		return @file_put_contents($this->log_path, $line, FILE_APPEND | LOCK_EX) !== false;
	}
}
