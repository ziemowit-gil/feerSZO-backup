<?php
/**
 * includes/saml_idp.php — SAML 2.0 Identity Provider (IdP)
 *
 * Pozwala zewnętrznym aplikacjom (Service Providerom: Nextcloud,
 * Grafana itp.) logować użytkowników na danych z SZO.
 *
 * Zależności:
 *   - rozszerzenia PHP: openssl, dom, libxml, zlib
 *   - includes/lib/xmlseclibs/  (podpisy XML-DSig — wzendurowane, bez composera)
 *
 * Certyfikat podpisujący:
 *   certs/saml-idp.crt + certs/saml-idp.key  (dedykowany — zalecane)
 *   fallback: certs/app.crt + certs/app.key  (cert instalacji)
 *   Generowanie: php cli/saml_gen_cert.php   lub  Admin → SAML IdP.
 *
 * @see saml/metadata.php  saml/sso.php  saml/slo.php  admin/saml.php
 */

require_once __DIR__ . '/db.php';

// ── Przestrzenie nazw XML ──────────────────────────────────────────────────────
const SAML_NS_SAMLP = 'urn:oasis:names:tc:SAML:2.0:protocol';
const SAML_NS_SAML  = 'urn:oasis:names:tc:SAML:2.0:assertion';
const SAML_NS_MD    = 'urn:oasis:names:tc:SAML:2.0:metadata';
const SAML_NS_DS    = 'http://www.w3.org/2000/09/xmldsig#';

// ── xmlseclibs — ładowanie bez composera ───────────────────────────────────────
function saml_load_xmlseclibs(): void {
    if (class_exists('RobRichards\\XMLSecLibs\\XMLSecurityDSig')) return;
    $b = __DIR__ . '/lib/xmlseclibs/';
    require_once $b . 'Utils/XPath.php';
    require_once $b . 'XMLSecurityKey.php';
    require_once $b . 'XMLSecurityDSig.php';
    require_once $b . 'XMLSecEnc.php';
}

// ── Ustawienia ──────────────────────────────────────────────────────────────────
function saml_setting(string $key, string $default = ''): string {
    try {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        return $r ? (string)$r['value'] : $default;
    } catch (\Throwable $e) { return $default; }
}

function saml_idp_enabled(): bool {
    return saml_setting('saml_idp_enabled', '0') === '1';
}

// ── Tożsamość i endpointy IdP ───────────────────────────────────────────────────
function saml_idp_base_url(): string {
    return rtrim(defined('APP_URL') ? APP_URL : '', '/');
}
function saml_idp_entity_id(): string {
    $s = saml_setting('saml_idp_entity_id', '');
    return $s !== '' ? $s : saml_idp_base_url() . '/saml/metadata.php';
}
function saml_idp_sso_url(): string { return saml_idp_base_url() . '/saml/sso.php'; }
function saml_idp_slo_url(): string { return saml_idp_base_url() . '/saml/slo.php'; }
function saml_idp_metadata_url(): string { return saml_idp_base_url() . '/saml/metadata.php'; }

// ── Certyfikat podpisujący ───────────────────────────────────────────────────────
function saml_idp_cert_dir(): string { return dirname(__DIR__) . '/certs'; }

function saml_idp_cert_paths(): array {
    $d = saml_idp_cert_dir();
    if (is_file("$d/saml-idp.key") && is_file("$d/saml-idp.crt")) {
        return ['key' => "$d/saml-idp.key", 'crt' => "$d/saml-idp.crt", 'dedicated' => true];
    }
    if (is_file("$d/app.key") && is_file("$d/app.crt")) {
        return ['key' => "$d/app.key", 'crt' => "$d/app.crt", 'dedicated' => false];
    }
    return ['key' => '', 'crt' => '', 'dedicated' => false];
}

function saml_idp_has_cert(): bool {
    $p = saml_idp_cert_paths();
    return $p['key'] !== '' && $p['crt'] !== '';
}

function saml_idp_cert_pem(): string {
    $p = saml_idp_cert_paths();
    return $p['crt'] !== '' ? (string)file_get_contents($p['crt']) : '';
}
function saml_idp_key_pem(): string {
    $p = saml_idp_cert_paths();
    return $p['key'] !== '' ? (string)file_get_contents($p['key']) : '';
}

/** Czysty base64 certyfikatu (bez nagłówków PEM i białych znaków) — do <ds:X509Certificate>. */
function saml_idp_cert_clean(): string {
    if (!preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s',
                    saml_idp_cert_pem(), $m)) {
        return '';
    }
    return preg_replace('/\s+/', '', $m[1]);
}

function saml_idp_cert_info(): ?array {
    $pem = saml_idp_cert_pem();
    if ($pem === '') return null;
    $p = openssl_x509_parse($pem);
    if (!$p) return null;
    $paths = saml_idp_cert_paths();
    return [
        'subject'    => $p['subject']['CN'] ?? ($p['name'] ?? ''),
        'valid_from' => date('Y-m-d', $p['validFrom_time_t'] ?? 0),
        'valid_to'   => date('Y-m-d', $p['validTo_time_t'] ?? 0),
        'days_left'  => (int)ceil((($p['validTo_time_t'] ?? 0) - time()) / 86400),
        'fingerprint'=> strtoupper(openssl_x509_fingerprint($pem, 'sha256')),
        'dedicated'  => $paths['dedicated'],
    ];
}

