<?php

/**
 * Command line tool to manage the branded calling registry.
 *
 * Usage: php /var/www/fusionpbx/app/identity/brand.php <command> [arguments]
 * Run without arguments for help.
 */

// Command line only
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

// Include files
$no_session = true;
$no_cidr = true;
require_once dirname(__DIR__, 2) . "/resources/require.php";

// Get the brands file
$brands_file = $config->get('identity.brands_file', '');
if (empty($brands_file)) {
	fwrite(STDERR, "Set identity.brands_file in /etc/fusionpbx/config.conf first.\n");
	exit(1);
}

/**
 * Print help text.
 */
function brand_usage() {
	echo <<<TEXT
Usage: brand.php <command> [arguments]

  list                                List brands and assigned numbers
  show <tn>                           Show the active brand for a calling number
  add <brand_id> --name "Display Name" [--logo URL] [--reason "Call reason"]...
      [--default-reason "Call reason"] [--profile bcid|default]
      [--expires YYYY-MM-DD] [--vetting-ref REF] [--disabled]
                                      Create or replace a brand
  logo <brand_id> <https url>         Set the logo, download it and pin its digest
  refresh-logo <brand_id>             Download the logo again and update its digest
  enable <brand_id>                   Enable a brand
  disable <brand_id>                  Disable a brand
  remove <brand_id>                   Remove a brand and its number assignments
  assign <tn> <brand_id>              Assign a calling number to a brand
  unassign <tn>                       Remove a calling number assignment

TEXT;
}

/**
 * Download a logo and return its rcdi digest.
 *
 * @param string $url HTTPS URL of the logo
 *
 * @return string Digest string
 */
function brand_logo_digest(string $url): string {
	if (stripos($url, 'https://') !== 0) {
		throw new RuntimeException('Logo URL must use https');
	}
	$context = stream_context_create([
		'http' => ['timeout' => 15, 'follow_location' => 0, 'ignore_errors' => false],
		'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
	]);
	$bytes = @file_get_contents($url, false, $context, 0, 2 * 1024 * 1024);
	if ($bytes === false || $bytes === '') {
		throw new RuntimeException("Unable to download logo from {$url}");
	}
	if (@getimagesizefromstring($bytes) === false) {
		throw new RuntimeException('Downloaded logo is not a recognized image');
	}
	return identity::compute_digest($bytes);
}

/**
 * Parse --option value pairs. Repeated --reason options are collected.
 *
 * @param array $args Arguments after the command and brand id
 *
 * @return array Options
 */
function brand_parse_options(array $args): array {
	$options = ['reason' => []];
	for ($i = 0; $i < count($args); $i++) {
		$arg = $args[$i];
		if ($arg === '--disabled') {
			$options['disabled'] = true;
			continue;
		}
		if (strpos($arg, '--') !== 0 || !isset($args[$i + 1])) {
			throw new InvalidArgumentException("Unexpected argument: {$arg}");
		}
		$name = substr($arg, 2);
		$value = $args[++$i];
		if ($name === 'reason') {
			$options['reason'][] = $value;
		}
		else {
			$options[$name] = $value;
		}
	}
	return $options;
}

$command = $argv[1] ?? '';
$brands = new identity_brands($brands_file);
if (!$brands->load()) {
	fwrite(STDERR, $brands->error_message . "\n");
	exit(1);
}

