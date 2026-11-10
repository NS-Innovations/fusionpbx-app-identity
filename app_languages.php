<?php

$text['title-identity']['en-us'] = "Identity";
$text['title-identity']['en-gb'] = "Identity";

$text['title-identity_logs']['en-us'] = "Identity Logs";
$text['title-identity_logs']['en-gb'] = "Identity Logs";

$text['title-identity_setup']['en-us'] = "Identity Setup";
$text['title-identity_setup']['en-gb'] = "Identity Setup";

$text['title-identity_routes']['en-us'] = "Identity Routes";
$text['title-identity_routes']['en-gb'] = "Identity Routes";

$text['description-identity']['en-us'] = "STIR/SHAKEN signing of outbound calls and verification of inbound calls.";
$text['description-identity']['en-gb'] = "STIR/SHAKEN signing of outbound calls and verification of inbound calls.";

$text['description-identity_logs']['en-us'] = "Each call signed by sign.php and each Identity header checked by verify.php. Verify results are not assigned to a domain because inbound calls are verified before the domain is known.";
$text['description-identity_logs']['en-gb'] = "Each call signed by sign.php and each Identity header checked by verify.php. Verify results are not assigned to a domain because inbound calls are verified before the domain is known.";

$text['description-identity_setup']['en-us'] = "Checks the STIR/SHAKEN configuration, manages the settings and certificates, and adds signing and verification to the dialplan.";
$text['description-identity_setup']['en-gb'] = "Checks the STIR/SHAKEN configuration, manages the settings and certificates, and adds signing and verification to the dialplan.";

$text['header-settings']['en-us'] = "Settings";
$text['header-settings']['en-gb'] = "Settings";

$text['description-settings']['en-us'] = "These settings apply to the whole server and are used by sign.php and verify.php right away. They are stored in Default Settings under the Identity category.";
$text['description-settings']['en-gb'] = "These settings apply to the whole server and are used by sign.php and verify.php right away. They are stored in Default Settings under the Identity category.";

$text['header-settings_branded']['en-us'] = "Branded Calling (Experimental)";
$text['header-settings_branded']['en-gb'] = "Branded Calling (Experimental)";

$text['label-setting_path']['en-us'] = "Certificate Directory";
$text['label-setting_path']['en-gb'] = "Certificate Directory";

$text['description-setting_path']['en-us'] = "Directory of the private keys and certificates, such as /etc/fusionpbx/certs. The web server user must be able to read it.";
$text['description-setting_path']['en-gb'] = "Directory of the private keys and certificates, such as /etc/fusionpbx/certs. The web server user must be able to read it.";

$text['label-setting_private_key_name']['en-us'] = "Private Key File";
$text['label-setting_private_key_name']['en-gb'] = "Private Key File";

$text['description-setting_private_key_name']['en-us'] = "File name of the private key in the certificate directory.";
$text['description-setting_private_key_name']['en-gb'] = "File name of the private key in the certificate directory.";

$text['label-setting_certificate_name']['en-us'] = "Certificate File";
$text['label-setting_certificate_name']['en-gb'] = "Certificate File";

$text['description-setting_certificate_name']['en-us'] = "File name of the certificate in the certificate directory.";
$text['description-setting_certificate_name']['en-gb'] = "File name of the certificate in the certificate directory.";

$text['label-setting_certificate_url']['en-us'] = "Certificate URL";
$text['label-setting_certificate_url']['en-gb'] = "Certificate URL";

$text['description-setting_certificate_url']['en-us'] = "Public https URL of the certificate. It is sent in every signature, and receivers download the certificate from it.";
$text['description-setting_certificate_url']['en-gb'] = "Public https URL of the certificate. It is sent in every signature, and receivers download the certificate from it.";

$text['label-setting_allowed_cidr']['en-us'] = "Allowed CIDR";
$text['label-setting_allowed_cidr']['en-gb'] = "Allowed CIDR";

$text['description-setting_allowed_cidr']['en-us'] = "Comma separated IPv4 ranges that can use sign.php and verify.php, such as 127.0.0.1/32. Limit it to the FreeSWITCH servers.";
$text['description-setting_allowed_cidr']['en-gb'] = "Comma separated IPv4 ranges that can use sign.php and verify.php, such as 127.0.0.1/32. Limit it to the FreeSWITCH servers.";

