<?php

$text['title-identity']['en-us'] = "Identity";
$text['title-identity']['en-gb'] = "Identity";

$text['title-identity_logs']['en-us'] = "Identity Logs";
$text['title-identity_logs']['en-gb'] = "Identity Logs";

$text['title-identity_setup']['en-us'] = "Identity Setup";
$text['title-identity_setup']['en-gb'] = "Identity Setup";

$text['description-identity']['en-us'] = "STIR/SHAKEN signing of outbound calls and verification of inbound calls.";
$text['description-identity']['en-gb'] = "STIR/SHAKEN signing of outbound calls and verification of inbound calls.";

$text['description-identity_logs']['en-us'] = "Each call signed by sign.php and each Identity header checked by verify.php. Verify results are not assigned to a domain because inbound calls are verified before the domain is known.";
$text['description-identity_logs']['en-gb'] = "Each call signed by sign.php and each Identity header checked by verify.php. Verify results are not assigned to a domain because inbound calls are verified before the domain is known.";

$text['description-identity_setup']['en-us'] = "Checks the STIR/SHAKEN configuration and adds signing and verification to the dialplan.";
$text['description-identity_setup']['en-gb'] = "Checks the STIR/SHAKEN configuration and adds signing and verification to the dialplan.";

$text['header-activity']['en-us'] = "Activity";
$text['header-activity']['en-gb'] = "Activity";

$text['header-configuration']['en-us'] = "Configuration";
$text['header-configuration']['en-gb'] = "Configuration";

$text['header-recent_failures']['en-us'] = "Recent Failures";
$text['header-recent_failures']['en-gb'] = "Recent Failures";

$text['header-config']['en-us'] = "config.conf";
$text['header-config']['en-gb'] = "config.conf";

$text['description-config']['en-us'] = "These are the settings in use. Values that are not in /etc/fusionpbx/config.conf use the default. Add or change them in config.conf on the server; they cannot be changed here.";
$text['description-config']['en-gb'] = "These are the settings in use. Values that are not in /etc/fusionpbx/config.conf use the default. Add or change them in config.conf on the server; they cannot be changed here.";

$text['header-certificate']['en-us'] = "Certificate";
$text['header-certificate']['en-gb'] = "Certificate";

$text['description-certificate']['en-us'] = "Production calls need the private key and certificate issued by your STI-CA. A self-signed test certificate can be generated to try signing and verification. It will not be accepted by carriers. Existing files are never replaced.";
$text['description-certificate']['en-gb'] = "Production calls need the private key and certificate issued by your STI-CA. A self-signed test certificate can be generated to try signing and verification. It will not be accepted by carriers. Existing files are never replaced.";

$text['header-test']['en-us'] = "Test";
$text['header-test']['en-gb'] = "Test";

$text['description-test']['en-us'] = "Signs a call with the private key, then verifies the result with the local certificate and with the certificate URL the way a carrier would. Nothing is logged.";
$text['description-test']['en-gb'] = "Signs a call with the private key, then verifies the result with the local certificate and with the certificate URL the way a carrier would. Nothing is logged.";

$text['header-outbound']['en-us'] = "Outbound Routes";
$text['header-outbound']['en-gb'] = "Outbound Routes";

$text['description-outbound']['en-us'] = "Adding signing inserts the actions that request a SIP Identity header from sign.php before the first bridge of the route. The destination is the number sent to the carrier, usually the same as the end of the bridge, such as 1\$1 or \$1.";
$text['description-outbound']['en-gb'] = "Adding signing inserts the actions that request a SIP Identity header from sign.php before the first bridge of the route. The destination is the number sent to the carrier, usually the same as the end of the bridge, such as 1\$1 or \$1.";

$text['header-inbound']['en-us'] = "Inbound Verification";
$text['header-inbound']['en-gb'] = "Inbound Verification";

$text['description-inbound']['en-us'] = "Adds a global dialplan in the public context that sends the Identity header of each inbound call to verify.php and stores the result in the identity_verstat channel variable.";
$text['description-inbound']['en-gb'] = "Adds a global dialplan in the public context that sends the Identity header of each inbound call to verify.php and stores the result in the identity_verstat channel variable.";

