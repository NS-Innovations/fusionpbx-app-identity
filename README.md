# fusionpbx-app-identity

Integrate Stir/Shaken identity sign and verify

## Install
```
cd /var/www/fusionpbx/app
git clone https://github.com/fusionpbx/fusionpbx-app-identity.git identity
cd /var/www/fusionpbx
php core/upgrade/upgrade.php --permissions
php core/upgrade/upgrade.php
```

## /etc/fusionpbx/certs

Make sure your certificate directory exists.
```
mkdir -p /etc/fusionpbx/certs
```
## 

Once you have obtained your official keys, move them into the /etc/fusionpbx/certs directory.
By default the private key should be called `identity_private.key` and the certificate_name should be `identity_certificate.pem`

## /etc/fusionpbx/config.conf

The allowed_cidr allows multiple comma delimitted addresses. Remember to add /32 or other notation.
The certificate_url is used by the server receiving the call to verify the identity.

```
#identity - Stir/Shaken
identity.path = '/etc/fusionpbx/certs'
identity.private_key_name = 'identity_private.key'
identity.certificate_name = 'identity_certificate.pem'
identity.certificate_url = 'https://certs.urlto.local/public_key.pem'
identity.allowed_cidr = '127.0.0.1/32'
identity.debug = false
```

## Curl Command Line

The `source` is your Outbound Caller ID Number `destination` is the number being called. Make sure to update these numbers.
The numbers should include the country code the numbers must be e.164 format though the + is optional.
```
curl -X POST \
     -d "source=18005551212" \
     -d "destination=18005551313" \
     http://127.0.0.1/app/identity/sign.php
```

## Web interface

Advanced > Identity (superadmin) shows signed and verified call counts by attestation level, activity by hour or day,
and recent failures. Logs lists every sign and verify result and links to the call detail record.

Setup checks the key, certificate, certificate URL, allowed CIDR and dialplan, and can:

- generate a self-signed test certificate when no key or certificate exists (never replaces existing files)
- sign a test call and verify it with the local certificate and with the certificate URL
- add or remove signing on each outbound route
- add or remove the inbound verification dialplan

Settings stay in `/etc/fusionpbx/config.conf`; Setup shows the values in use but does not write the file.

## Outbound Routes

Setup adds these actions before the first bridge of the route. To add them by hand:
```
<action application="unset" data="sip_h_Identity"/>
<action application="curl" data="http://127.0.0.1/app/identity/sign.php POST source=${effective_caller_id_number}&destination=1$1&attest_level=A&domain_uuid=${domain_uuid}&call_uuid=${uuid}"/>
<action application="export" data="exclude_outgoing_extra_header=sip_h_Identity"/>
<action application="set" data="sip_h_Identity=${curl_response_data}"/>
```

The `export exclude_outgoing_extra_header=sip_h_Identity` line is required. Without it FreeSWITCH sends the header
twice, and carriers reject the call with `400 Multiple Identity headers`.

`destination` must be the number sent to the carrier. Use `1$1` when the route captures 10 digits and `$1` when it
captures 11. A 10 digit North American number is given the leading 1 automatically.

If signing fails sign.php returns an empty response, so no Identity header is sent. `domain_uuid` and `call_uuid` are
optional and are used for the logs.

## Inbound Verification

Setup adds a global `identity-verify` dialplan to the public context:
```
<condition field="${sip_identity}" expression="^.+$">
	<action application="curl" data="http://127.0.0.1/app/identity/verify.php POST identity=${url_encode(${sip_identity})}&call_uuid=${uuid}"/>
	<action application="set" data="identity_verstat=${curl_response_data}"/>
</condition>
```

`identity_verstat` is `TN-Validation-Passed-A`, `-B`, `-C` or `TN-Validation-Failed`. verify.php checks the signature
against the certificate at the `x5u` URL in the PASSporT; it does not yet validate the certificate chain against the
STI-PA trust list, so a passed result means the header is intact, not that the signer is authorized.

## Branded Calling (Rich Call Data)

The signer can add Rich Call Data ([RFC 9795](https://www.rfc-editor.org/rfc/rfc9795.html)) to the SHAKEN PASSporT for vetted brands: a display name (`rcd.nam`), a logo (`rcd.icn` with its `rcdi` integrity digest) and a call reason (`crn`). Branding is only added to calls signed with full (A) attestation. Branded calls can be signed with a separate certificate profile (for example a BCID certificate) while all other calls keep using the default certificate.

Branding never blocks call authentication. If the brand is missing, disabled, expired or invalid, or if the BCID profile is not configured, the call is signed without branding using the default certificate.

### /etc/fusionpbx/config.conf

```
#identity - Branded calling
identity.brands_file = '/etc/fusionpbx/identity_brands.json'
identity.bcid.private_key_name = 'bcid_private.key'
identity.bcid.certificate_name = 'bcid_certificate.pem'
identity.bcid.certificate_url = 'https://certs.urlto.local/bcid_public.pem'
identity.bcid.report_log = '/var/log/fusionpbx/identity_bcid.log'
```

Branding is disabled when `identity.brands_file` is empty. Brands with `profile` set to `bcid` (the default) are only branded when `identity.bcid.certificate_url` is set. Brands with `profile` set to `default` are signed with the default certificate.

### Manage brands

Brands and number assignments are stored in the brands file and managed with `brand.php` (command line only):

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

The `logo` command downloads the image once and pins its SHA-256 digest, so no HTTP request is made during call setup. If the image at that URL changes, run `php brand.php refresh-logo acme`, or terminating providers will reject the logo.

Only vetted call reasons are signed. A requested reason that is not in the brand's list is replaced by the brand's default reason, or omitted.

### Outbound routes

Pass a call reason with the `call_reason` parameter (optional):

```
<action application="unset" data="sip_h_Identity"/>
<action application="curl" data="http://127.0.0.1/app/identity/sign.php POST source=${effective_caller_id_number}&destination=1$1&call_reason=${url_encode(${bcid_call_reason})}"/>
<action application="export" data="exclude_outgoing_extra_header=sip_h_Identity"/>
<action application="set" data="sip_h_Identity=${curl_response_data}"/>
```

From the command line: `php sign.php 12025550100 13105551234 A "Billing question"`

### Verify

`verify.php` with `format=json` (or a second command line argument `json`) returns the attestation level and any Rich Call Data:

```
{"verified":true,"attest":"A","rcd":{"nam":"Acme Dental","icn":"https://cdn.example.com/acme.bmp"},"crn":"Billing question","error":""}
```

### Reporting

Each branded call signed with the BCID profile is written as one JSON line to `identity.bcid.report_log` (origid, iat, orig, dest, brand_id, crn). The BCID platform reporting interface is defined in the BCID participation documents; forward these events to it once that interface is available.