$text['label-setting_attestation_check']['en-us'] = "Attestation Check";
$text['label-setting_attestation_check']['en-gb'] = "Attestation Check";

$text['description-setting_attestation_check']['en-us'] = "Full attestation (A) asserts that the domain owns the calling number. When true, a call that would be signed with A is signed with B if the calling number is not an enabled inbound destination of the domain. Partial (B) and gateway (C) attestation are not changed.";
$text['description-setting_attestation_check']['en-gb'] = "Full attestation (A) asserts that the domain owns the calling number. When true, a call that would be signed with A is signed with B if the calling number is not an enabled inbound destination of the domain. Partial (B) and gateway (C) attestation are not changed.";

$text['label-check_attestation']['en-us'] = "Attestation Check";
$text['label-check_attestation']['en-gb'] = "Attestation Check";

$text['label-setting_sign_all_routes']['en-us'] = "Sign All Routes";
$text['label-setting_sign_all_routes']['en-gb'] = "Sign All Routes";

$text['description-setting_sign_all_routes']['en-us'] = "When true, signing is added to the outbound routes of every domain, including global routes and routes added later, with the destination taken from the bridge and attestation A. New routes are signed within an hour, when a platform administrator opens Setup or Routes, or on upgrade. Routes checked and removed with Remove on the Routes page, signed or not yet, are excluded and stay unsigned until Add is clicked; signing deleted in the dialplan editor is added back. Routes whose destination cannot be found in the bridge must be added on the Routes page. Before turning it on, exclude the routes that must stay unsigned: filter the Routes page by Not Signed, check them and click Remove.";
$text['description-setting_sign_all_routes']['en-gb'] = "When true, signing is added to the outbound routes of every domain, including global routes and routes added later, with the destination taken from the bridge and attestation A. New routes are signed within an hour, when a platform administrator opens Setup or Routes, or on upgrade. Routes checked and removed with Remove on the Routes page, signed or not yet, are excluded and stay unsigned until Add is clicked; signing deleted in the dialplan editor is added back. Routes whose destination cannot be found in the bridge must be added on the Routes page. Before turning it on, exclude the routes that must stay unsigned: filter the Routes page by Not Signed, check them and click Remove.";

$text['label-check_sign_all_routes']['en-us'] = "Sign All Routes";
$text['label-check_sign_all_routes']['en-gb'] = "Sign All Routes";

$text['message-sign_all_skipped']['en-us'] = "Routes that cannot be signed automatically because the destination was not found in the bridge, check the destination and click Add:";
$text['message-sign_all_skipped']['en-gb'] = "Routes that cannot be signed automatically because the destination was not found in the bridge, check the destination and click Add:";

$text['message-sign_all_failed']['en-us'] = "Sign All Routes could not add signing to routes:";
$text['message-sign_all_failed']['en-gb'] = "Sign All Routes could not add signing to routes:";

$text['message-sign_all_remaining']['en-us'] = "Sign All Routes stopped at its time limit, the rest are signed on the next run, within minutes on a server with calls. Routes left:";
$text['message-sign_all_remaining']['en-gb'] = "Sign All Routes stopped at its time limit, the rest are signed on the next run, within minutes on a server with calls. Routes left:";

$text['label-routes_excluded']['en-us'] = "routes excluded";
$text['label-routes_excluded']['en-gb'] = "routes excluded";

$text['label-excluded']['en-us'] = "Excluded";
$text['label-excluded']['en-gb'] = "Excluded";

$text['message-attestation_check_on']['en-us'] = "Calls are signed with A only from numbers that are inbound destinations of the domain.";
$text['message-attestation_check_on']['en-gb'] = "Calls are signed with A only from numbers that are inbound destinations of the domain.";

$text['message-attestation_check_off']['en-us'] = "Calls are signed with the attestation of the route even when the domain does not own the calling number.";
$text['message-attestation_check_off']['en-gb'] = "Calls are signed with the attestation of the route even when the domain does not own the calling number.";

$text['label-setting_debug']['en-us'] = "Debug";
$text['label-setting_debug']['en-gb'] = "Debug";