/**
 * Wygeneruj dedykowaną parę kluczy IdP (RSA-2048, self-signed, 5 lat).
 * @return array{ok:bool, msg:string}
 */
function saml_idp_generate_cert(bool $force = false): array {
    if (!extension_loaded('openssl')) {
        return ['ok' => false, 'msg' => 'Brak rozszerzenia openssl.'];
    }
    $dir = saml_idp_cert_dir();

    // Aktualny użytkownik procesu (do diagnostyki uprawnień).
    $procUser = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
        ? (posix_getpwuid(posix_geteuid())['name'] ?? (string)posix_geteuid())
        : (getenv('USER') ?: 'www-data');

    // Utwórz katalog certs/ jeśli brakuje.
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $parent = dirname($dir);
            return ['ok' => false, 'msg' =>
                "Nie można utworzyć katalogu {$dir}. " .
                "Katalog nadrzędny " . ($parent) . " " .
                (is_writable($parent) ? 'jest zapisywalny' : "NIE jest zapisywalny dla użytkownika „{$procUser}”") .
                ". Utwórz katalog ręcznie: mkdir -p {$dir} && chown {$procUser} {$dir}"];
        }
    }

    // Sprawdź zapisywalność — spróbuj naprawić uprawnienia, jeśli trzeba.
    if (!is_writable($dir)) {
        @chmod($dir, 0775);
    }
    if (!is_writable($dir)) {
        return ['ok' => false, 'msg' =>
            "Katalog {$dir} nie jest zapisywalny dla użytkownika serwera „{$procUser}”. " .
            "Nadaj uprawnienia: chown -R {$procUser} {$dir} (lub chmod u+w {$dir}). " .
            "Alternatywnie wygeneruj cert z konsoli: php cli/saml_gen_cert.php"];
    }

    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Require all denied\n");

    $crt = $dir . '/saml-idp.crt';
    $key = $dir . '/saml-idp.key';
    if (!$force && is_file($crt) && is_file($key)) {
        return ['ok' => false, 'msg' => 'Certyfikat SAML już istnieje (użyj wymuszenia, aby nadpisać).'];
    }
    // Pliki mogą istnieć, ale być niezapisywalne (np. utworzone przez root).
    foreach ([$crt, $key] as $pf) {
        if (is_file($pf) && !is_writable($pf)) {
            return ['ok' => false, 'msg' =>
                "Plik {$pf} istnieje, ale nie jest zapisywalny dla „{$procUser}”. " .
                "Usuń go lub nadaj uprawnienia: chown {$procUser} {$pf}"];
        }
    }

    $org = defined('ORG_NAME') && ORG_NAME !== '' ? ORG_NAME : 'SZO Identity Provider';
    $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (!$pkey) return ['ok' => false, 'msg' => 'Generowanie klucza nie powiodło się: ' . openssl_error_string()];

    $dn = ['C' => 'PL', 'ST' => 'Polska', 'O' => $org, 'OU' => 'SAML Identity Provider', 'CN' => $org];
    $csr  = openssl_csr_new($dn, $pkey, ['digest_alg' => 'sha256']);
    if (!$csr) return ['ok' => false, 'msg' => 'CSR nie powiodło się: ' . openssl_error_string()];
    // 5 lat = 1825 dni
    $cert = openssl_csr_sign($csr, null, $pkey, 1825, ['digest_alg' => 'sha256'],
                             (int)(microtime(true) * 1000) & 0x7FFFFFFF);
    if (!$cert) return ['ok' => false, 'msg' => 'Podpisanie certyfikatu nie powiodło się: ' . openssl_error_string()];

    $certPem = $keyPem = '';
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($pkey, $keyPem);
    if ($certPem === '' || $keyPem === '') return ['ok' => false, 'msg' => 'Eksport PEM nie powiódł się.'];

    if (file_put_contents($crt, $certPem) === false) {
        return ['ok' => false, 'msg' => "Zapis {$crt} nie powiódł się — brak uprawnień użytkownika „{$procUser}”. " .
            "Wykonaj: chown -R {$procUser} {$dir} (lub uruchom: php cli/saml_gen_cert.php)."];
    }
    if (file_put_contents($key, $keyPem) === false) {
        @unlink($crt);
        return ['ok' => false, 'msg' => "Zapis {$key} nie powiódł się — brak uprawnień użytkownika „{$procUser}”. " .
            "Wykonaj: chown -R {$procUser} {$dir} (lub uruchom: php cli/saml_gen_cert.php)."];
    }
    @chmod($key, 0600);

    return ['ok' => true, 'msg' => 'Wygenerowano dedykowany certyfikat SAML IdP (ważny 5 lat).'];
}