$text['button-logs']['en-us'] = "Logs";
$text['button-logs']['en-gb'] = "Logs";

$text['button-test']['en-us'] = "Test";
$text['button-test']['en-gb'] = "Test";

$text['button-generate_certificate']['en-us'] = "Generate Test Certificate";
$text['button-generate_certificate']['en-gb'] = "Generate Test Certificate";

$text['confirm-remove_signing']['en-us'] = "Remove signing from this route?";
$text['confirm-remove_signing']['en-gb'] = "Remove signing from this route?";

$text['confirm-remove_verify']['en-us'] = "Remove inbound verification?";
$text['confirm-remove_verify']['en-gb'] = "Remove inbound verification?";

$text['label-calls_sign']['en-us'] = "Signed Calls";
$text['label-calls_sign']['en-gb'] = "Signed Calls";

$text['label-calls_verify']['en-us'] = "Verified Calls";
$text['label-calls_verify']['en-gb'] = "Verified Calls";

$text['label-success']['en-us'] = "Success";
$text['label-success']['en-gb'] = "Success";

$text['label-failed']['en-us'] = "Failed";
$text['label-failed']['en-gb'] = "Failed";

$text['label-success_rate']['en-us'] = "Success Rate";
$text['label-success_rate']['en-gb'] = "Success Rate";

$text['label-period']['en-us'] = "Period";
$text['label-period']['en-gb'] = "Period";

$text['label-sign_failed']['en-us'] = "Sign Failed";
$text['label-sign_failed']['en-gb'] = "Sign Failed";

$text['label-verify_failed']['en-us'] = "Verify Failed";
$text['label-verify_failed']['en-gb'] = "Verify Failed";

$text['label-attest']['en-us'] = "Attestation";
$text['label-attest']['en-gb'] = "Attestation";

$text['label-source']['en-us'] = "Source";
$text['label-source']['en-gb'] = "Source";

$text['label-destination']['en-us'] = "Destination";
$text['label-destination']['en-gb'] = "Destination";

$text['label-certificate_url']['en-us'] = "Certificate URL";
$text['label-certificate_url']['en-gb'] = "Certificate URL";

$text['label-message']['en-us'] = "Message";
$text['label-message']['en-gb'] = "Message";

$text['label-signing']['en-us'] = "Signing";
$text['label-signing']['en-gb'] = "Signing";

$text['label-routes_signing']['en-us'] = "routes signing";
$text['label-routes_signing']['en-gb'] = "routes signing";

$text['label-expires']['en-us'] = "Expires";
$text['label-expires']['en-gb'] = "Expires";

$text['label-days']['en-us'] = "days";
$text['label-days']['en-gb'] = "days";

$text['label-self_signed']['en-us'] = "self-signed";
$text['label-self_signed']['en-gb'] = "self-signed";

$text['label-not_configured']['en-us'] = "Not configured";
$text['label-not_configured']['en-gb'] = "Not configured";

$text['label-check']['en-us'] = "Check";
$text['label-check']['en-gb'] = "Check";

$text['label-status_ok']['en-us'] = "OK";
$text['label-status_ok']['en-gb'] = "OK";

$text['label-status_warning']['en-us'] = "Warning";
$text['label-status_warning']['en-gb'] = "Warning";

$text['label-status_error']['en-us'] = "Error";
$text['label-status_error']['en-gb'] = "Error";

$text['label-check_logs']['en-us'] = "Log Table";
$text['label-check_logs']['en-gb'] = "Log Table";

$text['label-check_config']['en-us'] = "Config";
$text['label-check_config']['en-gb'] = "Config";

$text['label-check_path']['en-us'] = "Certificate Directory";
$text['label-check_path']['en-gb'] = "Certificate Directory";

$text['label-check_private_key']['en-us'] = "Private Key";
$text['label-check_private_key']['en-gb'] = "Private Key";

