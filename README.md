# Identity (STIR/SHAKEN) for FusionPBX

Signs outbound calls with a SIP `Identity` header and verifies the `Identity` header of inbound calls, as defined by
STIR/SHAKEN (RFC 8224, 8225, 8588). It can also add branded calling (Rich Call Data) for vetted brands.

FreeSWITCH asks the web server for each signature and verification with the dialplan `curl` application:

- `sign.php` returns the `Identity` header for an outbound call.
- `verify.php` checks the `Identity` header of an inbound call and returns a verification status.

Each result is written to the Identity logs. The web pages under **Advanced > Identity** show the results and
handle certificate setup and dialplan changes.

## Install

```
cd /var/www/fusionpbx/app
git clone git@github.com:NS-Innovations/fusionpbx-app-identity.git identity
cd /var/www/fusionpbx
php core/upgrade/upgrade.php --permissions
php core/upgrade/upgrade.php
```

The upgrade creates the `v_identity_logs` table, the permissions and the menu item. Log out and back in to pick up
the new permissions.

Then open **Advanced > Identity > Setup**:

1. Fix any errors in the checks table.
2. Import your certificate, or generate a test certificate.
3. Sign and verify a test call.
4. Add signing to the outbound routes.
5. Add inbound verification.

## Configuration

Settings are read from `/etc/fusionpbx/config.conf`. Setup shows the values in use but cannot change them; edit the
file on the server. Any key that is not set uses the default shown.

```
#identity - Stir/Shaken
identity.path = '/etc/fusionpbx/certs'
identity.private_key_name = 'identity_private.key'
identity.certificate_name = 'identity_certificate.pem'
identity.certificate_url = 'https://certs.example.com/identity_certificate.pem'
identity.allowed_cidr = '127.0.0.1/32'
identity.debug = false
```

| Key | Default | Purpose |
|---|---|---|
| `identity.path` | `/etc/fusionpbx/certs` | Directory of the private keys and certificates |
| `identity.private_key_name` | `identity_private.key` | Private key file name in `identity.path` |
| `identity.certificate_name` | `identity_certificate.pem` | Certificate file name in `identity.path` |
| `identity.certificate_url` | *(none, required)* | Public URL of the certificate, sent as `x5u` in every signature |
| `identity.allowed_cidr` | `127.0.0.1/32` | Addresses allowed to call sign.php and verify.php, comma separated, each with a mask |
| `identity.debug` | `false` | Return error text instead of the normal response (see below) |