$text['description-setting_debug']['en-us'] = "Return error text from sign.php and verify.php instead of the normal response. Leave it off in production.";
$text['description-setting_debug']['en-gb'] = "Return error text from sign.php and verify.php instead of the normal response. Leave it off in production.";

$text['label-setting_database_retention_days']['en-us'] = "Log Retention (days)";
$text['label-setting_database_retention_days']['en-gb'] = "Log Retention (days)";

$text['description-setting_database_retention_days']['en-us'] = "Days to keep the identity logs. Older entries are deleted automatically, at most once an hour, by sign.php and verify.php. 0 keeps them forever.";
$text['description-setting_database_retention_days']['en-gb'] = "Days to keep the identity logs. Older entries are deleted automatically, at most once an hour, by sign.php and verify.php. 0 keeps them forever.";

$text['label-setting_trust_list']['en-us'] = "Trust List";
$text['label-setting_trust_list']['en-gb'] = "Trust List";

$text['description-setting_trust_list']['en-us'] = "PEM file of the STI-CA root certificates approved by the STI-PA. Inbound calls pass verification only when the certificate of the call chains to one of them. While it is empty, inbound calls with a valid signature are reported as No-TN-Validation.";
$text['description-setting_trust_list']['en-gb'] = "PEM file of the STI-CA root certificates approved by the STI-PA. Inbound calls pass verification only when the certificate of the call chains to one of them. While it is empty, inbound calls with a valid signature are reported as No-TN-Validation.";

$text['label-setting_crl_url']['en-us'] = "CRL URL";
$text['label-setting_crl_url']['en-gb'] = "CRL URL";

$text['description-setting_crl_url']['en-us'] = "URL of the certificate revocation list (CRL) of the STI-PA. The certificate of an inbound call, and any intermediate certificate served with it, must not be in it. Its signer must chain to the trust list, so add the STI-PA root certificate to the trust list. Revocation is not checked when empty.";
$text['description-setting_crl_url']['en-gb'] = "URL of the certificate revocation list (CRL) of the STI-PA. The certificate of an inbound call, and any intermediate certificate served with it, must not be in it. Its signer must chain to the trust list, so add the STI-PA root certificate to the trust list. Revocation is not checked when empty.";

$text['label-setting_brands_file']['en-us'] = "Brands File";
$text['label-setting_brands_file']['en-gb'] = "Brands File";

$text['description-setting_brands_file']['en-us'] = "Full path of the brands file managed with brand.php, such as /etc/fusionpbx/identity_brands.json. Branding is off when empty.";
$text['description-setting_brands_file']['en-gb'] = "Full path of the brands file managed with brand.php, such as /etc/fusionpbx/identity_brands.json. Branding is off when empty.";

$text['label-setting_bcid_private_key_name']['en-us'] = "BCID Private Key File";
$text['label-setting_bcid_private_key_name']['en-gb'] = "BCID Private Key File";

$text['description-setting_bcid_private_key_name']['en-us'] = "File name of the BCID private key in the certificate directory.";
$text['description-setting_bcid_private_key_name']['en-gb'] = "File name of the BCID private key in the certificate directory.";

$text['label-setting_bcid_certificate_name']['en-us'] = "BCID Certificate File";
$text['label-setting_bcid_certificate_name']['en-gb'] = "BCID Certificate File";

$text['description-setting_bcid_certificate_name']['en-us'] = "File name of the BCID certificate in the certificate directory.";
$text['description-setting_bcid_certificate_name']['en-gb'] = "File name of the BCID certificate in the certificate directory.";

$text['label-setting_bcid_certificate_url']['en-us'] = "BCID Certificate URL";
$text['label-setting_bcid_certificate_url']['en-gb'] = "BCID Certificate URL";

$text['description-setting_bcid_certificate_url']['en-us'] = "Public https URL of the BCID certificate. Brands with the bcid profile are only branded when it is set.";
$text['description-setting_bcid_certificate_url']['en-gb'] = "Public https URL of the BCID certificate. Brands with the bcid profile are only branded when it is set.";

$text['label-setting_bcid_report_log']['en-us'] = "BCID Report Log";
$text['label-setting_bcid_report_log']['en-gb'] = "BCID Report Log";