// ── Service Providerzy (rejestr) ─────────────────────────────────────────────────
function saml_sp_by_entity(string $entityId): ?array {
    return db_one("SELECT * FROM saml_sp WHERE entity_id=?", [$entityId]);
}
function saml_sp_by_id(int $id): ?array {
    return db_one("SELECT * FROM saml_sp WHERE id=?", [$id]);
}
function saml_sp_all(): array {
    return db_all("SELECT * FROM saml_sp ORDER BY name COLLATE NOCASE");
}

// ── Formaty NameID ───────────────────────────────────────────────────────────────
function saml_nameid_urn(string $fmt): string {
    return match ($fmt) {
        'persistent'  => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
        'transient'   => 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient',
        'unspecified' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
        default       => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
    };
}

// ── Presety atrybutów dla typowych SP ────────────────────────────────────────────
/**
 * @return array{nameid_format:string, nameid_attr:string, attrs:array<int,array{name:string,friendly:string,nameformat:string,source:string}>}
 */
function saml_preset(string $preset): array {
    $basic = 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic';
    $uri   = 'urn:oasis:names:tc:SAML:2.0:attrname-format:uri';
    switch ($preset) {
        case 'nextcloud':
        case 'owncloud': // ten sam app user_saml co Nextcloud — identyczne oczekiwane atrybuty
            return ['nameid_format' => 'emailAddress', 'nameid_attr' => 'email', 'attrs' => [
                ['name' => 'email',       'friendly' => 'email',       'nameformat' => $basic, 'source' => 'email'],
                ['name' => 'displayname', 'friendly' => 'displayname', 'nameformat' => $basic, 'source' => 'display_name'],
                ['name' => 'uid',         'friendly' => 'uid',         'nameformat' => $basic, 'source' => 'email'],
            ]];
        case 'grafana':
            return ['nameid_format' => 'emailAddress', 'nameid_attr' => 'email', 'attrs' => [
                ['name' => 'email',       'friendly' => 'email',       'nameformat' => $basic, 'source' => 'email'],
                ['name' => 'displayName', 'friendly' => 'displayName', 'nameformat' => $basic, 'source' => 'display_name'],
                ['name' => 'login',       'friendly' => 'login',       'nameformat' => $basic, 'source' => 'username'],
                ['name' => 'role',        'friendly' => 'role',        'nameformat' => $basic, 'source' => 'role'],
            ]];
        default: // generic — atrybuty OID + friendly, kompatybilne z większością SP
            return ['nameid_format' => 'emailAddress', 'nameid_attr' => 'email', 'attrs' => [
                ['name' => 'urn:oid:0.9.2342.19200300.100.1.3', 'friendly' => 'mail',        'nameformat' => $uri, 'source' => 'email'],
                ['name' => 'urn:oid:2.5.4.42',                  'friendly' => 'givenName',   'nameformat' => $uri, 'source' => 'first_name'],
                ['name' => 'urn:oid:2.5.4.4',                   'friendly' => 'sn',          'nameformat' => $uri, 'source' => 'last_name'],
                ['name' => 'urn:oid:2.16.840.1.113730.3.1.241', 'friendly' => 'displayName', 'nameformat' => $uri, 'source' => 'display_name'],
            ]];
    }
}

/**
 * Podpowiedzi endpointów SP — stałe dla presetów chmurowych (żaden na razie),
 * a dla ownCloud wyliczone z już skonfigurowanego adresu (admin/owncloud_settings.php),
 * bo `user_saml` w ownCloud ma przewidywalne ścieżki względem adresu instalacji.
 * @return array<string,array{entity_id:string,acs_url:string,slo_url?:string}>
 */
function saml_preset_endpoints(): array {
    $out = [];
    if (function_exists('owncloud_setting')) {
        $url = rtrim(owncloud_setting('url'), '/');
        if ($url !== '') {
            $out['owncloud'] = [
                'entity_id' => $url . '/apps/user_saml/saml/metadata',
                'acs_url'   => $url . '/apps/user_saml/saml/acs',
                'slo_url'   => $url . '/apps/user_saml/saml/sls',
            ];
        }
    }
    return $out;
}

/** Efektywna mapa atrybutów SP: własna (JSON) lub z presetu. */
function saml_sp_attr_map(array $sp): array {
    $raw = trim((string)($sp['attr_map'] ?? ''));
    if ($raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j) && $j) return $j;
    }
    return saml_preset($sp['preset'] ?? 'generic')['attrs'];
}

/** Wartość pola źródłowego z rekordu użytkownika. */
function saml_resolve_source(array $user, string $src): string {
    switch ($src) {
        case 'email':       return (string)($user['email'] ?? '');
        case 'username':    $e = (string)($user['email'] ?? ''); return strstr($e, '@', true) ?: $e;
        case 'name':
        case 'display_name':
            $n = trim((string)($user['name'] ?? ''));
            if ($n !== '') return $n;
            return trim(((string)($user['first_name'] ?? '')) . ' ' . ((string)($user['last_name'] ?? '')));
        case 'first_name':
        case 'given_name':  return (string)($user['first_name'] ?? '');
        case 'last_name':
        case 'surname':     return (string)($user['last_name'] ?? '');
        case 'role':        return (string)($user['role'] ?? '');
        case 'id':          return (string)($user['id'] ?? '');
        case 'phone':       return (string)($user['phone_number'] ?? '');
        default:            return isset($user[$src]) ? (string)$user[$src] : '';
    }
}