The branded calling keys are listed under [Branded Calling](#branded-calling-rich-call-data).

`identity.allowed_cidr` is the only access control on sign.php and verify.php. They do not use a web session.
Requests from other addresses get an empty response. Keep it limited to the FreeSWITCH servers.

With `identity.debug` enabled, verify.php returns `PASSporT verification error <message>` instead of
`TN-Validation-Failed`, and both scripts print errors on the command line. Leave it off in production. Setup warns
while it is on.

## Certificates

Production calls need the private key and certificate issued by your STI-CA. The key must be EC P-256
(prime256v1). Calls are signed with ES256.

### Import

**Setup > Import Certificate** saves the uploaded files to `identity.path` under the configured names. You can import
the key, the certificate, or both.

- **Private key:** PEM format. An encrypted key is decrypted with the passphrase and saved without one, because
  sign.php cannot enter a passphrase. It is saved with mode 0600.
- **Certificate:** PEM or DER, optionally with the intermediate chain. The certificate that belongs to the key is
  saved first. Expired certificates and certificates without a P-256 key are refused.
- **Matching:** the key and certificate in use afterwards must belong together. A key that does not match the
  current certificate (or the reverse) is refused and nothing is replaced. Import a new pair together.
- **Backups:** a replaced file is kept as `<name>.<YYYYmmddHHMMSS>.bak` in the same directory (with `-1`, `-2`, …
  added if two imports happen in the same second).
- **Limits:** each file can be at most 64 KB. The directory must be writable by the web server user.

Import only covers the default certificate. Place the BCID key and certificate in `identity.path` by hand.

### Test certificate

When neither the key nor the certificate exists, **Setup > Generate Test Certificate** creates a self-signed
P-256 key and certificate, valid for one year. Carriers will not accept it. Use it only to try signing and
verification. It never replaces existing files.

### Certificate URL

Carriers download the certificate from `identity.certificate_url` to verify your calls. The app does not publish
it. Copy the certificate, including any chain, to a web server reachable from the internet, preferably over HTTPS.

Setup downloads the URL and compares it with the local certificate. It reports an error if the URL cannot be reached,
is not a certificate, or serves a different certificate. After importing a new certificate, update the URL.

## Web Interface

All pages are for superadmin by default.

### Overview

**Advanced > Identity** shows:

- signed and verified call counts by attestation level, with success rate and branded calls, for the last day, week
  or month
- activity by hour or day
- the most recent failures
- a summary of the certificate, certificate URL, outbound routes and inbound verification

### Logs

**Identity > Logs** lists every sign and verify result, newest first. You can:

- search by number, brand, certificate URL, origid, message or call UUID
- filter by type (sign or verify) and status
- sort by any column
- delete entries

A row with a call UUID links to its call detail record. Verify results have no domain because inbound calls are
verified before the domain is known, so they appear in every domain's view. **Show All** adds the sign results of the
other domains.

### Setup

**Identity > Setup** runs these checks:

- the log table
- `config.conf`
- the certificate directory
- the private key: present, readable, P-256, and permissions
- the certificate: present, readable, expired, self-signed, or within 30 days of expiry
- that the key matches the certificate
- the certificate URL
- the allowed CIDR
- debug mode
- outbound routes
- inbound verification

It can also:

- show the settings in use as `config.conf` lines
- generate a test certificate, or import the STI-CA key and certificate
- sign a test call and verify it, first with the local certificate, then by downloading the certificate URL the way a
  carrier would (nothing is logged)
- add or remove signing on each outbound route, or add it to every unsigned route with **Add All**
- add or remove the inbound verification dialplan

**Show All** lists the outbound routes of every domain instead of the current one.

## Outbound Signing

Adding signing to a route inserts these actions before its first bridge, then rebuilds the route XML and clears the
dialplan cache:

```
<action application="unset" data="sip_h_Identity"/>
<action application="curl" data="http://127.0.0.1/app/identity/sign.php POST source=${effective_caller_id_number}&destination=1$1&attest_level=A&domain_uuid=${domain_uuid}&call_uuid=${uuid}"/>
<action application="export" data="exclude_outgoing_extra_header=sip_h_Identity"/>
<action application="set" data="sip_h_Identity=${curl_response_data}"/>
```

- **Destination:** must be the number sent to the carrier. Setup suggests the end of the first bridge: `1$1` when
  the route captures 10 digits, `$1` when it captures 11. Change it in the route row before adding. It must have the
  form `[+][digits]$N`.
- **Attestation:** A (full), B (partial) or C (gateway). A is the default.
- **Add All:** signs every unsigned route in the list, using the destination and attestation shown in each row.
  It then reports how many routes were signed and how many failed.
- **Remove:** deletes the four actions. To add them by hand, use the same lines.

The `export exclude_outgoing_extra_header=sip_h_Identity` line is required. Without it FreeSWITCH sends the header
twice, and carriers reject the call with `400 Multiple Identity headers`.

### sign.php

| Parameter | Required | Description |
|---|---|---|
| `source` | yes | Calling number |
| `destination` | yes | Called number |
| `attest_level` | no | `A`, `B` or `C` (default `A`) |
| `call_reason` | no | Call reason for branded calls |
| `domain_uuid` | no | Logged with the result |
| `call_uuid` | no | Logged with the result and used to link to the call detail record |

Numbers are reduced to digits. A 10 digit North American number gets a leading 1. The response is the complete
`Identity` header value. If signing fails the response is empty, so no header is sent and the call still proceeds.
The response is sent before the log is written, so logging does not delay the call.

From the command line:

```
php /var/www/fusionpbx/app/identity/sign.php <source> <destination> [attest_level] [call_reason]
```

With curl, from an address in `identity.allowed_cidr`:

```
curl -X POST -d "source=12025550100" -d "destination=13105551234" http://127.0.0.1/app/identity/sign.php
```

## Inbound Verification

**Setup > Inbound Verification > Add** creates a global `identity-verify` dialplan in the public context (order 20,
continue on):

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
| `TN-Validation-Failed` | The header is malformed, the certificate could not be downloaded, or the signature is invalid |
| `No-TN-Validation` | verify.php was called without a header |

Use the variable in later dialplans, for example to tag or route unverified calls. The certificate is downloaded from
the `x5u` URL in the PASSporT on each call, with a 2 second timeout. Only http and https URLs are followed.

From the command line: `php verify.php '<identity header>' [json]`. With `format=json` (or the `json` argument)
verify.php returns the attestation and any Rich Call Data:

```
{"verified":true,"attest":"A","rcd":{"nam":"Acme Dental","icn":"https://cdn.example.com/acme.bmp"},"crn":"Billing question","error":""}
```

### Limitations

A passed result means the header is intact and signed by the certificate at its `x5u` URL. It does not mean the
signer is authorized. verify.php does not yet:

- validate the certificate chain against the STI-PA trust list or check certificate revocation
- check the PASSporT `iat` age
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

Branding is only added to calls signed with A attestation. Branded calls can use a separate certificate profile
(for example a BCID certificate), while all other calls keep the default certificate.

Branding never blocks call authentication. The call is signed without branding, using the default certificate, if:

- the brand is missing, disabled, expired or invalid
- the brands file is unreadable, malformed or too large
- the BCID profile is not configured
- branded signing fails

```
#identity - Branded calling
identity.brands_file = '/etc/fusionpbx/identity_brands.json'
identity.bcid.private_key_name = 'bcid_private.key'
identity.bcid.certificate_name = 'bcid_certificate.pem'
identity.bcid.certificate_url = 'https://certs.example.com/bcid_certificate.pem'
identity.bcid.report_log = '/var/log/fusionpbx/identity_bcid.log'
```

| Key | Default | Purpose |
|---|---|---|
| `identity.brands_file` | *(none)* | JSON file of brands and numbers. Branding is off when empty |
| `identity.bcid.private_key_name` | `bcid_private.key` | BCID private key in `identity.path` |
| `identity.bcid.certificate_name` | `bcid_certificate.pem` | BCID certificate in `identity.path` |
| `identity.bcid.certificate_url` | *(none)* | Public URL of the BCID certificate. The BCID profile is off when empty |
| `identity.bcid.report_log` | *(none)* | File that BCID signing events are appended to |

A brand's `profile` is either:

- `bcid` (the default): branded only when `identity.bcid.certificate_url` is set
- `default`: signed with the default certificate

### Manage brands

The brands file is managed with `brand.php`. It runs from the command line only; there is no web page for brands yet.

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
default reason, or left out. Setup adds signing without `call_reason`. To pass one, add it to the route's curl action
by hand:

```
<action application="curl" data="http://127.0.0.1/app/identity/sign.php POST source=${effective_caller_id_number}&destination=1$1&attest_level=A&domain_uuid=${domain_uuid}&call_uuid=${uuid}&call_reason=${url_encode(${bcid_call_reason})}"/>
```

Removing signing in Setup still finds and removes the edited action.

### Reporting

Each branded call signed with the BCID profile is appended to `identity.bcid.report_log` as one JSON line:

- `origid`
- `iat`
- `orig`
- `dest`
- `brand_id`
- `crn`

The BCID participation documents define the platform reporting interface. Forward these events to it once that
interface is available. The log must be writable by the web server user. Events that cannot be written go to the PHP
error log instead.

Branded calls show the display name in the Brand column of the logs and are counted on the overview.

## Permissions

| Permission | Allows |
|---|---|
| `identity_view` | The Identity overview and menu item |
| `identity_setup` | The Setup page: certificates, tests and dialplan changes |
| `identity_log_view` | The Logs page |
| `identity_log_delete` | Deleting log entries |
| `identity_log_all` | **Show All**: the logs, overview and outbound routes of every domain |
| `identity_log_add` | Adding log entries (sign.php and verify.php grant it to themselves temporarily) |

All are granted to superadmin by default.