$text['description-setting_bcid_report_log']['en-us'] = "Full path of the file each branded call signed with the BCID certificate is written to, such as /var/log/fusionpbx/identity_bcid.log. Empty to not record them.";
$text['description-setting_bcid_report_log']['en-gb'] = "Full path of the file each branded call signed with the BCID certificate is written to, such as /var/log/fusionpbx/identity_bcid.log. Empty to not record them.";

$text['label-check_settings']['en-us'] = "Settings";
$text['label-check_settings']['en-gb'] = "Settings";

$text['message-settings_config']['en-us'] = "The settings are still read from config.conf. Run the upgrade, or save the settings below, to move them to Default Settings.";
$text['message-settings_config']['en-gb'] = "The settings are still read from config.conf. Run the upgrade, or save the settings below, to move them to Default Settings.";

$text['message-setting_invalid']['en-us'] = "Invalid value:";
$text['message-setting_invalid']['en-gb'] = "Invalid value:";

$text['message-settings_saved']['en-us'] = "Settings saved.";
$text['message-settings_saved']['en-gb'] = "Settings saved.";

$text['message-settings_failed']['en-us'] = "The settings could not be saved.";
$text['message-settings_failed']['en-gb'] = "The settings could not be saved.";

$text['header-activity']['en-us'] = "Activity";
$text['header-activity']['en-gb'] = "Activity";

$text['header-configuration']['en-us'] = "Configuration";
$text['header-configuration']['en-gb'] = "Configuration";

$text['header-recent_failures']['en-us'] = "Recent Failures";
$text['header-recent_failures']['en-gb'] = "Recent Failures";

$text['header-certificate']['en-us'] = "Certificate";
$text['header-certificate']['en-gb'] = "Certificate";

$text['description-certificate']['en-us'] = "Production calls need the private key and certificate issued by your STI-CA. A self-signed test certificate can be generated to try signing and verification. It will not be accepted by carriers. Generating never replaces existing files.";
$text['description-certificate']['en-gb'] = "Production calls need the private key and certificate issued by your STI-CA. A self-signed test certificate can be generated to try signing and verification. It will not be accepted by carriers. Generating never replaces existing files.";

$text['header-import']['en-us'] = "Import Certificate";
$text['header-import']['en-gb'] = "Import Certificate";

$text['description-import']['en-us'] = "Import the private key and the certificate issued by your STI-CA. Either can be imported on its own, but the key and certificate in use must belong together. The private key must be EC P-256 in PEM format. The certificate can be PEM or DER and can include the chain. Existing files are kept as a .bak copy. Publish the same certificate at the certificate URL.";
$text['description-import']['en-gb'] = "Import the private key and the certificate issued by your STI-CA. Either can be imported on its own, but the key and certificate in use must belong together. The private key must be EC P-256 in PEM format. The certificate can be PEM or DER and can include the chain. Existing files are kept as a .bak copy. Publish the same certificate at the certificate URL.";

$text['label-passphrase']['en-us'] = "Passphrase";
$text['label-passphrase']['en-gb'] = "Passphrase";

$text['description-passphrase']['en-us'] = "Only for an encrypted private key. The key is saved without a passphrase so sign.php can read it.";
$text['description-passphrase']['en-gb'] = "Only for an encrypted private key. The key is saved without a passphrase so sign.php can read it.";

$text['header-test']['en-us'] = "Test";
$text['header-test']['en-gb'] = "Test";

$text['description-test']['en-us'] = "Signs a call with the private key, then verifies the result with the local certificate and with the certificate URL the way a carrier would. Nothing is logged.";
$text['description-test']['en-gb'] = "Signs a call with the private key, then verifies the result with the local certificate and with the certificate URL the way a carrier would. Nothing is logged.";

$text['header-outbound']['en-us'] = "Outbound Routes";
$text['header-outbound']['en-gb'] = "Outbound Routes";

$text['description-outbound']['en-us'] = "Signing is added to and removed from the outbound routes on the Routes page, which lists them with paging, search and a filter by signing. Turn on Sign All Routes in the settings to sign the routes of every domain automatically.";
$text['description-outbound']['en-gb'] = "Signing is added to and removed from the outbound routes on the Routes page, which lists them with paging, search and a filter by signing. Turn on Sign All Routes in the settings to sign the routes of every domain automatically.";