/** Wartość NameID zgodnie z konfiguracją SP. */
function saml_name_id_value(array $sp, array $user): string {
    $fmt = $sp['nameid_format'] ?? 'emailAddress';
    if ($fmt === 'persistent') {
        // Stabilny, nieodwracalny identyfikator: per użytkownik + per SP.
        $key = defined('APP_KEY') ? APP_KEY : 'saml';
        return hash('sha256', ($user['id'] ?? '') . '|' . ($sp['entity_id'] ?? '') . '|' . $key);
    }
    if ($fmt === 'transient') {
        return '_' . bin2hex(random_bytes(16));
    }
    return saml_resolve_source($user, $sp['nameid_attr'] ?: 'email');
}

// ── Parsowanie AuthnRequest ──────────────────────────────────────────────────────
/** Dekoduj SAMLRequest (Redirect: base64+deflate, POST: base64). */
function saml_decode_message(string $b64, bool $deflated): ?string {
    $raw = base64_decode($b64, true);
    if ($raw === false) return null;
    if ($deflated) {
        $xml = @gzinflate($raw);
        if ($xml === false) return null;
        return $xml;
    }
    return $raw;
}

/**
 * Sparsuj AuthnRequest XML.
 * @return array{id:string, issuer:string, acs_url:string, binding:string, force_authn:bool, is_passive:bool, nameid_format:string}|null
 */
function saml_parse_authn_request(string $xml): ?array {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$doc->loadXML($xml, LIBXML_NONET)) { libxml_clear_errors(); return null; }
    libxml_clear_errors();

    $xp = new DOMXPath($doc);
    $xp->registerNamespace('samlp', SAML_NS_SAMLP);
    $xp->registerNamespace('saml', SAML_NS_SAML);

    $req = $xp->query('/samlp:AuthnRequest')->item(0);
    if (!$req instanceof DOMElement) return null;

    $issuer = $xp->query('saml:Issuer', $req)->item(0);
    $np     = $xp->query('samlp:NameIDPolicy', $req)->item(0);

    return [
        'id'            => $req->getAttribute('ID'),
        'issuer'        => $issuer ? trim($issuer->textContent) : '',
        'acs_url'       => $req->getAttribute('AssertionConsumerServiceURL'),
        'binding'       => $req->getAttribute('ProtocolBinding'),
        'force_authn'   => $req->getAttribute('ForceAuthn') === 'true' || $req->getAttribute('ForceAuthn') === '1',
        'is_passive'    => $req->getAttribute('IsPassive') === 'true' || $req->getAttribute('IsPassive') === '1',
        'nameid_format' => $np instanceof DOMElement ? $np->getAttribute('Format') : '',
    ];
}

/**
 * Weryfikuj podpis na wiązaniu HTTP-Redirect (podpis nad query stringiem).
 * $rawQuery — surowy $_SERVER['QUERY_STRING'].
 */
function saml_verify_redirect_signature(array $sp, string $rawQuery): bool {
    if (trim((string)$sp['sp_cert']) === '') return false;

    // Wyciągnij surowe (zakodowane) wartości w kolejności wymaganej przez spec.
    $parts = [];
    foreach (explode('&', $rawQuery) as $pair) {
        $kv = explode('=', $pair, 2);
        $parts[$kv[0]] = $kv[1] ?? '';
    }
    if (!isset($parts['SAMLRequest'], $parts['SigAlg'], $parts['Signature'])) return false;

    $signed = 'SAMLRequest=' . $parts['SAMLRequest'];
    if (isset($parts['RelayState'])) $signed .= '&RelayState=' . $parts['RelayState'];
    $signed .= '&SigAlg=' . $parts['SigAlg'];

    $sigAlg    = rawurldecode($parts['SigAlg']);
    $signature = base64_decode(rawurldecode($parts['Signature']), true);
    if ($signature === false) return false;

    $pub = openssl_pkey_get_public($sp['sp_cert']);
    if (!$pub) return false;
    $alg = str_contains($sigAlg, 'sha256') ? OPENSSL_ALGO_SHA256
         : (str_contains($sigAlg, 'sha512') ? OPENSSL_ALGO_SHA512 : OPENSSL_ALGO_SHA1);

    return openssl_verify($signed, $signature, $pub, $alg) === 1;
}

/** Weryfikuj wbudowany podpis XML (wiązanie HTTP-POST) certyfikatem SP. */
function saml_verify_xml_signature(string $xml, string $spCertPem): bool {
    if (trim($spCertPem) === '') return false;
    saml_load_xmlseclibs();
    try {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        if (!$doc->loadXML($xml, LIBXML_NONET)) { libxml_clear_errors(); return false; }
        libxml_clear_errors();

        $dsig = new \RobRichards\XMLSecLibs\XMLSecurityDSig();
        $sig  = $dsig->locateSignature($doc);
        if (!$sig) return false;
        $dsig->canonicalizeSignedInfo();
        $dsig->idKeys = ['ID'];
        if (!$dsig->validateReference()) return false;

        $key = new \RobRichards\XMLSecLibs\XMLSecurityKey(
            \RobRichards\XMLSecLibs\XMLSecurityKey::RSA_SHA256, ['type' => 'public']);
        $key->loadKey($spCertPem, false, true);
        return $dsig->verify($key) === 1;
    } catch (\Throwable $e) {
        error_log('[saml] verify_xml_signature: ' . $e->getMessage());
        return false;
    }
}