try {
	switch ($command) {
		case 'list':
			$numbers_by_brand = [];
			foreach ($brands->get_numbers() as $tn => $brand_id) {
				$numbers_by_brand[$brand_id][] = $tn;
			}
			foreach ($brands->get_brands() as $brand_id => $brand) {
				$state = empty($brand['enabled']) ? 'disabled' : 'enabled';
				echo "{$brand_id}  [{$state}, profile " . ($brand['profile'] ?? 'bcid') . "]  {$brand['nam']}\n";
				if (!empty($brand['icn'])) {
					echo "    logo: {$brand['icn']}  ({$brand['icn_digest']})\n";
				}
				foreach ($brand['call_reasons'] ?? [] as $reason) {
					$marker = ($reason === ($brand['default_call_reason'] ?? '')) ? ' (default)' : '';
					echo "    reason: {$reason}{$marker}\n";
				}
				if (!empty($brand['expires'])) {
					echo "    expires: {$brand['expires']}\n";
				}
				echo "    numbers: " . implode(', ', $numbers_by_brand[$brand_id] ?? []) . "\n";
			}
			break;

		case 'show':
			$brand = $brands->lookup($argv[2] ?? '');
			if ($brand === null) {
				echo "No active brand\n";
				exit(1);
			}
			echo json_encode($brand, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
			break;

		case 'add':
			$brand_id = $argv[2] ?? '';
			$options = brand_parse_options(array_slice($argv, 3));
			if (empty($options['name'])) {
				throw new InvalidArgumentException('--name is required');
			}
			$brand = [
				'nam' => $options['name'],
				'call_reasons' => array_values(array_unique($options['reason'])),
				'default_call_reason' => $options['default-reason'] ?? '',
				'profile' => $options['profile'] ?? 'bcid',
				'enabled' => empty($options['disabled']),
				'expires' => $options['expires'] ?? '',
				'vetting_reference' => $options['vetting-ref'] ?? '',
			];
			if (!in_array($brand['profile'], ['bcid', 'default'], true)) {
				throw new InvalidArgumentException('--profile must be bcid or default');
			}
			if ($brand['default_call_reason'] !== '' && !in_array($brand['default_call_reason'], $brand['call_reasons'], true)) {
				$brand['call_reasons'][] = $brand['default_call_reason'];
			}
			if ($brand['expires'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $brand['expires'])) {
				throw new InvalidArgumentException('--expires must be YYYY-MM-DD');
			}
			if (!empty($options['logo'])) {
				$brand['icn'] = $options['logo'];
				$brand['icn_digest'] = brand_logo_digest($options['logo']);
			}
			// Validate the claims the same way signing will
			$check = (new identity())->build_rich_call_data(identity_brands::to_rich_call_data($brand));
			if (!empty($check['error'])) {
				throw new InvalidArgumentException($check['error']);
			}
			foreach ($brand['call_reasons'] as $reason) {
				$check = (new identity())->build_rich_call_data(['crn' => $reason]);
				if (!empty($check['error'])) {
					throw new InvalidArgumentException("Call reason \"{$reason}\": " . $check['error']);
				}
			}
			$brands->set_brand($brand_id, $brand);
			break;

		case 'logo':
		case 'refresh-logo':
			$brand_id = $argv[2] ?? '';
			$brand = $brands->get_brand($brand_id);
			if ($brand === null) {
				throw new InvalidArgumentException("Unknown brand: {$brand_id}");
			}
			$url = ($command === 'logo') ? ($argv[3] ?? '') : ($brand['icn'] ?? '');
			if ($url === '') {
				throw new InvalidArgumentException('No logo URL');
			}
			$brand['icn'] = $url;
			$brand['icn_digest'] = brand_logo_digest($url);
			$brands->set_brand($brand_id, $brand);
			echo "{$brand_id} logo digest {$brand['icn_digest']}\n";
			break;

		case 'enable':
		case 'disable':
			$brand_id = $argv[2] ?? '';
			$brand = $brands->get_brand($brand_id);
			if ($brand === null) {
				throw new InvalidArgumentException("Unknown brand: {$brand_id}");
			}
			$brand['enabled'] = ($command === 'enable');
			$brands->set_brand($brand_id, $brand);
			break;

		case 'remove':
			$brands->remove_brand($argv[2] ?? '');
			break;

		case 'assign':
			$brands->assign_number($argv[2] ?? '', $argv[3] ?? '');
			break;

		case 'unassign':
			$brands->unassign_number($argv[2] ?? '');
			break;

		default:
			brand_usage();
			exit($command === '' || $command === 'help' ? 0 : 1);
	}
}
catch (Exception $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}

// Save changes for commands that modify the registry
if (!in_array($command, ['list', 'show'], true)) {
	if (!$brands->save()) {
		fwrite(STDERR, $brands->error_message . "\n");
		exit(1);
	}
	echo "Saved {$brands_file}\n";
}
