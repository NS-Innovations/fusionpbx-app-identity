# Identity (STIR/SHAKEN) for FusionPBX

Signs outbound calls with a SIP `Identity` header and verifies the `Identity` header of inbound calls, as defined by
STIR/SHAKEN (RFC 8224, 8225, 8588). It can also add branded calling (Rich Call Data) for vetted brands.

Everything is managed in the web interface under **Advanced > Identity**:

- **Identity** (overview): call counts, activity and recent failures
- **Logs**: every sign and verify result
- **Setup**: checks, settings, certificates, a test call, and the dialplan for outbound routes and inbound verification

The pages are for superadmin by default. The only command-line tool is `brand.php`, which manages branded calling
brands.

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
2. **Settings:** set the certificate URL. Adjust the certificate directory, file names or allowed CIDR if needed, then
   click **Save**.
3. **Certificate:** import the private key and certificate from your STI-CA. To try things out first, click
   **Generate Test Certificate** instead.
4. **Certificate URL:** copy the certificate to the web server behind the certificate URL. The checks report a
   mismatch until the URL serves the same certificate.
5. **Test:** enter a calling and called number and click **Test**. The call is signed, then verified with the local
   certificate and by downloading the certificate URL, the way a carrier would.
6. **Outbound Routes:** click **Add** on each route, or **Add All**, to sign its calls.
7. **Inbound Verification:** click **Add** to verify the `Identity` header of inbound calls.

Then watch the results on the Identity overview and the Logs page.

## Overview

**Advanced > Identity** shows:

- signed and verified call counts by attestation level, with the success rate and branded calls, for the last day,
  week or month
- activity by hour or day
- the most recent failures
- a summary of the certificate, certificate URL, outbound routes and inbound verification

The **Logs** and **Setup** buttons open the other pages.

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

## Setup

### Checks

The checks table shows ok, warning or error, with details, for:

- the log table
- the settings (a warning while they are still read from `config.conf`, see below)
- the certificate directory: present, and writable by the web server
- the private key: present, readable, P-256, and permissions
- the certificate: present, readable, expired, self-signed, or within 30 days of expiry
- that the key matches the certificate
- the certificate URL: set, https, reachable, and serving the same certificate
- the allowed CIDR
- the attestation check (a warning when it is off)
- debug mode
- outbound routes
- inbound verification
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
| Debug | false | Return error text instead of the normal response |
| Log Retention (days) | 90 | Days to keep the identity logs; 0 keeps them forever (see [Logs](#logs)) |
| Brands File | *(none)* | Brands file for branded calling. Branding is off when empty |
| BCID Private Key File | `bcid_private.key` | File name of the BCID private key in the certificate directory |
| BCID Certificate File | `bcid_certificate.pem` | File name of the BCID certificate in the certificate directory |
| BCID Certificate URL | *(none)* | Public https URL of the BCID certificate. The BCID profile is off when empty |
| BCID Report Log | *(none)* | File that BCID signing events are written to |

**Allowed CIDR** is the only access control on sign.php and verify.php, which FreeSWITCH calls without a web session.
Requests from other addresses get an empty response. Keep it limited to the FreeSWITCH servers. Each entry must be an
IPv4 address with a mask, such as `10.0.0.5/32`. Empty and invalid entries are ignored, and when no valid entry is left
every request is refused, so a stray comma or a typo can never open signing to everyone. The checks list invalid
entries.

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
- the result of verifying it by downloading the certificate URL with the same restrictions as inbound verification,
  so an http, redirecting or private URL fails here the way it fails at a receiver running this app

Nothing is logged.

### Outbound Routes

Each outbound route with a bridge is listed with whether it signs calls. For a route that does not sign yet, check the
**Destination** and **Attestation**, then click **Add**. **Add All** does the same for every unsigned route in the
list, using the values in each row. It then reports how many routes were signed and how many failed. **Remove** takes
signing off a route. Only the routes of the current domain can be changed, unless **Show All** is used, which needs the
`identity_setup_all` permission.

- **Destination:** the number sent to the carrier. Setup suggests the end of the first bridge: `1$1` when the route
  captures 10 digits, `$1` when it captures 11. It must have the form `[+][digits]$N`.
- **Attestation:** A (full), B (partial) or C (gateway). A is the default. It is the highest level the route signs
  with; see [Attestation](#attestation).

Adding signing inserts these actions before the first bridge of the route, then rebuilds the route XML and clears the
dialplan cache:

```
<action application="unset" data="sip_h_Identity"/>
<action application="curl" data="http://127.0.0.1/app/identity/sign.php POST source=${url_encode(${effective_caller_id_number})}&destination=${url_encode(1$1)}&attest_level=A&domain_uuid=${domain_uuid}&call_uuid=${uuid}"/>
<action application="export" data="exclude_outgoing_extra_header=sip_h_Identity"/>
<action application="set" data="sip_h_Identity=${curl_response_data}"/>
```

The `export exclude_outgoing_extra_header=sip_h_Identity` line is required. Without it FreeSWITCH sends the header
twice, and carriers reject the call with `400 Multiple Identity headers`. The route name links to the dialplan
editor if you need to change the actions.

The caller ID, the dialed number and any call reason are URL encoded so they cannot add parameters, such as a call
reason, to the signing request. Earlier versions sent them without encoding; the upgrade updates those routes,
including signing actions edited by hand, and prints how many it changed. It also adds `domain_uuid=${domain_uuid}`
to actions that do not send it, since the attestation check needs the domain of the call.

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

A lowered call is logged as signed with B, with the reason in the message. If a customer may use a number that is not
one of their destinations, for example a number hosted elsewhere with a letter of authorization, add it as an inbound
destination of their domain, or turn the check off.

### Inbound Verification

**Add** creates a global `identity-verify` dialplan in the public context (order 20, continue on). **Edit** opens it
in the dialplan editor and **Remove** deletes it.

```
<condition field="${sip_identity}" expression="^.+$">
	<action application="curl" data="http://127.0.0.1/app/identity/verify.php POST identity=${url_encode(${sip_identity})}&call_uuid=${uuid}"/>
	<action application="set" data="identity_verstat=${curl_response_data}"/>
</condition>
```

`identity_verstat` is set to one of:

| Value | Meaning |
|---|---|
| `TN-Validation-Passed-A`, `-B`, `-C` | The signature is valid, with that attestation |
| `TN-Validation-Failed` | The header is malformed or too old, the certificate could not be downloaded, or the signature is invalid |
| `No-TN-Validation` | verify.php was called without a header |

Use the variable in later dialplans, for example to tag or route unverified calls.

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

#### Limitations

A passed result means the header is recent, intact, and signed by the certificate at its `x5u` URL. It does not mean
the signer is authorized. verify.php does not yet:

- validate the certificate chain against the STI-PA trust list or check certificate revocation
- check that `orig` and `dest` match the call
- check the logo digest (`rcdi`)

Anyone can sign a PASSporT with any number or display name and it will verify. Do not show verified Rich Call Data
to users, or treat `TN-Validation-Passed` as proof of identity, until these checks exist.

## Branded Calling (Rich Call Data)

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
CIDR**, or from the command line.

| Parameter | Required | Description |
|---|---|---|
| `source` | yes | Calling number |
| `destination` | yes | Called number |
| `attest_level` | no | `A`, `B` or `C` (default `A`) |
| `call_reason` | no | Call reason for branded calls |
| `domain_uuid` | no | Logged with the result |
| `call_uuid` | no | Logged with the result and used to link to the call detail record |

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

FreeSWITCH calls verify.php from the inbound verification dialplan with the `identity` parameter. It returns one of the
`identity_verstat` values above. With `format=json` it returns the attestation and any Rich Call Data:

```
{"verified":true,"attest":"A","rcd":{"nam":"Acme Dental","icn":"https://cdn.example.com/acme.bmp"},"crn":"Billing question","error":""}
```

From the command line: `php verify.php '<identity header>' [json]`.

### Permissions

| Permission | Allows |
|---|---|
| `identity_view` | The Identity overview and menu item |
| `identity_setup` | The Setup page: the checks, and adding or removing signing on the current domain's outbound routes |
| `identity_log_view` | The Logs page |
| `identity_log_delete` | Deleting log entries of the current domain (with `identity_log_all`, any entry) |
| `identity_log_all` | **Show All** on the overview and Logs pages: the results of every domain. Also needed to see verify results, which have no domain |
| `identity_setup_all` | Everything that applies to the whole platform: the settings, importing or generating the certificate, the Test, inbound verification, and **Show All** for the outbound routes of every domain. Give it only to platform administrators |
| `identity_log_add` | Adding log entries (sign.php and verify.php grant it to themselves temporarily) |

All are granted to superadmin by default.