// ── Budowa i podpis odpowiedzi SAML ──────────────────────────────────────────────
function saml_gen_id(): string { return '_' . bin2hex(random_bytes(20)); }
function saml_now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

/**
 * Zbuduj samlp:Response z saml:Assertion. Zwraca surowy XML.
 * $sessionIndex zwracany przez referencję (do zapisu aktywnej sesji / SLO).
 */
function saml_build_response(array $sp, array $user, string $inResponseTo, string $acsUrl, ?string &$sessionIndex = null): string {
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = false;

    $issuer       = saml_idp_entity_id();
    $respId       = saml_gen_id();
    $assertId     = saml_gen_id();
    $sessionIndex = saml_gen_id();
    $now          = saml_now();
    $notAfter     = gmdate('Y-m-d\TH:i:s\Z', time() + 300);   // 5 min
    $sessionAfter = gmdate('Y-m-d\TH:i:s\Z', time() + 28800); // 8 h

    // <samlp:Response>
    $resp = $doc->createElementNS(SAML_NS_SAMLP, 'samlp:Response');
    $doc->appendChild($resp);
    $resp->setAttribute('ID', $respId);
    $resp->setAttribute('Version', '2.0');
    $resp->setAttribute('IssueInstant', $now);
    $resp->setAttribute('Destination', $acsUrl);
    if ($inResponseTo !== '') $resp->setAttribute('InResponseTo', $inResponseTo);

    $resp->appendChild(saml_el($doc, SAML_NS_SAML, 'saml:Issuer', $issuer));

    // <samlp:Status>
    $status = $doc->createElementNS(SAML_NS_SAMLP, 'samlp:Status');
    $resp->appendChild($status);
    $sc = $doc->createElementNS(SAML_NS_SAMLP, 'samlp:StatusCode');
    $sc->setAttribute('Value', 'urn:oasis:names:tc:SAML:2.0:status:Success');
    $status->appendChild($sc);

    // <saml:Assertion>
    $assert = $doc->createElementNS(SAML_NS_SAML, 'saml:Assertion');
    $resp->appendChild($assert);
    $assert->setAttribute('ID', $assertId);
    $assert->setAttribute('Version', '2.0');
    $assert->setAttribute('IssueInstant', $now);

    $assert->appendChild(saml_el($doc, SAML_NS_SAML, 'saml:Issuer', $issuer));

    // <saml:Subject>
    $nameIdVal = saml_name_id_value($sp, $user);
    $subject = $doc->createElementNS(SAML_NS_SAML, 'saml:Subject');
    $assert->appendChild($subject);
    $nameId = saml_el($doc, SAML_NS_SAML, 'saml:NameID', $nameIdVal);
    $nameId->setAttribute('Format', saml_nameid_urn($sp['nameid_format'] ?? 'emailAddress'));
    if (($sp['nameid_format'] ?? '') === 'persistent') {
        $nameId->setAttribute('SPNameQualifier', $sp['entity_id']);
    }
    $subject->appendChild($nameId);

    $subjConf = $doc->createElementNS(SAML_NS_SAML, 'saml:SubjectConfirmation');
    $subjConf->setAttribute('Method', 'urn:oasis:names:tc:SAML:2.0:cm:bearer');
    $subject->appendChild($subjConf);
    $scd = $doc->createElementNS(SAML_NS_SAML, 'saml:SubjectConfirmationData');
    $scd->setAttribute('NotOnOrAfter', $notAfter);
    $scd->setAttribute('Recipient', $acsUrl);
    if ($inResponseTo !== '') $scd->setAttribute('InResponseTo', $inResponseTo);
    $subjConf->appendChild($scd);

    // <saml:Conditions>
    $cond = $doc->createElementNS(SAML_NS_SAML, 'saml:Conditions');
    $cond->setAttribute('NotBefore', $now);
    $cond->setAttribute('NotOnOrAfter', $notAfter);
    $assert->appendChild($cond);
    $audR = $doc->createElementNS(SAML_NS_SAML, 'saml:AudienceRestriction');
    $cond->appendChild($audR);
    $audR->appendChild(saml_el($doc, SAML_NS_SAML, 'saml:Audience', $sp['entity_id']));

    // <saml:AuthnStatement>
    $authn = $doc->createElementNS(SAML_NS_SAML, 'saml:AuthnStatement');
    $authn->setAttribute('AuthnInstant', $now);
    $authn->setAttribute('SessionIndex', $sessionIndex);
    $authn->setAttribute('SessionNotOnOrAfter', $sessionAfter);
    $assert->appendChild($authn);
    $ac = $doc->createElementNS(SAML_NS_SAML, 'saml:AuthnContext');
    $authn->appendChild($ac);
    $acr = $doc->createElementNS(SAML_NS_SAML, 'saml:AuthnContextClassRef',
        'urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport');
    $ac->appendChild($acr);

    // <saml:AttributeStatement>
    $attrs = saml_sp_attr_map($sp);
    if ($attrs) {
        $as = $doc->createElementNS(SAML_NS_SAML, 'saml:AttributeStatement');
        $assert->appendChild($as);
        foreach ($attrs as $a) {
            $val = saml_resolve_source($user, $a['source'] ?? '');
            if ($val === '') continue;
            $attr = $doc->createElementNS(SAML_NS_SAML, 'saml:Attribute');
            $attr->setAttribute('Name', $a['name'] ?? '');
            if (!empty($a['friendly']))   $attr->setAttribute('FriendlyName', $a['friendly']);
            if (!empty($a['nameformat'])) $attr->setAttribute('NameFormat', $a['nameformat']);
            $av = saml_el($doc, SAML_NS_SAML, 'saml:AttributeValue', $val);
            $av->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:type', 'xs:string');
            $attr->appendChild($av);
            $as->appendChild($attr);
        }
    }

    // ── Podpisy ──────────────────────────────────────────────────────────────
    $signAssertion = (int)($sp['sign_assertion'] ?? 1) === 1;
    $signResponse  = (int)($sp['sign_response'] ?? 0) === 1;

    if ($signAssertion) saml_sign_element($doc, $assert);
    if ($signResponse)  saml_sign_element($doc, $resp);

    return $doc->saveXML();
}