$text['label-check_certificate']['en-us'] = "Certificate";
$text['label-check_certificate']['en-gb'] = "Certificate";

$text['label-check_key_pair']['en-us'] = "Key Pair";
$text['label-check_key_pair']['en-gb'] = "Key Pair";

$text['label-check_certificate_url']['en-us'] = "Certificate URL";
$text['label-check_certificate_url']['en-gb'] = "Certificate URL";

$text['label-check_cidr']['en-us'] = "Allowed CIDR";
$text['label-check_cidr']['en-gb'] = "Allowed CIDR";

$text['label-check_debug']['en-us'] = "Debug";
$text['label-check_debug']['en-gb'] = "Debug";

$text['label-check_outbound']['en-us'] = "Outbound Signing";
$text['label-check_outbound']['en-gb'] = "Outbound Signing";

$text['label-check_inbound']['en-us'] = "Inbound Verification";
$text['label-check_inbound']['en-gb'] = "Inbound Verification";

$text['label-check_sign']['en-us'] = "Identity Header";
$text['label-check_sign']['en-gb'] = "Identity Header";

$text['label-check_verify_local']['en-us'] = "Local Certificate";
$text['label-check_verify_local']['en-gb'] = "Local Certificate";

$text['label-check_verify_remote']['en-us'] = "Certificate URL";
$text['label-check_verify_remote']['en-gb'] = "Certificate URL";

$text['option-sign']['en-us'] = "Sign";
$text['option-sign']['en-gb'] = "Sign";

$text['option-verify']['en-us'] = "Verify";
$text['option-verify']['en-gb'] = "Verify";

$text['option-signed']['en-us'] = "Signed";
$text['option-signed']['en-gb'] = "Signed";

$text['option-passed']['en-us'] = "Passed";
$text['option-passed']['en-gb'] = "Passed";

$text['option-failed']['en-us'] = "Failed";
$text['option-failed']['en-gb'] = "Failed";

$text['option-period_day']['en-us'] = "Last 24 Hours";
$text['option-period_day']['en-gb'] = "Last 24 Hours";

$text['option-period_week']['en-us'] = "Last 7 Days";
$text['option-period_week']['en-gb'] = "Last 7 Days";

$text['option-period_month']['en-us'] = "Last 30 Days";
$text['option-period_month']['en-gb'] = "Last 30 Days";

$text['message-run_upgrade']['en-us'] = "The v_identity_logs table does not exist. Run Advanced > Upgrade > Schema or php core/upgrade/upgrade.php.";
$text['message-run_upgrade']['en-gb'] = "The v_identity_logs table does not exist. Run Advanced > Upgrade > Schema or php core/upgrade/upgrade.php.";

$text['message-config_default']['en-us'] = "Using the default for";
$text['message-config_default']['en-gb'] = "Using the default for";

$text['message-path_missing']['en-us'] = "Directory does not exist:";
$text['message-path_missing']['en-gb'] = "Directory does not exist:";

$text['message-file_missing']['en-us'] = "File does not exist:";
$text['message-file_missing']['en-gb'] = "File does not exist:";

$text['message-file_unreadable']['en-us'] = "File cannot be read by the web server:";
$text['message-file_unreadable']['en-gb'] = "File cannot be read by the web server:";

$text['message-certificate_unreadable']['en-us'] = "File is not a valid certificate:";
$text['message-certificate_unreadable']['en-gb'] = "File is not a valid certificate:";

$text['message-key_curve']['en-us'] = "ES256 requires a prime256v1 (P-256) key. Found:";
$text['message-key_curve']['en-gb'] = "ES256 requires a prime256v1 (P-256) key. Found:";

$text['message-key_mode']['en-us'] = "The private key can be read by all users. Set the permissions to 0600. Current:";
$text['message-key_mode']['en-gb'] = "The private key can be read by all users. Set the permissions to 0600. Current:";

$text['message-key_mismatch']['en-us'] = "The private key does not belong to the certificate.";
$text['message-key_mismatch']['en-gb'] = "The private key does not belong to the certificate.";

