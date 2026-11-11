# Identity (STIR/SHAKEN) for FusionPBX

Signs outbound calls with a SIP `Identity` header and verifies the `Identity` header of inbound calls, as defined by
STIR/SHAKEN (RFC 8224, 8225, 8588). It can also add branded calling (Rich Call Data) for vetted brands; branded
calling is experimental (see [Branded Calling](#branded-calling-rich-call-data-experimental)).

Everything is managed in the web interface under **Advanced > Identity**:

- **Identity** (overview): call counts, activity and recent failures
- **Logs**: every sign and verify result
- **Routes**: the outbound routes, with adding and removing signing
- **Setup**: checks, settings, certificates, a test call, and the dialplan for inbound verification

The pages are for superadmin by default. The only command-line tool is `brand.php`, which manages branded calling
brands.

See [CHANGELOG.md](CHANGELOG.md) for the changes in each release.

## Requirements

- FusionPBX 5.6 (developed on 5.6.6)
- PostgreSQL. The overview, Logs and Routes pages use PostgreSQL SQL (`filter`, `timezone()`, intervals) and Sign All
  Routes uses PostgreSQL advisory locks, so MySQL and SQLite are not supported, although `app_config.php` declares
  column types for them as FusionPBX apps do
- PHP 8.1 or later with the openssl, curl and mbstring extensions. PHP 8.4 or later is recommended: it checks the
  address of a certificate download after connecting (see [Inbound Verification](#inbound-verification))
- APCu for PHP-FPM is recommended: without it the replay check is off and the certificate of each inbound call is
  downloaded again instead of being cached
- A STIR/SHAKEN certificate and private key from an STI-CA, and an https URL to publish the certificate at
- To verify inbound calls, the STI-CA root certificates and the STI-PA root certificate from the STI-PA (see
  [Trust List](#trust-list)), and outbound https access to the STI-PA CRL

## Install

```
cd /var/www/fusionpbx/app
git clone git@github.com:NS-Innovations/fusionpbx-app-identity.git identity
cd /var/www/fusionpbx
php core/upgrade/upgrade.php --permissions
php core/upgrade/upgrade.php
```

The upgrade adds the `v_identity_logs` table, the permissions, the menu item and the identity Default Settings. Log
out and back in to pick up the new permissions.

Create the certificate directory, owned by the web server user:

```
install -d -o www-data -g www-data -m 0750 /etc/fusionpbx/certs
```

The Debian and Ubuntu PHP-FPM service runs with `ProtectSystem=full`, which makes `/etc` read-only for the web
server even when it owns the directory. Import and Generate Test Certificate then fail, and the Certificate Directory
check shows a warning. Allow the web server to write to the directory, adjusting the PHP version:

```
mkdir -p /etc/systemd/system/php8.4-fpm.service.d
printf '[Service]\nReadWritePaths=/etc/fusionpbx/certs\n' > /etc/systemd/system/php8.4-fpm.service.d/identity.conf
systemctl daemon-reload
systemctl restart php8.4-fpm
```

`ReadWritePaths` adds to the paths the service already allows. Alternatively, copy the key and certificate into the
directory by hand.

The Certificate Directory check in Setup finds which of these is missing on the server (the directory, its owner or
mode, the drop-in, or a PHP-FPM restart after adding it) and shows the command to run as root, with the server's
directory, web server user and PHP-FPM service filled in.

FreeSWITCH reaches sign.php and verify.php through the `127.0.0.1` server block of the FusionPBX nginx
configuration. They are also served by the public server blocks, where the **Allowed CIDR** setting is their only
protection. Anyone who can use sign.php gets calls signed with your certificate, so if a reverse proxy, tunnel or load
balancer on the same host forwards public requests, they arrive from `127.0.0.1` and pass that check. Refuse both
scripts on the public server blocks by adding these lines to each `server` block in
`/etc/nginx/sites-enabled/fusionpbx` except the one that listens on `127.0.0.1:80`:

```
	location = /app/identity/sign.php { return 404; }
	location = /app/identity/verify.php { return 404; }
```

Exact match (`=`) locations take priority over the `\.php$` location wherever they are placed. Run `nginx -t` and
`systemctl reload nginx`, then check that the public address returns 404 and the local one still signs:

```
curl -s -o /dev/null -w '%{http_code}\n' https://<your host>/app/identity/sign.php
curl -s -X POST -d "source=12025550100" -d "destination=13105551234" http://127.0.0.1/app/identity/sign.php
```

## Getting Started

Open **Advanced > Identity** and click **Setup**. Everything except the outbound routes applies to the whole platform
and needs the `identity_setup_all` permission, which superadmin has. Work down the page:

1. **Checks:** the table at the top shows what is missing or wrong. Fix the errors first.
2. **Settings:** set the certificate URL, and the trust list to verify inbound calls (see
   [Inbound Verification](#inbound-verification)). Adjust the certificate directory, file names or allowed CIDR if
   needed, then click **Save**.
3. **Certificate:** import the private key and certificate from your STI-CA. To try things out first, click
   **Generate Test Certificate** instead.
4. **Certificate URL:** copy the certificate to the web server behind the certificate URL. The checks report a
   mismatch until the URL serves the same certificate.
5. **Test:** enter a calling and called number and click **Test**. The call is signed, then verified with the local
   certificate and by downloading the certificate URL, the way a carrier would.
6. **Outbound Routes:** click **Routes**, then **Add** on each route, or **Add All**, to sign its calls. To sign every
   route of every domain, including routes added later, turn on **Sign All Routes** instead (see
   [Sign All Routes](#sign-all-routes)).
7. **Inbound Verification:** click **Add** to verify the `Identity` header of inbound calls.

Then watch the results on the Identity overview and the Logs page.

## Overview

**Advanced > Identity** shows:

- signed and verified call counts by attestation level, with the success rate and branded calls, for the last day,
  week or month
- activity by hour or day
- the most recent failures
- a summary of the certificate, certificate URL, outbound routes and inbound verification

The **Logs**, **Routes** and **Setup** buttons open the other pages.

## Logs

**Logs** lists every sign and verify result, newest first. You can:

- search by number, brand, certificate URL, origid, message or call UUID
- filter by type (sign or verify) and status
- sort by any column
- delete entries

Entries older than the **Log Retention (days)** setting (90 by default) are deleted automatically. sign.php and
verify.php run the cleanup at most once an hour, after the response is sent, so it never delays a call and does not
need the maintenance app. The time of the last cleanup is kept in `identity_logs_purge_<uid>` in the FusionPBX cache
directory (the `cache/location` setting, `/var/cache/fusionpbx` by default), which only the web server user can
write. If that file cannot be written, the cleanup runs on about one call in a thousand instead and the error is
logged. Where the FusionPBX maintenance app is installed, it also runs the cleanup through
`identity_logs::database_maintenance()`. Set the setting to 0 to keep every entry.

Deleting entries needs `identity_log_delete`. Without `identity_log_all`, only the entries of the current domain can
be deleted, not the verify entries, which have no domain.

A row with a call UUID links to its call detail record. Verify results have no domain because inbound calls are
verified before the domain is known, so they hold the inbound calls of every domain. They are shown, on the overview
and Logs pages, only to users with `identity_log_all`; other users see only the sign results of their own domain.
**Show All** adds the sign results of the other domains.

## Routes

### Outbound Routes

The **Routes** page (the **Routes** button on the overview and on Setup) lists each outbound route with a bridge and
whether it signs calls. It needs the `identity_setup` permission. The list is paged with the domain's paging setting,
can be searched by route or domain name, filtered by signing (signed, not signed, and excluded from
[Sign All Routes](#sign-all-routes)) and sorted by domain, name, enabled or signing.

For a route that does not sign yet, check the **Destination** and **Attestation**, then click **Add** on the row. To
change several routes at once, check them and use **Add** or **Remove** at the top. When the destination cannot be
taken from the bridge, the **Destination** box is empty and marked Required; a route left empty is skipped. **Add All**
signs every route that does not sign yet, is not excluded and matches the search, on every page: the routes on the
current page with the values in their rows, the others with the suggested destination and attestation A. It skips
routes whose destination cannot be taken from the bridge and was not filled in, and reports how many routes were signed,
failed and skipped. Like a Sign All Routes run, it stops after 20 seconds and says how many routes are left; click
**Add All** again to sign the rest. The search matches the text as typed: `%` and `_` are not wildcards. **Remove**
takes signing off a route. Only the routes of the current domain can be changed, unless **Show All** is used, which needs the
`identity_setup_all` permission. **Show All** also lists the global outbound routes (Domain set to Global in the
dialplan editor), shown with the domain Global; changing one clears the dialplan cache of every domain.

- **Destination:** the number sent to the carrier. The page suggests the end of the first bridge: `1$1` when the route
  captures 10 digits, `$1` when it captures 11. It must have the form `[+][digits]$N`. When the destination cannot be
  taken from the bridge, such as a bridge to `user/1000@domain`, the field is left empty to be filled in.
- **Attestation:** A (full), B (partial) or C (gateway). A is the default. It is the highest level the route signs
  with; see [Attestation](#attestation).

Adding signing inserts these actions before the first bridge of the route, then rebuilds the route XML and clears the
dialplan cache:

```
<action application="multiunset" data="sip_h_Identity curl_response_data"/>
<action application="set" data="curl_timeout=3"/>
<action application="curl" data="http://127.0.0.1/app/identity/sign.php POST source=${url_encode(${effective_caller_id_number})}&destination=${url_encode(1$1)}&attest_level=A&domain_uuid=${domain_uuid}&call_uuid=${uuid}"/>
<action application="unset" data="curl_timeout"/>
<action application="export" data="exclude_outgoing_extra_header=sip_h_Identity"/>
<action application="set" data="sip_h_Identity=${curl_response_data}"/>
```

The `export exclude_outgoing_extra_header=sip_h_Identity` line is required. Without it FreeSWITCH sends the header
twice, and carriers reject the call with `400 Multiple Identity headers`. The route name links to the dialplan
editor if you need to change the actions.

The request to sign.php may take 3 seconds (`curl_timeout`). If it takes longer, or fails, the call goes out without
an `Identity` header instead of waiting. The timeout is set as a channel variable and unset after the request, because
FreeSWITCH 1.11 does not apply the `timeout` option of the `curl` application, and unsetting it keeps it from applying
to later `curl` actions of the call. `curl_response_data` is cleared before the request because FreeSWITCH does not
change it when a request fails or times out, so an earlier `curl` action on the same channel, such as inbound
verification of a forwarded call, would otherwise be sent as the `Identity` header.

The caller ID, the dialed number and any call reason are URL encoded so they cannot add parameters, such as a call
reason, to the signing request. Earlier versions sent them without encoding; the upgrade updates those routes,
including signing actions edited by hand, and prints how many it changed. It also adds `domain_uuid=${domain_uuid}`
to actions that do not send it, since the attestation check needs the domain of the call, and adds the timeout and
the clearing of `curl_response_data` around the request, renumbering the actions of the route when it adds steps.

### Sign All Routes

With the **Sign All Routes** setting on, signing is added to every outbound route of every domain that does not sign
yet, including global routes, so new routes do not need to be added one at a time. Each route is signed the way
**Add** would, with the destination suggested from the bridge and attestation A. The
[attestation check](#attestation) still lowers A to B when the domain does not own the calling number.

**Before turning it on**, exclude the routes that must stay unsigned, such as routes to a carrier that rejects the
Identity header: on the Routes page, filter by **Not Signed**, check those routes and click **Remove**. This matters
most after upgrading from 1.0.x, where **Remove** did not record anything, so a route whose signing was removed on
purpose looks the same as one that was never signed and would be signed with the others.

The routes are signed:

- when the setting is turned on in Setup (saving other settings leaves it to the hourly run)
- at most once an hour after a call is signed or verified, or when a user with `identity_setup_all` opens Setup or
  the Routes page, so a new route is signed within an hour without a cron job (the time of the last run is kept in
  `identity_sign_all_routes_<uid>` in the FusionPBX cache directory). Users without `identity_setup_all` never start a
  run, since it changes the routes of every domain. Calls start a run only under PHP-FPM, after the response was sent
  to FreeSWITCH, so a call never waits for it
- on upgrade

A run started from the web server stops after 20 seconds, so turning the setting on for thousands of routes is not
cut off by PHP's `max_execution_time`. It reports how many routes are left and makes the next run due at once, so the
rest are signed by the next calls or page views. The upgrade has no time limit. Only one run happens at a time (a
PostgreSQL advisory lock), and each route is locked while signing is added to or removed from it, so a run cannot
sign a route again while **Remove** is taking signing off it, or add signing twice while **Add** is adding it.

**Remove** excludes a route: it stays unsigned and is shown as **Excluded**, also while the setting is off, so turning
the setting on later leaves it unsigned. A route that is not signed yet can be excluded too, so it is never signed:
check it and click **Remove** at the top. Click **Add** on the route to sign it again, which removes the exclusion.
**Add All** leaves excluded routes alone and is shown only when the list is not filtered to **Signed** or
**Excluded**. Exclusions are kept in the `v_identity_route_exclusions` table, created by the upgrade, and are deleted
with their route. Until the upgrade has created the table nothing is signed automatically.

Only **Remove** on the Routes page excludes a route. Signing taken off any other way, such as by deleting the actions
in the dialplan editor, leaves the route unsigned and not excluded, so Sign All Routes adds signing back within the
hour. To keep a route unsigned, use **Remove**. When signing is added back to an excluded route in the dialplan
editor, the next run removes the exclusion, so later edits are handled the same way.

A route whose destination cannot be taken from the bridge, such as a bridge to `user/1000@domain`, is not signed,
because it would only be guessing `$1`. The checks in Setup count them; set the destination of each on the Routes page
(filter by **Not Signed**) and click **Add**.

The setting changes the routes in the database, not how calls are handled: turning it off leaves the routes it signed
as they are. Use **Remove** to take signing off a route.

### Attestation

The attestation level tells the receiver how much the signer vouches for the calling number:

- **A (full):** the customer is authenticated and is authorized to use the calling number
- **B (partial):** the customer is authenticated, but the signer cannot confirm the number belongs to them
- **C (gateway):** the call entered the network from elsewhere, such as an international gateway

Signing A for a number the customer does not own is mis-attestation, which carriers and regulators act on. With the
**Attestation Check** setting on (the default), a call that the route would sign with A is signed with B unless the
calling number is an enabled inbound destination of the call's domain. The destination number is matched on its own
and with its area code, so `2025550100` matches the calling number `12025550100` or `+12025550100`. B and C routes
are not changed.

Only the destination number and area code count, which only superadmin can edit by default. The country code
(`destination_prefix`) is ignored, because the stock admin group may edit it and could otherwise claim another
customer's number. Store numbers outside North America with their country code in the destination number, such as
`442071234567`.

A call that came in from outside and is going out again, such as one forwarded by call forward, a ring group or follow
me, may still present the outside caller's number, which nobody here authenticated. When the number being signed is
the number the call came in with, it is signed with C whatever the route asks for; a forward that presents another
number, such as the domain's own number a ring group or follow me sets, keeps the attestation of its route. The upgrade
adds a global dialplan in the public context, `identity_origin` (order 11), that exports `identity_origin=inbound` and
`identity_origin_number` (the caller's number) to every call coming in from outside; unlike `call_direction`, which every outbound route sets
to outbound again, no route changes it, and a ring group or follow me leg gets it from the call it was made for. sign.php
reads it from the switch by the call's `call_uuid`, so routes signed before need no change. When it can not be read,
for example without a `call_uuid` or with the event socket down, the call is signed as the route asks and the log says
so. A call that came in from outside whose number can not be read, as with an origin dialplan made before the number was
recorded, is signed with C. Keep the `identity_origin` dialplan enabled; Setup's **Forwarded Calls** check shows an
error when it is missing, disabled or does not record the number. This app clears only the public dialplan's cache
keys when it changes a public dialplan, not the whole cache.

Not covered: a call that never passes the public context, such as one from a gateway whose context is a domain, and a
leg FreeSWITCH starts without the call it serves, such as a call center agent call or a conference dial out, carry no
`identity_origin`, so they keep the attestation of their route.

A lowered call is logged as signed with B, or C for a forwarded call, with the reason in the message. If a customer may use a number that is not
one of their destinations, for example a number hosted elsewhere with a letter of authorization, add it as an inbound
destination of their domain, or turn the check off.

## Setup

### Checks

The checks table shows ok, warning or error, with details, for:

- the database tables (`v_identity_logs` and `v_identity_route_exclusions`, created by the upgrade)
- the settings (a warning while they are still read from `config.conf`, see below)
- the certificate directory: present, and writable by the web server
- the private key: present, readable, P-256, and permissions
- the certificate: present, readable, expired, self-signed, or within 30 days of expiry
- that the key matches the certificate
- the certificate URL: set, https, reachable, and serving the same certificate
- the allowed CIDR: valid entries, open to every address (a warning), and `127.0.0.1` allowed (an error otherwise)
- the attestation check (a warning when it is off)
- debug mode
- outbound routes: how many sign calls, counted over every domain for users with `identity_setup_all` and over the
  current domain for others; OK when every route signs or was excluded with **Remove**, and the **Routes** buttons
  open the same routes the check counts
- Sign All Routes, when it is on: the number of excluded routes, and a warning with the number of routes it cannot sign
  because the destination cannot be taken from the bridge
- inbound verification
- the trust list, when inbound verification is on or **Trust List** is set: present and readable, with the number of
  certificates, and a warning when some have expired
- revocation, when **Trust List** is set: the CRL is downloaded and checked the way verify.php does, and the check
  shows its date, next update and number of revoked certificates, or why it cannot be used. A warning when **CRL URL**
  is empty
- the brands file, when **Brands File** is set: present and readable, with the number of brands and numbers, and a
  warning when enabled brands use the `bcid` profile but **BCID Certificate URL** is not set
- the BCID private key, certificate, key pair and certificate URL, when **BCID Certificate URL** is set, with the same
  tests as the default ones. A branded call that cannot be signed with the BCID key is signed without branding, and a
  BCID key that does not match its certificate makes branded calls fail verification, so these show problems that are
  otherwise only in the PHP error log or at the carrier

Click **Refresh** to run them again.

### Settings

The settings apply to the whole server and take effect on the next call. Click **Save** to store them. Each value is
checked first; an invalid value is listed with a **Back** button that returns to the form with your input.

| Setting | Default | Purpose |
|---|---|---|
| Certificate Directory | `/etc/fusionpbx/certs` | Directory of the private keys and certificates (absolute path) |
| Private Key File | `identity_private.key` | File name of the private key in the certificate directory |
| Certificate File | `identity_certificate.pem` | File name of the certificate in the certificate directory |
| Certificate URL | *(none, required)* | Public https URL of the certificate, sent as `x5u` in every signature |
| Allowed CIDR | `127.0.0.1/32` | Comma separated IPv4 ranges that can use sign.php and verify.php |
| Attestation Check | true | Sign with B instead of A when the calling number is not a destination of the domain (see [Attestation](#attestation)) |
| Sign All Routes | false | Add signing to the outbound routes of every domain, including global routes and new routes (see [Sign All Routes](#sign-all-routes)) |
| Debug | false | Return error text instead of the normal response |
| Log Retention (days) | 90 | Days to keep the identity logs; 0 keeps them forever (see [Logs](#logs)) |
| Trust List | *(none)* | PEM file of the STI-CA root certificates approved by the STI-PA, and the STI-PA root certificate. Inbound calls are reported as `No-TN-Validation` while it is empty (see [Inbound Verification](#inbound-verification)) |
| CRL URL | `https://authenticate-api.iconectiv.com/download/v1/crl` | Certificate revocation list of the STI-PA that inbound calls are checked against; revocation is not checked when empty (see [Revocation](#revocation)) |
| Brands File | *(none)* | Brands file for branded calling. Branding is off when empty |
| BCID Private Key File | `bcid_private.key` | File name of the BCID private key in the certificate directory |
| BCID Certificate File | `bcid_certificate.pem` | File name of the BCID certificate in the certificate directory |
| BCID Certificate URL | *(none)* | Public https URL of the BCID certificate. The BCID profile is off when empty |
| BCID Report Log | *(none)* | File that BCID signing events are written to |

**Allowed CIDR** is the only access control on sign.php and verify.php, which FreeSWITCH calls without a web session.
Requests from other addresses get an empty response. Keep it limited to the FreeSWITCH servers. Each entry must be an
IPv4 address with a mask, such as `10.0.0.5/32`. Empty and invalid entries are ignored, and when no valid entry is left
every request is refused, so a stray comma or a typo can never open signing to everyone. The checks list invalid
entries, and show an error when `127.0.0.1` is not allowed, since the actions added in Setup call sign.php and
verify.php on `127.0.0.1`. A refused request is logged as a failed sign or verify result with no domain, and in the
PHP error log, at most once a minute for each, so a call that was not signed or verified because of this setting can
be traced.

With **Debug** on, verify.php returns `PASSporT verification error <message>` instead of `TN-Validation-Failed`, and
both scripts print errors on the command line. Leave it off in production. The checks warn while it is on.

The settings are stored in **Advanced > Default Settings** under the `identity` category and can be edited there too.
Only default settings are used; domain and user settings for this category are ignored. A disabled setting uses its
default. Saving in Setup enables it again.

If APCu is enabled, the web server caches settings. Saving in Setup clears that cache. A change made outside the web
server, such as an upgrade run from the command line, may need the PHP-FPM service restarted.

#### Moving from config.conf

Earlier versions read these settings from `/etc/fusionpbx/config.conf` (`identity.path`, `identity.certificate_url`,
`identity.bcid.certificate_url` and so on). The upgrade copies any of them that are set to Default Settings and prints
which ones it copied. After that, config.conf is no longer read and the `identity.*` lines can be removed. The copy is
skipped once any identity setting has been changed, so it never replaces a value saved in Setup.

Until the upgrade has run, the settings are still read from config.conf, and the checks show a warning. Saving the
settings in Setup also moves them to Default Settings.

### Certificate

Production calls need the private key and certificate issued by your STI-CA. The key must be EC P-256
(prime256v1). Calls are signed with ES256.

When neither the key nor the certificate exists, **Generate Test Certificate** creates a self-signed P-256 key and
certificate, valid for one year. Carriers will not accept it. Use it only to try signing and verification. It never
replaces existing files.

### Import Certificate

**Import** saves the uploaded files to the certificate directory under the configured file names. You can import the
key, the certificate, or both.

- **Private key:** PEM format. An encrypted key is decrypted with the passphrase and saved without one, because
  sign.php cannot enter a passphrase. It is saved with mode 0600.
- **Certificate:** PEM or DER, optionally with the intermediate chain. The certificate that belongs to the key is
  saved first. Expired certificates and certificates without a P-256 key are refused.
- **Matching:** the key and certificate in use afterwards must belong together. A key that does not match the
  current certificate (or the reverse) is refused and nothing is replaced. Import a new pair together.
- **Backups:** a replaced file is kept as `<name>.<YYYYmmddHHMMSS>.bak` in the same directory (with `-1`, `-2`, …
  added if two imports happen in the same second). You are asked to confirm before files are replaced.
- **Limits:** each file can be at most 64 KB. The directory must be writable by the web server (see
  [Install](#install)).

Import only covers the default certificate. Copy the BCID key and certificate to the certificate directory by hand;
the BCID checks show whether they are present, match and are published at **BCID Certificate URL**.

Import does not publish the certificate. Carriers download it from the certificate URL to verify your calls, so copy
the certificate, including any chain, to a web server reachable from the internet over HTTPS. The URL must return
the certificate directly: receivers using this app do not follow redirects or accept http (see
[Inbound Verification](#inbound-verification)). The checks download the URL and report an error if it cannot be
reached, is not a certificate, or serves a different certificate.

### Test

Enter a calling number, a called number and an attestation level, then click **Test**. The page shows:

- the `Identity` header, or the signing error
- the result of verifying it with the local certificate
- the result of verifying it the way inbound calls are verified: the certificate URL is downloaded with the same
  restrictions, the certificate is checked against the trust list, and the numbers are checked. An http, redirecting
  or private URL, or a certificate that does not chain to the trust list, fails here the way it fails at a receiver
  running this app. A test certificate never chains to the trust list, so it shows `TN-Validation-Failed`, and without
  a trust list the result is `No-TN-Validation`

Nothing is logged.

### Inbound Verification

**Add** creates a global `identity-verify` dialplan in the public context (order 20, continue on). **Edit** opens it
in the dialplan editor and **Remove** deletes it.

```
<condition field="${sip_identity}" expression="^.+$">
	<action application="unset" data="curl_response_data"/>
	<action application="set" data="curl_timeout=6"/>
	<action application="curl" data="http://127.0.0.1/app/identity/verify.php POST identity=${url_encode(${sip_identity})}&call_uuid=${uuid}&source=${url_encode(${caller_id_number})}&destination=${url_encode(${destination_number})}"/>
	<action application="unset" data="curl_timeout"/>
	<action application="set" data="identity_verstat=${curl_response_data}"/>
</condition>
```

The upgrade adds `source` and `destination` to verification dialplans added by earlier versions or by hand, URL
encodes the `identity`, `source` and `destination` values that are not encoded yet, and adds the timeout and the
clearing of `curl_response_data` around the request, as for signing.

The request to verify.php may take 6 seconds (`curl_timeout`): enough for the certificate download (up to 2 seconds)
and, once an hour, the CRL and its signer certificate (up to 2 seconds each). If it takes longer, `identity_verstat`
is left empty and the call continues.

`identity_verstat` is set to one of:

| Value | Meaning |
|---|---|
| `TN-Validation-Passed-A`, `-B`, `-C` | Every check below passed, with that attestation |
| `TN-Validation-Failed` | A check failed: the header is malformed, too old or already used, its numbers are not those of the call, the certificate could not be downloaded, is not a STIR/SHAKEN certificate or does not chain to the trust list, or the signature is invalid |
| `No-TN-Validation` | The header could not be fully checked: there is no header, no usable trust list, or the dialplan did not send the call's numbers. Nothing in it was shown to be wrong, but it is not verified either |

Use the variable in later dialplans, for example to tag or route unverified calls. verify.php logs `No-TN-Validation`
results as failed, with the reason in the message.

A passed result means:

- the PASSporT is well formed, its `attest` is A, B or C, and its `iat` is recent (see below)
- its `orig` number is the caller ID number of the call and the called number of the call is one of its `dest`
  numbers. Both sides are compared as digits, with a leading 1 added to 10 digit North American numbers, so
  `2025550100`, `12025550100` and `+1 (202) 555-0100` match. A number that differs fails before anything is
  downloaded and does not use the replay claim below, so a header copied onto another call cannot block the real one
- the certificate at its `x5u` URL carries the TNAuthList extension that every STIR/SHAKEN certificate has, and
  chains, through any intermediate certificates served with it, to a certificate in the trust list, with every
  certificate in the chain within its validity dates. Only the trust list is trusted, not the system's web CA
  certificates
- neither that certificate nor an intermediate certificate served with it is in the STI-PA certificate revocation
  list (see [Revocation](#revocation))
- the signature is valid for that certificate, the same one the chain and revocation checks read: a download with a
  PEM block other than `CERTIFICATE`, such as `X509 CERTIFICATE`, is refused; text between the blocks is ignored

Rich Call Data (the JSON output below) is only returned when the result is a pass.

A PASSporT whose `iat` is more than 60 seconds from the server clock, older or newer, fails before anything is
downloaded, and so does one whose `attest` is not A, B or C. Keep the server clock synchronized with NTP.

When APCu is enabled, a PASSporT that was already presented is refused for the next two minutes, so a captured header
cannot be reused on other calls within that window. The check uses the signed payload, which has a unique `origid`
for each call, so re-encoding the signature does not get around it, and it is claimed before the certificate is
downloaded, so two calls with the same header at the same moment cannot both pass. A copy that fails verification
releases the claim, so a forged header cannot block the real call. Without APCu, a captured header can be reused for
up to 60 seconds.

The certificate is downloaded from the `x5u` URL in the PASSporT. That URL comes from the caller, so the download is
restricted:

- https on port 443 only, with the server certificate verified
- only public addresses: private, loopback, link-local, shared (CGNAT), benchmark, multicast, reserved and
  documentation ranges are refused, and so are IPv6 site-local addresses, the reserved IPv6 `::/8` block, and the IPv6
  ranges that can carry any IPv4 address (IPv4-compatible, SIIT IPv4-translated, 6to4, Teredo, NAT64)
- the address is checked after curl connects and before any request is sent (PHP 8.4 and later), so DNS cannot
  change it; on older PHP the host is resolved and checked first and the connection pinned to that address
- no redirects and no proxy
- at most 64 KB and only an HTTP 200 response, within 2 seconds including the DNS lookup (on PHP 8.4 and later)
- cached for 5 minutes when APCu is enabled, so repeated calls do not download it again

The PHP curl extension is required.

#### Trust List

Service providers registered with the STI-PA download the list of approved STI-CA certificates from the STI-PA. Save
their root certificates, one after the other in PEM format, in a file the web server user can read, for example
`/etc/fusionpbx/certs/sti_ca_list.pem`, and set **Trust List** in Setup > Settings. Add the STI-PA root certificate
(currently `STI-PA Root Certificate 2`), which the STI-PA also publishes, to the same file: the certificate revocation
list is signed by a certificate issued by it (see [Revocation](#revocation)). The Trust List check counts the
certificates and warns about expired ones. The STI-PA updates the list when STI-CAs are added or removed, so download
it again regularly. Add the root certificates of any other signers you accept, such as those of BCID certificates, to
the same file.

#### Revocation

The STI-PA publishes one certificate revocation list (CRL) for every STI-CA, at the **CRL URL** setting. It is an
indirect CRL: it is signed by the `STI-PA CRL` certificate, which is issued by the STI-PA root certificate, and each
entry names the issuer of the revoked certificate. The certificate of an inbound call, and each intermediate
certificate served with it, fails verification when it is in the CRL.

The CRL is used only when:

- its signer certificate, downloaded from the URL in the CRL (Authority Information Access), has the CRL issuer as its
  subject, may sign CRLs, and chains to the trust list, which therefore needs the STI-PA root certificate
- its signature is valid
- its next update time has not passed, and its this update time is not more than five minutes ahead of the clock
- it is not a delta CRL and has no critical extension that is not understood

Both downloads have the same restrictions as the certificate download above, and the CRL may be up to 4 MB. The CRL
is kept in the FusionPBX cache directory (`identity_crl_<uid>_<hash>.crl`) and downloaded again after an hour. When
that download fails, the kept CRL is used until its next update time and the failure is written to the PHP error log;
the download is tried again a minute later, not on every call. When no current CRL can be used, inbound calls with a
valid signature are reported as `No-TN-Validation` and the Revocation check in Setup shows why. Clear **CRL URL** to
turn the check off.

#### Limitations

verify.php does not yet:

- check the logo digest (`rcdi`), so a logo returned in the JSON output was signed as a URL but its content was not
  compared with the digest
- check that the TNAuthList of the certificate covers the calling number, which ATIS does not require for service
  provider certificates

## Branded Calling (Rich Call Data, experimental)

> **Experimental.** Branded calling is not part of the 1.0 feature set. It is off unless **Brands File** is set, and
> with it off sign.php skips every branding step. It has not been used with a BCID platform: issuing the BCID certificate,
> syncing vetted brands and sending signing reports are not implemented, and verify.php does not check the logo digest
> (`rcdi`). Its settings, files and behaviour may change in a later release.

The signer can add Rich Call Data ([RFC 9795](https://www.rfc-editor.org/rfc/rfc9795.html)) to the SHAKEN PASSporT
for vetted brands:

- a display name (`rcd.nam`)
- a logo (`rcd.icn`, with its `rcdi` integrity digest)
- a call reason (`crn`)

Branding is only added to calls signed with A attestation whose calling number is a destination of the call's
domain, the same ownership test as the [attestation check](#attestation). The ownership test applies even when the
attestation check is off, because the brands file is shared by every domain and a number's brand must not be
available to a domain that does not own the number. It is not applied on the command line. Branded calls can use a
separate certificate profile
(for example a BCID certificate), while all other calls keep the default certificate.

To turn it on, set **Brands File** in Setup > Settings. For the BCID profile, also set **BCID Certificate URL** and
copy the BCID key and certificate to the certificate directory under the **BCID Private Key File** and **BCID
Certificate File** names. A brand's `profile` is either:

- `bcid` (the default): branded only when **BCID Certificate URL** is set
- `default`: signed with the default certificate

Branding never blocks call authentication. The call is signed without branding, using the default certificate, if:

- the brand is missing, disabled, expired or invalid
- the brands file is unreadable, malformed or too large
- the BCID profile is not configured
- branded signing fails

Branded calls show the display name in the Brand column of the logs and are counted on the overview.

### Manage brands

There is no web page for brands yet. They are managed with `brand.php` on the command line, which uses the **Brands
File** setting:

```
cd /var/www/fusionpbx/app/identity
php brand.php add acme --name "Acme Dental" \
    --reason "Appointment reminder" --reason "Billing question" \
    --default-reason "Appointment reminder" --expires 2027-12-31 --vetting-ref VET-123
php brand.php logo acme https://cdn.example.com/acme.bmp
php brand.php assign 12025550100 acme
php brand.php list
php brand.php show 12025550100
```

| Command | Description |
|---|---|
| `list` | List brands and their numbers |
| `show <tn>` | Show the active brand for a calling number |
| `add <brand_id> --name ... [options]` | Create or replace a brand. Options: `--logo`, `--reason` (repeatable), `--default-reason`, `--profile bcid\|default`, `--expires YYYY-MM-DD`, `--vetting-ref`, `--disabled` |
| `logo <brand_id> <https url>` | Set the logo, download it and pin its digest |
| `refresh-logo <brand_id>` | Download the logo again and update its digest |
| `enable <brand_id>` / `disable <brand_id>` | Turn a brand on or off |
| `remove <brand_id>` | Remove a brand and its number assignments |
| `assign <tn> <brand_id>` / `unassign <tn>` | Assign or unassign a calling number |

The display name can be at most 35 characters, with no quotes, backslashes or control characters. Otherwise calls
are signed without branding. A brand stops being used at the end of its `--expires` date.

The `logo` command downloads the image once over HTTPS and pins its SHA-256 digest:

- TLS is verified, redirects are not followed, and the image can be at most 2 MB.
- No HTTP request is made during call setup.
- If the image at that URL changes, run `refresh-logo`, or terminating providers will reject the logo.

sign.php reads the brands file on every call that can be branded. The file:

- must be readable by the web server user
- can be at most 8 MB (about 330,000 numbers)
- is written through a temporary file, so a call never reads a partly written file

### Call reasons

Only vetted call reasons are signed. A requested reason that is not in the brand's list is replaced by the brand's
default reason, or left out. Setup adds signing without `call_reason`. To pass one, open the route in the dialplan
editor and add it to the curl action:

```
<action application="curl" data="http://127.0.0.1/app/identity/sign.php POST source=${url_encode(${effective_caller_id_number})}&destination=${url_encode(1$1)}&attest_level=A&domain_uuid=${domain_uuid}&call_uuid=${uuid}&call_reason=${url_encode(${bcid_call_reason})}"/>
```

Removing signing in Setup still finds and removes the edited action.

### Reporting

When **BCID Report Log** is set, each branded call signed with the BCID profile is appended to it as one JSON line:

- `origid`
- `iat`
- `orig`
- `dest`
- `brand_id`
- `crn`

The BCID participation documents define the platform reporting interface. Forward these events to it once that
interface is available. The log must be writable by the web server user. Events that cannot be written go to the PHP
error log instead.

## Reference

### sign.php

FreeSWITCH calls sign.php from the outbound route. It can also be called with curl from an address in **Allowed
CIDR**, or from the command line. Only a POST signs; a GET is answered 405. verify.php too answers only a POST.

| Parameter | Required | Description |
|---|---|---|
| `source` | yes | Calling number |
| `destination` | yes | Called number |
| `attest_level` | no | `A`, `B` or `C` (default `A`) |
| `call_reason` | no | Call reason for branded calls |
| `domain_uuid` | no | The domain whose destinations the attestation check reads, and logged with the result |
| `call_uuid` | no | Logged with the result, used to link to the call detail record, and to read `identity_origin` and `identity_origin_number` from the call |

Numbers are reduced to digits. A 10 digit North American number gets a leading 1. With the attestation check on, A is
lowered to B unless `source` is a destination of `domain_uuid`; the check is not applied on the command line. The
response is the complete
`Identity` header value. If signing fails the response is empty, so no header is sent and the call still proceeds.
The response is sent before the log is written, so logging does not delay the call.

```
curl -X POST -d "source=12025550100" -d "destination=13105551234" http://127.0.0.1/app/identity/sign.php
php /var/www/fusionpbx/app/identity/sign.php <source> <destination> [attest_level] [call_reason]
```

### verify.php

FreeSWITCH calls verify.php from the inbound verification dialplan.

| Parameter | Required | Description |
|---|---|---|
| `identity` | yes | The `Identity` header |
| `source` | yes | Caller ID number of the call, compared with `orig` |
| `destination` | yes | Called number of the call, compared with `dest` |
| `call_uuid` | no | Logged with the result and used to link to the call detail record |
| `format` | no | `json` for the JSON output below |

It returns one of the `identity_verstat` values above, or `No-TN-Validation` when `source` or `destination` is
missing. With `format=json` it returns the status, the attestation, and the Rich Call Data when the result is a pass:

```
{"verified":true,"verstat":"TN-Validation-Passed-A","attest":"A","rcd":{"nam":"Acme Dental","icn":"https://cdn.example.com/acme.bmp"},"crn":"Billing question","error":""}
```

From the command line: `php verify.php '<identity header>' [text|json] [calling number] [called number]`. Without the
numbers the result is `No-TN-Validation`.

### Permissions

| Permission | Allows |
|---|---|
| `identity_view` | The Identity overview and menu item |
| `identity_setup` | The Setup page checks, and the Routes page: adding or removing signing on the current domain's outbound routes |
| `identity_log_view` | The Logs page |
| `identity_log_delete` | Deleting log entries of the current domain (with `identity_log_all`, any entry) |
| `identity_log_all` | **Show All** on the overview and Logs pages: the results of every domain. Also needed to see verify results, which have no domain |
| `identity_setup_all` | Everything that applies to the whole platform: the settings, importing or generating the certificate, the Test, inbound verification, and **Show All** on the Routes page for the outbound routes of every domain and the global routes. Give it only to platform administrators |
| `identity_log_add` | Adding log entries (sign.php and verify.php grant it to themselves temporarily) |

All are granted to superadmin by default.