/**
 * Utwórz element z bezpieczną zawartością tekstową.
 * Wartość wstawiamy jako węzeł tekstowy — DOM escape'uje '&', '<' itd.
 * (createElementNS z 3. argumentem NIE escape'uje i psuje XML przy '&').
 */
function saml_el(DOMDocument $doc, string $ns, string $qname, ?string $text = null): DOMElement {
    $el = $doc->createElementNS($ns, $qname);
    if ($text !== null && $text !== '') $el->appendChild($doc->createTextNode($text));
    return $el;
}

/**
 * Podpisz element (Assertion/Response) podpisem enveloped, EXC-C14N, RSA-SHA256.
 * Podpis wstawiany tuż po <saml:Issuer> elementu.
 */
function saml_sign_element(DOMDocument $doc, DOMElement $node): void {
    saml_load_xmlseclibs();
    $dsig = new \RobRichards\XMLSecLibs\XMLSecurityDSig();
    $dsig->setCanonicalMethod(\RobRichards\XMLSecLibs\XMLSecurityDSig::EXC_C14N);
    $dsig->addReference(
        $node,
        \RobRichards\XMLSecLibs\XMLSecurityDSig::SHA256,
        ['http://www.w3.org/2000/09/xmldsig#enveloped-signature',
         \RobRichards\XMLSecLibs\XMLSecurityDSig::EXC_C14N],
        ['id_name' => 'ID', 'overwrite' => false]
    );
    $key = new \RobRichards\XMLSecLibs\XMLSecurityKey(
        \RobRichards\XMLSecLibs\XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
    $key->loadKey(saml_idp_key_pem(), false);
    $dsig->sign($key);
    $dsig->add509Cert(saml_idp_cert_clean(), true, false);

    // Wstaw <ds:Signature> tuż po <saml:Issuer>.
    $issuer = null;
    foreach ($node->childNodes as $ch) {
        if ($ch instanceof DOMElement && $ch->localName === 'Issuer') { $issuer = $ch; break; }
    }
    $before = $issuer ? $issuer->nextSibling : $node->firstChild;
    $dsig->insertSignature($node, $before);
}

// ── Wysyłka odpowiedzi (HTTP-POST binding — auto-submit form) ─────────────────────
function saml_post_to_acs(string $acsUrl, string $samlResponseB64, string $relayState): never {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    header('Pragma: no-cache');
    $u  = htmlspecialchars($acsUrl, ENT_QUOTES);
    $r  = htmlspecialchars($samlResponseB64, ENT_QUOTES);
    $rs = htmlspecialchars($relayState, ENT_QUOTES);
    echo <<<HTML
<!DOCTYPE html>
<html lang="pl"><head><meta charset="utf-8"><title>Logowanie…</title></head>
<body onload="document.forms[0].submit()">
<noscript><p>Twoja przeglądarka nie obsługuje JavaScript. Kliknij przycisk, aby kontynuować.</p></noscript>
<form method="post" action="{$u}">
<input type="hidden" name="SAMLResponse" value="{$r}">
<input type="hidden" name="RelayState" value="{$rs}">
<noscript><button type="submit">Kontynuuj</button></noscript>
</form>
<p style="font-family:sans-serif;color:#666">Przekierowywanie do aplikacji…</p>
</body></html>
HTML;
    exit;
}

// ── Logowanie zdarzeń SSO ─────────────────────────────────────────────────────────
function saml_log(array $row): void {
    // Uwaga: nie używamy db_insert() — zamieniłby puste *_id (request_id,
    // assertion_id) na NULL, łamiąc NOT NULL. Wstawiamy wartości wprost.
    $d = array_merge([
        'sp_id' => null, 'sp_entity' => '', 'user_id' => null, 'user_email' => '',
        'name_id' => '', 'request_id' => '', 'assertion_id' => '', 'session_index' => '',
        'relay_state' => '', 'binding' => '', 'event' => 'sso', 'result' => 'ok',
        'detail' => '', 'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ], $row);
    try {
        $cols = array_keys($d);
        $ph   = ':' . implode(', :', $cols);
        $sql  = 'INSERT INTO saml_sso_log (' . implode(', ', $cols) . ') VALUES (' . $ph . ')';
        db()->prepare($sql)->execute($d);
    } catch (\Throwable $e) { error_log('[saml] log: ' . $e->getMessage()); }
}

/** Zapisz aktywną sesję SSO (mapowanie sesji PHP -> SP) dla SLO. */
function saml_record_active_session(array $sp, array $user, string $nameId, string $sessionIndex): void {
    try {
        $sid = session_id() ?: '';
        if ($sid === '') return;
        db_insert('saml_active_sessions', [
            'php_session_id' => $sid,
            'sp_id'          => (int)$sp['id'],
            'user_id'        => (int)($user['id'] ?? 0) ?: null,
            'name_id'        => $nameId,
            'name_id_format' => saml_nameid_urn($sp['nameid_format'] ?? 'emailAddress'),
            'session_index'  => $sessionIndex,
        ]);
    } catch (\Throwable $e) { error_log('[saml] active_session: ' . $e->getMessage()); }
}

// ── Metadata IdP ──────────────────────────────────────────────────────────────────
function saml_idp_metadata_xml(): string {
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;

    $ed = $doc->createElementNS(SAML_NS_MD, 'md:EntityDescriptor');
    $doc->appendChild($ed);
    $ed->setAttribute('entityID', saml_idp_entity_id());

    $idp = $doc->createElementNS(SAML_NS_MD, 'md:IDPSSODescriptor');
    $idp->setAttribute('protocolSupportEnumeration', SAML_NS_SAMLP);
    $idp->setAttribute('WantAuthnRequestsSigned', 'false');
    $ed->appendChild($idp);

    // KeyDescriptor (signing)
    $cert = saml_idp_cert_clean();
    if ($cert !== '') {
        $kd = $doc->createElementNS(SAML_NS_MD, 'md:KeyDescriptor');
        $kd->setAttribute('use', 'signing');
        $idp->appendChild($kd);
        $ki = $doc->createElementNS(SAML_NS_DS, 'ds:KeyInfo');
        $kd->appendChild($ki);
        $xd = $doc->createElementNS(SAML_NS_DS, 'ds:X509Data');
        $ki->appendChild($xd);
        $xc = $doc->createElementNS(SAML_NS_DS, 'ds:X509Certificate', $cert);
        $xd->appendChild($xc);
    }

    // SingleLogoutService (Redirect)
    $slo = $doc->createElementNS(SAML_NS_MD, 'md:SingleLogoutService');
    $slo->setAttribute('Binding', 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect');
    $slo->setAttribute('Location', saml_idp_slo_url());
    $idp->appendChild($slo);

    // NameIDFormats
    foreach (['emailAddress', 'persistent', 'transient', 'unspecified'] as $f) {
        $nf = $doc->createElementNS(SAML_NS_MD, 'md:NameIDFormat', saml_nameid_urn($f));
        $idp->appendChild($nf);
    }

    // SingleSignOnService (Redirect + POST)
    foreach ([
        'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
        'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
    ] as $b) {
        $sso = $doc->createElementNS(SAML_NS_MD, 'md:SingleSignOnService');
        $sso->setAttribute('Binding', $b);
        $sso->setAttribute('Location', saml_idp_sso_url());
        $idp->appendChild($sso);
    }

    return $doc->saveXML();
}

// ── Import metadata SP ──────────────────────────────────────────────────────────
/**
 * Sparsuj metadata SP (XML) -> pola do zapisu w saml_sp.
 * @return array{entity_id:string, acs_url:string, acs_binding:string, slo_url:string, sp_cert:string}|null
 */
function saml_parse_sp_metadata(string $xml): ?array {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$doc->loadXML($xml, LIBXML_NONET)) { libxml_clear_errors(); return null; }
    libxml_clear_errors();

    $xp = new DOMXPath($doc);
    $xp->registerNamespace('md', SAML_NS_MD);
    $xp->registerNamespace('ds', SAML_NS_DS);

    $ed = $xp->query('//md:EntityDescriptor')->item(0);
    if (!$ed instanceof DOMElement) return null;
    $entityId = $ed->getAttribute('entityID');

    // ACS — preferuj HTTP-POST, w razie braku pierwszy dostępny.
    $acsUrl = ''; $acsBinding = 'HTTP-POST';
    $acsNodes = $xp->query('.//md:SPSSODescriptor/md:AssertionConsumerService', $ed);
    foreach ($acsNodes as $n) {
        if (str_contains($n->getAttribute('Binding'), 'HTTP-POST')) {
            $acsUrl = $n->getAttribute('Location'); break;
        }
    }
    if ($acsUrl === '' && $acsNodes->length > 0) {
        $acsUrl = $acsNodes->item(0)->getAttribute('Location');
        $acsBinding = str_contains($acsNodes->item(0)->getAttribute('Binding'), 'Redirect') ? 'HTTP-Redirect' : 'HTTP-POST';
    }

    // SLO
    $sloUrl = '';
    $sloNodes = $xp->query('.//md:SPSSODescriptor/md:SingleLogoutService', $ed);
    foreach ($sloNodes as $n) {
        if (str_contains($n->getAttribute('Binding'), 'HTTP-Redirect')) { $sloUrl = $n->getAttribute('Location'); break; }
    }
    if ($sloUrl === '' && $sloNodes->length > 0) $sloUrl = $sloNodes->item(0)->getAttribute('Location');

    // Cert (signing lub pierwszy)
    $cert = '';
    $certNodes = $xp->query('.//md:SPSSODescriptor//ds:X509Certificate', $ed);
    if ($certNodes->length > 0) {
        $b64 = preg_replace('/\s+/', '', $certNodes->item(0)->textContent);
        $cert = "-----BEGIN CERTIFICATE-----\n" . chunk_split($b64, 64, "\n") . "-----END CERTIFICATE-----\n";
    }

    if ($entityId === '' || $acsUrl === '') return null;
    return [
        'entity_id'   => $entityId,
        'acs_url'     => $acsUrl,
        'acs_binding' => $acsBinding,
        'slo_url'     => $sloUrl,
        'sp_cert'     => $cert,
    ];
}

// ── Parsowanie LogoutRequest / budowa LogoutResponse (SLO) ───────────────────────
/** @return array{id:string, issuer:string, name_id:string, session_index:string}|null */
function saml_parse_logout_request(string $xml): ?array {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$doc->loadXML($xml, LIBXML_NONET)) { libxml_clear_errors(); return null; }
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('samlp', SAML_NS_SAMLP);
    $xp->registerNamespace('saml', SAML_NS_SAML);
    $req = $xp->query('/samlp:LogoutRequest')->item(0);
    if (!$req instanceof DOMElement) return null;
    $issuer  = $xp->query('saml:Issuer', $req)->item(0);
    $nameId  = $xp->query('saml:NameID', $req)->item(0);
    $sidx    = $xp->query('samlp:SessionIndex', $req)->item(0);
    return [
        'id'            => $req->getAttribute('ID'),
        'issuer'        => $issuer ? trim($issuer->textContent) : '',
        'name_id'       => $nameId ? trim($nameId->textContent) : '',
        'session_index' => $sidx ? trim($sidx->textContent) : '',
    ];
}

/** Zbuduj LogoutResponse (status Success) — surowy XML. */
function saml_build_logout_response(string $inResponseTo, string $destination): string {
    $doc = new DOMDocument('1.0', 'UTF-8');
    $resp = $doc->createElementNS(SAML_NS_SAMLP, 'samlp:LogoutResponse');
    $doc->appendChild($resp);
    $resp->setAttribute('ID', saml_gen_id());
    $resp->setAttribute('Version', '2.0');
    $resp->setAttribute('IssueInstant', saml_now());
    if ($destination !== '')   $resp->setAttribute('Destination', $destination);
    if ($inResponseTo !== '')  $resp->setAttribute('InResponseTo', $inResponseTo);
    $resp->appendChild(saml_el($doc, SAML_NS_SAML, 'saml:Issuer', saml_idp_entity_id()));
    $status = $doc->createElementNS(SAML_NS_SAMLP, 'samlp:Status');
    $resp->appendChild($status);
    $sc = $doc->createElementNS(SAML_NS_SAMLP, 'samlp:StatusCode');
    $sc->setAttribute('Value', 'urn:oasis:names:tc:SAML:2.0:status:Success');
    $status->appendChild($sc);
    return $doc->saveXML();
}

/** Zakoduj wiadomość do wiązania HTTP-Redirect (deflate + base64). */
function saml_encode_redirect(string $xml): string {
    return base64_encode(gzdeflate($xml));
}

/**
 * Zbuduj podpisany query string dla wiązania HTTP-Redirect (RSA-SHA256).
 * $type: 'SAMLResponse' | 'SAMLRequest'. $message: wynik saml_encode_redirect().
 * Zwraca gotowy query string (bez wiodącego '?').
 */
function saml_redirect_sign(string $type, string $message, string $relayState): string {
    $sigAlg = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    $q = $type . '=' . rawurlencode($message);
    if ($relayState !== '') $q .= '&RelayState=' . rawurlencode($relayState);
    $q .= '&SigAlg=' . rawurlencode($sigAlg);
    $sig = '';
    $key = openssl_pkey_get_private(saml_idp_key_pem());
    if ($key && openssl_sign($q, $sig, $key, OPENSSL_ALGO_SHA256)) {
        $q .= '&Signature=' . rawurlencode(base64_encode($sig));
    }
    return $q;
}
