# Changelog

## Unreleased

- **Sign All Routes:** a new setting that adds signing to the outbound routes of every domain, including global routes
  and routes added later. Routes are signed when the setting is turned on, at most once an hour after a call is signed
  or verified or when a platform administrator opens Setup or Routes, and on upgrade. Saving other settings does not
  start a run. A run from the web server stops after 20 seconds and continues on the next one; routes are locked while
  signing is added or removed. **Remove** excludes a route so it is not signed again, including a route that does not
  sign yet, and **Add** includes it again. Excluded routes are shown as such whether the setting is on or not, and **Add
  All** leaves them alone. Signing is removed only once the exclusion is saved. Calls start a run only under PHP-FPM,
  after the response was sent. Routes whose destination cannot be found in the bridge are skipped and counted in the
  checks. Needs the upgrade, which adds the `v_identity_route_exclusions` table. Before turning it on after upgrading
  from 1.0.x, exclude the routes whose signing was removed on purpose (filter by **Not Signed**, check them and click
  **Remove**), since 1.0.x did not record removals.
- **Routes page:** the outbound routes moved from Setup to their own page, so Setup stays short on servers with many
  routes. The list is paged, searchable by route or domain name (`%` and `_` match literally), filterable by signing and
  sortable with a stable order across pages, and routes can be checked to add or remove signing on several at once.
  **Add All** works across every page that matches the search, stops after 20 seconds and says how many routes are left,
  and is hidden when the list is filtered to signed or excluded routes. A route whose destination cannot be found in the
  bridge is shown with the destination empty instead of `$1`, and **Add All** skips it unless a destination is entered.
  The routes are filtered and paged in the database, so the overview and Setup no longer read every route of every
  domain to count them.
- **Setup:** the Outbound Signing check counts the routes of every domain for users with `identity_setup_all`, and Setup
  no longer has a **Show All** button; its **Routes** buttons open the routes of every domain for those users. The check
  is OK when every route signs or is excluded, and shows the excluded count. Global outbound routes are shown with the
  domain Global under **Show All** on the Routes page, and adding or removing their signing clears the dialplan cache of
  every domain instead of a cache key that no domain uses.
- **Settings:** saving the settings in Setup without changing any value no longer shows "The settings could not be
  saved". The database class reports a save that changes nothing without a result code, so only a failed save is
  reported.
- **Logs:** the search matches `%` and `_` literally instead of as wildcards.
- **Accessibility:** the **Routes** buttons on the overview and Setup have a screen reader label on their link, which
  was announced as an unlabeled link, most noticeably with icon only buttons.

## 1.0.1 (2026-11-07)

- **Setup:** the Certificate Directory check finds why the web server cannot use the directory (it is missing, it has
  the wrong owner or mode, PHP-FPM's ProtectSystem makes it read-only, or PHP-FPM was not restarted after the
  `ReadWritePaths` drop-in was added) and shows the command that fixes it, with the server's directory, web server
  user and PHP-FPM service filled in.

## 1.0 (2026-11-07)

The first release with a web interface. Earlier versions were `sign.php` and `verify.php` configured in
`config.conf`.

### Features

- **Signing:** sign.php adds a SIP `Identity` header (SHAKEN PASSporT, ES256) to outbound calls. A attestation is
  only given when the calling number is an enabled inbound destination of the call's domain; other calls are signed
  with B. The attestation check can be turned off in Setup.
- **Verification:** verify.php checks the `Identity` header of inbound calls and returns `TN-Validation-Passed-A`,
  `-B` or `-C`, `TN-Validation-Failed` or `No-TN-Validation` for the `identity_verstat` channel variable. A pass
  requires a recent PASSporT that was not already used, the call's calling and called numbers, a certificate that
  carries TNAuthList and chains to the STI-CA roots in the Trust List setting, is not revoked in the STI-PA
  certificate revocation list (CRL), and a valid signature. Certificates and the CRL are downloaded over https from
  public addresses only, and the CRL is kept for an hour in the FusionPBX cache directory.
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
- adds a timeout (`curl_timeout`, 3 seconds for signing and 6 for verification) around the requests to sign.php and
  verify.php in existing actions, and clears `curl_response_data` before them, so a slow or failed request can neither
  hold up call setup nor send an earlier response as the `Identity` header.
- adds the `v_identity_logs` table, the permissions, the menu item and the new Default Settings.

Behaviour changes to check before upgrading:

- **Attestation:** calls from numbers that are not inbound destinations of their domain are signed with B instead of
  A. Turn the Attestation Check setting off to keep the old behaviour.
- **Inbound verification:** calls that passed before return `No-TN-Validation` until the Trust List setting points to
  the STI-CA root certificates and the STI-PA root certificate from the STI-PA, and while the STI-PA certificate
  revocation list cannot be downloaded and checked. They return `TN-Validation-Failed` when their numbers do not
  match the call, their certificate does not chain to the trust list, or it is revoked. Review dialplans that route
  on `identity_verstat`. The server needs outbound https access to the CRL URL.
- **Certificate URL:** must be https. An http URL is reported as an error in Setup and cannot be saved.
- **Allowed CIDR:** an empty or invalid entry no longer allows every address; an empty list allows none.
- **nginx:** sign.php and verify.php should only be reachable through the `127.0.0.1` server block. See Install in the
  README for the lines that refuse them on the public server blocks.

### Known limitations

- verify.php does not check the logo digest (`rcdi`) of Rich Call Data.
- The call's numbers are compared with the signed ones as digits, with a leading 1 added to 10 digit North American
  numbers. Numbers in other national formats must be sent in the same form they were signed in.
- Replay protection and the certificate cache need APCu and only cover one server.