$text['description-identity_routes']['en-us'] = "Adding signing inserts the actions that request a SIP Identity header from sign.php before the first bridge of the route. The destination is the number sent to the carrier, usually the same as the end of the bridge, such as 1\$1 or \$1. Check routes to add or remove signing on several at once.";
$text['description-identity_routes']['en-gb'] = "Adding signing inserts the actions that request a SIP Identity header from sign.php before the first bridge of the route. The destination is the number sent to the carrier, usually the same as the end of the bridge, such as 1\$1 or \$1. Check routes to add or remove signing on several at once.";

$text['option-unsigned']['en-us'] = "Not Signed";
$text['option-unsigned']['en-gb'] = "Not Signed";

$text['option-excluded']['en-us'] = "Excluded";
$text['option-excluded']['en-gb'] = "Excluded";

$text['confirm-remove_signing_checked']['en-us'] = "Remove signing from the checked routes?";
$text['confirm-remove_signing_checked']['en-gb'] = "Remove signing from the checked routes?";

$text['message-signing_removed_all']['en-us'] = "Signing removed from routes:";
$text['message-signing_removed_all']['en-gb'] = "Signing removed from routes:";

$text['label-destination_required']['en-us'] = "Required";
$text['label-destination_required']['en-gb'] = "Required";

$text['message-signing_remaining_all']['en-us'] = "Add All stopped at its time limit, click Add All again to sign the rest. Routes left:";
$text['message-signing_remaining_all']['en-gb'] = "Add All stopped at its time limit, click Add All again to sign the rest. Routes left:";

$text['message-signing_excluded_all']['en-us'] = "Routes that did not sign excluded from Sign All Routes:";
$text['message-signing_excluded_all']['en-gb'] = "Routes that did not sign excluded from Sign All Routes:";

$text['message-signing_remove_failed_all']['en-us'] = "Signing could not be removed from routes, they were not found in this domain or the change could not be saved:";
$text['message-signing_remove_failed_all']['en-gb'] = "Signing could not be removed from routes, they were not found in this domain or the change could not be saved:";

$text['message-signing_skipped_all']['en-us'] = "Routes not signed because the destination was not found in the bridge, set the destination and click Add:";
$text['message-signing_skipped_all']['en-gb'] = "Routes not signed because the destination was not found in the bridge, set the destination and click Add:";

$text['header-inbound']['en-us'] = "Inbound Verification";
$text['header-inbound']['en-gb'] = "Inbound Verification";

$text['description-inbound']['en-us'] = "Adds a global dialplan in the public context that sends the Identity header of each inbound call to verify.php and stores the result in the identity_verstat channel variable.";
$text['description-inbound']['en-gb'] = "Adds a global dialplan in the public context that sends the Identity header of each inbound call to verify.php and stores the result in the identity_verstat channel variable.";

$text['button-logs']['en-us'] = "Logs";
$text['button-logs']['en-gb'] = "Logs";

$text['button-routes']['en-us'] = "Routes";
$text['button-routes']['en-gb'] = "Routes";

$text['button-test']['en-us'] = "Test";
$text['button-test']['en-gb'] = "Test";

$text['button-generate_certificate']['en-us'] = "Generate Test Certificate";
$text['button-generate_certificate']['en-gb'] = "Generate Test Certificate";

$text['button-add_all']['en-us'] = "Add All";
$text['button-add_all']['en-gb'] = "Add All";

$text['confirm-add_signing_all']['en-us'] = "Add signing to every route that is not signed and matches the search, on every page? Routes on this page use the destination and attestation shown, routes on other pages use the destination from the bridge and attestation A.";
$text['confirm-add_signing_all']['en-gb'] = "Add signing to every route that is not signed and matches the search, on every page? Routes on this page use the destination and attestation shown, routes on other pages use the destination from the bridge and attestation A.";

$text['confirm-import']['en-us'] = "Replace the private key or certificate in use? The current files are kept as a .bak copy.";
$text['confirm-import']['en-gb'] = "Replace the private key or certificate in use? The current files are kept as a .bak copy.";

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

$text['label-brand']['en-us'] = "Brand";
$text['label-brand']['en-gb'] = "Brand";

$text['label-branded']['en-us'] = "Branded";
$text['label-branded']['en-gb'] = "Branded";

$text['label-brands']['en-us'] = "brands";
$text['label-brands']['en-gb'] = "brands";

