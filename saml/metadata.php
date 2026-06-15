<?php
/**
 * saml/metadata.php — metadata SAML 2.0 Identity Provider (publiczne).
 *
 * Service Providerzy pobierają ten dokument, aby skonfigurować zaufanie do IdP
 * (entityID, endpoint SSO/SLO, certyfikat podpisujący).
 *
 * URL: <APP_URL>/saml/metadata.php
 */

define('BOOTSTRAP_CHECKED', true); // metadata jest publiczne — pomiń bramę certyfikatu
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/saml_idp.php';

if (!saml_idp_enabled()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("SAML IdP nie jest włączony.\n");
}
if (!saml_idp_has_cert()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Brak certyfikatu podpisującego IdP. Wygeneruj: php cli/saml_gen_cert.php\n");
}

header('Content-Type: application/samlmetadata+xml; charset=utf-8');
header('Content-Disposition: inline; filename="szo-idp-metadata.xml"');
header('Cache-Control: public, max-age=3600');
echo saml_idp_metadata_xml();
