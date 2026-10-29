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