$text['label-numbers']['en-us'] = "numbers";
$text['label-numbers']['en-gb'] = "numbers";

$text['label-certificates']['en-us'] = "certificates";
$text['label-certificates']['en-gb'] = "certificates";

$text['label-crl_updated']['en-us'] = "CRL of";
$text['label-crl_updated']['en-gb'] = "CRL of";

$text['label-crl_next']['en-us'] = "next update";
$text['label-crl_next']['en-gb'] = "next update";

$text['label-crl_revoked']['en-us'] = "revoked certificates";
$text['label-crl_revoked']['en-gb'] = "revoked certificates";

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

$text['label-check_logs']['en-us'] = "Database Tables";
$text['label-check_logs']['en-gb'] = "Database Tables";

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

$text['label-check_brands_file']['en-us'] = "Brands File";
$text['label-check_brands_file']['en-gb'] = "Brands File";

$text['label-check_bcid_private_key']['en-us'] = "BCID Private Key";
$text['label-check_bcid_private_key']['en-gb'] = "BCID Private Key";

$text['label-check_bcid_certificate']['en-us'] = "BCID Certificate";
$text['label-check_bcid_certificate']['en-gb'] = "BCID Certificate";

$text['label-check_bcid_key_pair']['en-us'] = "BCID Key Pair";
$text['label-check_bcid_key_pair']['en-gb'] = "BCID Key Pair";

$text['label-check_bcid_certificate_url']['en-us'] = "BCID Certificate URL";
$text['label-check_bcid_certificate_url']['en-gb'] = "BCID Certificate URL";

$text['label-check_trust_list']['en-us'] = "Trust List";
$text['label-check_trust_list']['en-gb'] = "Trust List";

$text['label-check_crl']['en-us'] = "Revocation";
$text['label-check_crl']['en-gb'] = "Revocation";

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

$text['message-run_upgrade']['en-us'] = "The v_identity_logs or v_identity_route_exclusions table does not exist. Run Advanced > Upgrade > Schema or php core/upgrade/upgrade.php.";
$text['message-run_upgrade']['en-gb'] = "The v_identity_logs or v_identity_route_exclusions table does not exist. Run Advanced > Upgrade > Schema or php core/upgrade/upgrade.php.";

$text['message-path_missing']['en-us'] = "The certificate directory does not exist, and the web server cannot create it. Create it by running this as root, then click Refresh:";
$text['message-path_missing']['en-gb'] = "The certificate directory does not exist, and the web server cannot create it. Create it by running this as root, then click Refresh:";

$text['message-path_read_only']['en-us'] = "The web server cannot write to the certificate directory, so Import and Generate Test Certificate fail. Make it owned by the web server user and, if PHP-FPM runs with ProtectSystem, allow it in ReadWritePaths, by running this as root (it restarts PHP-FPM), then click Refresh:";
$text['message-path_read_only']['en-gb'] = "The web server cannot write to the certificate directory, so Import and Generate Test Certificate fail. Make it owned by the web server user and, if PHP-FPM runs with ProtectSystem, allow it in ReadWritePaths, by running this as root (it restarts PHP-FPM), then click Refresh:";

$text['message-path_owner']['en-us'] = "The web server user cannot write to the certificate directory, so Import and Generate Test Certificate fail. Give it to the web server user by running this as root, then click Refresh:";
$text['message-path_owner']['en-gb'] = "The web server user cannot write to the certificate directory, so Import and Generate Test Certificate fail. Give it to the web server user by running this as root, then click Refresh:";

$text['message-path_protected']['en-us'] = "PHP-FPM runs with ProtectSystem, which makes the certificate directory read-only for the web server even though the web server user owns it, so Import and Generate Test Certificate fail. Allow it with a systemd drop-in by running this as root (it restarts PHP-FPM, which interrupts web requests for a moment), then click Refresh:";
$text['message-path_protected']['en-gb'] = "PHP-FPM runs with ProtectSystem, which makes the certificate directory read-only for the web server even though the web server user owns it, so Import and Generate Test Certificate fail. Allow it with a systemd drop-in by running this as root (it restarts PHP-FPM, which interrupts web requests for a moment), then click Refresh:";

