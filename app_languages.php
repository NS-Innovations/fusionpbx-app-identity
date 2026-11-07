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

$text['description-identity_setup']['en-us'] = "Checks the STIR/SHAKEN configuration, manages the settings and certificates, and adds signing and verification to the dialplan.";
$text['description-identity_setup']['en-gb'] = "Checks the STIR/SHAKEN configuration, manages the settings and certificates, and adds signing and verification to the dialplan.";

$text['header-settings']['en-us'] = "Settings";
$text['header-settings']['en-gb'] = "Settings";

$text['description-settings']['en-us'] = "These settings apply to the whole server and are used by sign.php and verify.php right away. They are stored in Default Settings under the Identity category.";
$text['description-settings']['en-gb'] = "These settings apply to the whole server and are used by sign.php and verify.php right away. They are stored in Default Settings under the Identity category.";

$text['header-settings_branded']['en-us'] = "Branded Calling";
$text['header-settings_branded']['en-gb'] = "Branded Calling";

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

$text['button-add_all']['en-us'] = "Add All";
$text['button-add_all']['en-gb'] = "Add All";

$text['confirm-add_signing_all']['en-us'] = "Add signing to every route listed that is not signed, using the destination and attestation shown for each?";
$text['confirm-add_signing_all']['en-gb'] = "Add signing to every route listed that is not signed, using the destination and attestation shown for each?";

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

$text['message-path_missing']['en-us'] = "Directory does not exist:";
$text['message-path_missing']['en-gb'] = "Directory does not exist:";

$text['message-path_read_only']['en-us'] = "The web server cannot write to the directory, so Import and Generate Test Certificate will fail. If PHP-FPM runs with ProtectSystem, add the directory to ReadWritePaths:";
$text['message-path_read_only']['en-gb'] = "The web server cannot write to the directory, so Import and Generate Test Certificate will fail. If PHP-FPM runs with ProtectSystem, add the directory to ReadWritePaths:";

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

$text['message-signing_added']['en-us'] = "Signing added to the route.";
$text['message-signing_added']['en-gb'] = "Signing added to the route.";

$text['message-signing_failed']['en-us'] = "Signing could not be added. The route must have a bridge and the destination must look like 1\$1 or \$1.";
$text['message-signing_failed']['en-gb'] = "Signing could not be added. The route must have a bridge and the destination must look like 1\$1 or \$1.";

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

$text['message-signing_removed']['en-us'] = "Signing removed from the route.";
$text['message-signing_removed']['en-gb'] = "Signing removed from the route.";

$text['message-platform_permission']['en-us'] = "The settings, certificates, test and inbound verification apply to the whole platform and need the identity_setup_all permission.";
$text['message-platform_permission']['en-gb'] = "The settings, certificates, test and inbound verification apply to the whole platform and need the identity_setup_all permission.";

$text['message-signing_remove_failed']['en-us'] = "Signing could not be removed. The route was not found in this domain or does not sign calls.";
$text['message-signing_remove_failed']['en-gb'] = "Signing could not be removed. The route was not found in this domain or does not sign calls.";

$text['message-verify_added']['en-us'] = "Inbound verification added.";
$text['message-verify_added']['en-gb'] = "Inbound verification added.";

$text['message-verify_removed']['en-us'] = "Inbound verification removed.";
$text['message-verify_removed']['en-gb'] = "Inbound verification removed.";

?>