$text['message-certificate_expired']['en-us'] = "The certificate has expired.";
$text['message-certificate_expired']['en-gb'] = "The certificate has expired.";

$text['message-certificate_expiring']['en-us'] = "The certificate expires soon.";
$text['message-certificate_expiring']['en-gb'] = "The certificate expires soon.";

$text['message-certificate_self_signed']['en-us'] = "Self-signed, for testing only.";
$text['message-certificate_self_signed']['en-gb'] = "Self-signed, for testing only.";

$text['message-url_missing']['en-us'] = "Set identity.certificate_url in config.conf. Receivers download the certificate from this URL to verify your calls.";
$text['message-url_missing']['en-gb'] = "Set identity.certificate_url in config.conf. Receivers download the certificate from this URL to verify your calls.";

$text['message-url_scheme']['en-us'] = "The certificate URL must use https:";
$text['message-url_scheme']['en-gb'] = "The certificate URL must use https:";

$text['message-url_http']['en-us'] = "Carriers require https:";
$text['message-url_http']['en-gb'] = "Carriers require https:";

$text['message-url_unreachable']['en-us'] = "The certificate URL could not be downloaded:";
$text['message-url_unreachable']['en-gb'] = "The certificate URL could not be downloaded:";

$text['message-url_not_certificate']['en-us'] = "The certificate URL does not return a certificate:";
$text['message-url_not_certificate']['en-gb'] = "The certificate URL does not return a certificate:";

$text['message-url_mismatch']['en-us'] = "The certificate URL returns a different certificate than the local one:";
$text['message-url_mismatch']['en-gb'] = "The certificate URL returns a different certificate than the local one:";

$text['message-cidr_invalid']['en-us'] = "Invalid CIDR, use the address/mask format such as 127.0.0.1/32:";
$text['message-cidr_invalid']['en-gb'] = "Invalid CIDR, use the address/mask format such as 127.0.0.1/32:";

$text['message-cidr_open']['en-us'] = "Any address can request a signature:";
$text['message-cidr_open']['en-gb'] = "Any address can request a signature:";

$text['message-debug_enabled']['en-us'] = "identity.debug is enabled. Error messages are returned to FreeSWITCH.";
$text['message-debug_enabled']['en-gb'] = "identity.debug is enabled. Error messages are returned to FreeSWITCH.";

$text['message-no_routes']['en-us'] = "No outbound routes with a bridge were found.";
$text['message-no_routes']['en-gb'] = "No outbound routes with a bridge were found.";

$text['message-verify_missing']['en-us'] = "Inbound calls are not verified.";
$text['message-verify_missing']['en-gb'] = "Inbound calls are not verified.";

$text['message-certificate_exists']['en-us'] = "A private key or certificate already exists.";
$text['message-certificate_exists']['en-gb'] = "A private key or certificate already exists.";

$text['message-certificate_generated']['en-us'] = "Test certificate generated.";
$text['message-certificate_generated']['en-gb'] = "Test certificate generated.";

$text['message-certificate_failed']['en-us'] = "The test certificate could not be generated.";
$text['message-certificate_failed']['en-gb'] = "The test certificate could not be generated.";

$text['message-signing_added']['en-us'] = "Signing added to the route.";
$text['message-signing_added']['en-gb'] = "Signing added to the route.";

$text['message-signing_failed']['en-us'] = "Signing could not be added. The route must have a bridge and the destination must look like 1\$1 or \$1.";
$text['message-signing_failed']['en-gb'] = "Signing could not be added. The route must have a bridge and the destination must look like 1\$1 or \$1.";

$text['message-signing_removed']['en-us'] = "Signing removed from the route.";
$text['message-signing_removed']['en-gb'] = "Signing removed from the route.";

$text['message-verify_added']['en-us'] = "Inbound verification added.";
$text['message-verify_added']['en-gb'] = "Inbound verification added.";

$text['message-verify_removed']['en-us'] = "Inbound verification removed.";
$text['message-verify_removed']['en-gb'] = "Inbound verification removed.";

?>