$text['message-path_restart']['en-us'] = "A systemd drop-in allows PHP-FPM to write to the certificate directory, but PHP-FPM has not been restarted since it was added. Run this as root (it restarts PHP-FPM, which interrupts web requests for a moment), then click Refresh:";
$text['message-path_restart']['en-gb'] = "A systemd drop-in allows PHP-FPM to write to the certificate directory, but PHP-FPM has not been restarted since it was added. Run this as root (it restarts PHP-FPM, which interrupts web requests for a moment), then click Refresh:";

$text['label-path_owner']['en-us'] = "owner";
$text['label-path_owner']['en-gb'] = "owner";

$text['label-path_mode']['en-us'] = "mode";
$text['label-path_mode']['en-gb'] = "mode";

$text['label-path_web_user']['en-us'] = "web server user";
$text['label-path_web_user']['en-gb'] = "web server user";

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

$text['message-url_missing']['en-us'] = "Set the certificate URL in Settings below. Receivers download the certificate from this URL to verify your calls.";
$text['message-url_missing']['en-gb'] = "Set the certificate URL in Settings below. Receivers download the certificate from this URL to verify your calls.";

$text['message-url_scheme']['en-us'] = "The certificate URL must use https:";
$text['message-url_scheme']['en-gb'] = "The certificate URL must use https:";

$text['message-url_unreachable']['en-us'] = "The certificate URL could not be downloaded:";
$text['message-url_unreachable']['en-gb'] = "The certificate URL could not be downloaded:";

$text['message-url_not_certificate']['en-us'] = "The certificate URL does not return a certificate:";
$text['message-url_not_certificate']['en-gb'] = "The certificate URL does not return a certificate:";

$text['message-url_mismatch']['en-us'] = "The certificate URL returns a different certificate than the local one:";
$text['message-url_mismatch']['en-gb'] = "The certificate URL returns a different certificate than the local one:";

$text['message-brands_file_missing']['en-us'] = "The brands file does not exist, so no calls are branded:";
$text['message-brands_file_missing']['en-gb'] = "The brands file does not exist, so no calls are branded:";

$text['message-brands_file_invalid']['en-us'] = "The brands file cannot be used, so every call is signed without branding:";
$text['message-brands_file_invalid']['en-gb'] = "The brands file cannot be used, so every call is signed without branding:";

$text['message-bcid_off']['en-us'] = "Set the BCID Certificate URL in Settings below. Until then, calls from enabled brands with the bcid profile are signed without branding. Brands:";
$text['message-bcid_off']['en-gb'] = "Set the BCID Certificate URL in Settings below. Until then, calls from enabled brands with the bcid profile are signed without branding. Brands:";

$text['message-trust_list_missing']['en-us'] = "Set the trust list in Settings below. Until then, inbound calls with a valid signature are reported as No-TN-Validation, because the signer cannot be checked.";
$text['message-trust_list_missing']['en-gb'] = "Set the trust list in Settings below. Until then, inbound calls with a valid signature are reported as No-TN-Validation, because the signer cannot be checked.";

$text['message-trust_list_unreadable']['en-us'] = "The trust list does not exist or cannot be read, so inbound calls with a valid signature are reported as No-TN-Validation:";
$text['message-trust_list_unreadable']['en-gb'] = "The trust list does not exist or cannot be read, so inbound calls with a valid signature are reported as No-TN-Validation:";

$text['message-trust_list_invalid']['en-us'] = "The trust list contains no certificates, so every inbound call fails verification:";
$text['message-trust_list_invalid']['en-gb'] = "The trust list contains no certificates, so every inbound call fails verification:";

$text['message-trust_list_expired']['en-us'] = "Expired certificates in the trust list, download the current list from the STI-PA:";
$text['message-trust_list_expired']['en-gb'] = "Expired certificates in the trust list, download the current list from the STI-PA:";

$text['message-crl_off']['en-us'] = "Revocation is not checked, so a revoked certificate passes until it expires. Set the CRL URL in Settings below.";
$text['message-crl_off']['en-gb'] = "Revocation is not checked, so a revoked certificate passes until it expires. Set the CRL URL in Settings below.";

$text['message-crl_failed']['en-us'] = "The CRL cannot be used, so inbound calls with a valid signature are reported as No-TN-Validation:";
$text['message-crl_failed']['en-gb'] = "The CRL cannot be used, so inbound calls with a valid signature are reported as No-TN-Validation:";

