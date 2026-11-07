# Changelog

## 1.0 (Unreleased)

The first release with a web interface. Earlier versions were `sign.php` and `verify.php` configured in
`config.conf`.

### Features

- **Signing:** sign.php adds a SIP `Identity` header (SHAKEN PASSporT, ES256) to outbound calls. A attestation is
  only given when the calling number is an enabled inbound destination of the call's domain; other calls are signed
  with B. The attestation check can be turned off in Setup.
- **Verification:** verify.php checks the `Identity` header of inbound calls and returns `TN-Validation-Passed-A`,
  `-B` or `-C`, `TN-Validation-Failed` or `No-TN-Validation` for the `identity_verstat` channel variable. A pass
  requires a recent PASSporT that was not already used, the call's calling and called numbers, a certificate that
  carries TNAuthList and chains to the STI-CA roots in the Trust List setting, and a valid signature. Certificates
  are downloaded over https from public addresses only.
- **Setup page:** checks of the whole configuration, the settings (stored in Default Settings), importing the STI-CA
  key and certificate (PEM or DER, with the chain, encrypted keys allowed) or generating a test certificate, a test
  that signs and verifies a call, and adding or removing signing on outbound routes and the inbound verification
  dialplan.
- **Overview and Logs pages:** sign and verify counts by result and attestation, activity by hour or day, recent
  failures, and every result with a link to its call detail record. Logs older than 90 days are deleted
  automatically (the Log Retention setting).
- **Permissions:** everything is superadmin only by default. Work that applies to the whole platform needs
  `identity_setup_all`, and logs of other domains, and verify results, which have no domain, need
  `identity_log_all`.

### Experimental

- **Branded calling (Rich Call Data, RFC 9795):** a display name, logo and call reason for vetted brands, from a
  brands file managed with `brand.php`, optionally signed with a separate BCID certificate. Off unless Brands File is
  set. Not part of the 1.0 feature set and may change; see the README.

### Requirements

FusionPBX 5.6, PostgreSQL, PHP 8.1 or later (8.4 or later recommended) with openssl, curl and mbstring, and APCu for
PHP-FPM recommended. See the README.

### Upgrading from earlier versions

Run `php /var/www/fusionpbx/core/upgrade/upgrade.php` after updating. It:

- copies the identity settings from `config.conf` to Default Settings once. Manage them in Advanced > Identity >
  Setup afterwards and remove them from `config.conf`.
- URL encodes the caller ID and dialed number in existing signing actions, so they cannot add parameters to the
  sign.php request.
- adds the calling and called numbers to existing verification actions, and URL encodes their values.
- adds the `v_identity_logs` table, the permissions, the menu item and the new Default Settings.

Behaviour changes to check before upgrading:

- **Attestation:** calls from numbers that are not inbound destinations of their domain are signed with B instead of
  A. Turn the Attestation Check setting off to keep the old behaviour.
- **Inbound verification:** calls that passed before return `No-TN-Validation` until the Trust List setting points to
  the STI-CA root certificates from the STI-PA, and `TN-Validation-Failed` when their numbers do not match the call
  or their certificate does not chain to the trust list. Review dialplans that route on `identity_verstat`.
- **Certificate URL:** must be https. An http URL is reported as an error in Setup and cannot be saved.
- **Allowed CIDR:** an empty or invalid entry no longer allows every address; an empty list allows none.
- **nginx:** sign.php and verify.php should only be reachable through the `127.0.0.1` server block. See Install in the
  README for the lines that refuse them on the public server blocks.

### Known limitations

- verify.php does not check certificate revocation, so a revoked certificate passes until it expires.
- verify.php does not check the logo digest (`rcdi`) of Rich Call Data.
- The call's numbers are compared with the signed ones as digits, with a leading 1 added to 10 digit North American
  numbers. Numbers in other national formats must be sent in the same form they were signed in.
- Replay protection and the certificate cache need APCu and only cover one server.