$text['message-cidr_invalid']['en-us'] = "Invalid CIDR, use the address/mask format such as 127.0.0.1/32:";
$text['message-cidr_invalid']['en-gb'] = "Invalid CIDR, use the address/mask format such as 127.0.0.1/32:";

$text['message-cidr_open']['en-us'] = "Any address can request a signature:";
$text['message-cidr_open']['en-gb'] = "Any address can request a signature:";

$text['message-debug_enabled']['en-us'] = "Debug is enabled in Settings. Error messages are returned to FreeSWITCH.";
$text['message-debug_enabled']['en-gb'] = "Debug is enabled in Settings. Error messages are returned to FreeSWITCH.";

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

$text['message-signing_added_all']['en-us'] = "Signing added to routes:";
$text['message-signing_added_all']['en-gb'] = "Signing added to routes:";

$text['message-signing_failed_all']['en-us'] = "Signing could not be added to routes, check that each destination looks like 1\$1 or \$1:";
$text['message-signing_failed_all']['en-gb'] = "Signing could not be added to routes, check that each destination looks like 1\$1 or \$1:";

$text['message-import_complete']['en-us'] = "Certificate imported. Make sure the certificate URL serves the same certificate.";
$text['message-import_complete']['en-gb'] = "Certificate imported. Make sure the certificate URL serves the same certificate.";

$text['message-import_empty']['en-us'] = "Choose a private key or a certificate to import.";
$text['message-import_empty']['en-gb'] = "Choose a private key or a certificate to import.";

$text['message-import_upload']['en-us'] = "The file could not be uploaded. Files must be no larger than 64 KB.";
$text['message-import_upload']['en-gb'] = "The file could not be uploaded. Files must be no larger than 64 KB.";

$text['message-import_path']['en-us'] = "The certificate directory does not exist or the web server cannot write to it. See the Certificate Directory check.";
$text['message-import_path']['en-gb'] = "The certificate directory does not exist or the web server cannot write to it. See the Certificate Directory check.";

$text['message-import_key_invalid']['en-us'] = "The private key could not be read. It must be in PEM format, and an encrypted key needs the correct passphrase.";
$text['message-import_key_invalid']['en-gb'] = "The private key could not be read. It must be in PEM format, and an encrypted key needs the correct passphrase.";

$text['message-import_key_curve']['en-us'] = "The private key must be an EC P-256 (prime256v1) key.";
$text['message-import_key_curve']['en-gb'] = "The private key must be an EC P-256 (prime256v1) key.";

$text['message-import_certificate_invalid']['en-us'] = "The certificate could not be read. It must be in PEM or DER format.";
$text['message-import_certificate_invalid']['en-gb'] = "The certificate could not be read. It must be in PEM or DER format.";

$text['message-import_certificate_curve']['en-us'] = "The certificate must have an EC P-256 (prime256v1) public key.";
$text['message-import_certificate_curve']['en-gb'] = "The certificate must have an EC P-256 (prime256v1) public key.";

$text['message-import_certificate_expired']['en-us'] = "The certificate has expired.";
$text['message-import_certificate_expired']['en-gb'] = "The certificate has expired.";

$text['message-import_mismatch']['en-us'] = "The private key does not belong to the certificate. Import the key and certificate together. Nothing was replaced.";
$text['message-import_mismatch']['en-gb'] = "The private key does not belong to the certificate. Import the key and certificate together. Nothing was replaced.";

$text['message-import_write']['en-us'] = "The files could not be written to the certificate directory.";
$text['message-import_write']['en-gb'] = "The files could not be written to the certificate directory.";

$text['message-platform_permission']['en-us'] = "The settings, certificates, test and inbound verification apply to the whole platform and need the identity_setup_all permission.";
$text['message-platform_permission']['en-gb'] = "The settings, certificates, test and inbound verification apply to the whole platform and need the identity_setup_all permission.";

$text['message-verify_added']['en-us'] = "Inbound verification added.";
$text['message-verify_added']['en-gb'] = "Inbound verification added.";

$text['message-verify_removed']['en-us'] = "Inbound verification removed.";
$text['message-verify_removed']['en-gb'] = "Inbound verification removed.";

?>
