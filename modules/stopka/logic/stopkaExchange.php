<?php
/**
 * modules/stopka/logic/stopkaExchange.php — WSPÓŁDZIELONE połączenie SZO z Exchange Online (REST „InvokeCommand”)
 * i kreator konfiguracji uprawnień. Korzysta z niego moduł aliasów poczty (modules/mail_alias, admin/email_aliasy.php)
 * oraz cli/exoDiag.php.
 *
 * Automatyczna stopka przez regułę transportową Exchange (tryb Audit/Enforce, agent CRON, CLI, ustawienia ex_*)
 * została USUNIĘTA 2026-11-15 (polecenie „wywal stopki te exchangowe”). Zostały: generator i konfigurator stopki
 * (ręczne kopiowanie) oraz publiczny /pomocne/generatorstopki. Kolumny ex_* w stopka_settings są nieużywane i zostają w bazie.
 *
 * Wymaga w Entra ID: uprawnienia aplikacji Office 365 Exchange Online → Exchange.ManageAsApp (zgoda administratora),
 * certyfikatu aplikacji i roli „Exchange Administrator” przypisanej aplikacji (kreator: modules/stopka/index.php#h-wz).
 */

/**
 * Certyfikat aplikacji dla Exchange Online. Administracyjne API Exchange (InvokeCommand) w trybie aplikacji
 * przyjmuje WYŁĄCZNIE tokeny uzyskane certyfikatem (claim appidacr=2) — token z sekretu klienta (appidacr=1)
 * dostaje 403 bez treści mimo uprawnienia Exchange.ManageAsApp i roli Exchange Administrator.
 * SZO generuje własną parę kluczy (certs/exo_app.key|crt, poza repozytorium); część publiczną (.cer)
 * administrator wgrywa w Entra ID → Rejestracje aplikacji → ta aplikacja → Certyfikaty i wpisy tajne.
 * Zwraca ['crt' => PEM, 'key' => PEM, 'x5t' => base64url(SHA-1 DER), 'thumb' => SHA-1 hex, 'valid_to' => Y-m-d].
 */
function stopkaExCert(bool $create = true): ?array {
    $dir = dirname(__DIR__, 3) . '/certs/';
    $keyF = $dir . 'exo_app.key'; $crtF = $dir . 'exo_app.crt';
    if ((!is_file($keyF) || !is_file($crtF)) && $create) {
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (!$key) throw new RuntimeException('Nie udało się wygenerować klucza certyfikatu (OpenSSL).');
        $org = mb_substr((string)(org_setting('org_short_name') ?: org_setting('org_name') ?: 'SZO'), 0, 40);
        $csr = openssl_csr_new(['commonName' => 'SZO Exchange stopka — ' . $org], $key, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $key, 1095, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        openssl_pkey_export($key, $keyPem);
        openssl_x509_export($x509, $crtPem);
        if (@file_put_contents($keyF, $keyPem) === false || @file_put_contents($crtF, $crtPem) === false) {
            throw new RuntimeException('Nie udało się zapisać certyfikatu w katalogu certs/.');
        }
        @chmod($keyF, 0600);
    }
    if (!is_file($keyF) || !is_file($crtF)) return null;
    $crt = (string)file_get_contents($crtF);
    $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $crt));
    $sha = sha1($der, true);
    $info = openssl_x509_parse($crt) ?: [];
    return ['crt' => $crt, 'key' => (string)file_get_contents($keyF), 'x5t' => rtrim(strtr(base64_encode($sha), '+/', '-_'), '='),
            'thumb' => strtoupper(bin2hex($sha)), 'valid_to' => isset($info['validTo_time_t']) ? date('Y-m-d', (int)$info['validTo_time_t']) : ''];
}

/** client_assertion (JWT RS256 podpisany kluczem certyfikatu) do żądania tokenu. */
function stopkaExAssertion(string $tenant, string $cid, array $cert): string {
    $b = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $now = time();
    $head = $b(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'x5t' => $cert['x5t']]));
    $body = $b(json_encode(['aud' => "https://login.microsoftonline.com/$tenant/oauth2/v2.0/token", 'iss' => $cid, 'sub' => $cid,
                            'jti' => bin2hex(random_bytes(16)), 'nbf' => $now - 60, 'iat' => $now - 60, 'exp' => $now + 600]));
    if (!openssl_sign("$head.$body", $sig, $cert['key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('Nie udało się podpisać żądania tokenu certyfikatem.');
    return "$head.$body." . $b($sig);
}

/**
 * Token aplikacji (client credentials) dla zasobu $resource. Zwraca [token, claims]; NIE sprawdza ról.
 * $useCert=true: uwierzytelnienie certyfikatem (client_assertion) zamiast sekretu — wymagane przez Exchange.
 */
function stopkaExAppToken(string $resource, bool $useCert = false): array {
    $tenant = m365_setting('m365_tenant_id') ?: (defined('MS_TENANT_ID') ? MS_TENANT_ID : '');
    $cid    = m365_setting('m365_graph_client_id');
    $sec    = m365_setting('m365_graph_client_secret');
    if ($tenant === '' || $cid === '' || (!$useCert && $sec === '')) throw new RuntimeException('Microsoft 365 (Graph) nie jest skonfigurowany.');
    $auth = ['client_secret' => $sec];
    if ($useCert) {
        $cert = stopkaExCert();
        if (!$cert) throw new RuntimeException('Brak certyfikatu aplikacji dla Exchange.');
        $auth = ['client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                 'client_assertion' => stopkaExAssertion($tenant, $cid, $cert)];
    }
    $r = stopkaExHttp('POST', "https://login.microsoftonline.com/$tenant/oauth2/v2.0/token", [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'grant_type' => 'client_credentials', 'client_id' => $cid,
        'scope' => $resource . '/.default',
    ] + $auth));
    if (empty($r['access_token'])) {
        throw new RuntimeException('Token ' . $resource . ': ' . ($r['error_description'] ?? $r['error'] ?? 'brak odpowiedzi'));
    }
    return [$r['access_token'], M365Graph::decode_jwt($r['access_token'])];
}

function stopkaExToken(): string {
    try {
        [$tok, $claims] = stopkaExAppToken('https://outlook.office365.com', true);
    } catch (RuntimeException $e) {
        throw new RuntimeException($e->getMessage() . ' — wgraj certyfikat SZO w Entra ID (kreator Exchange, krok „Certyfikat aplikacji”)'
            . ' i sprawdź uprawnienie Exchange.ManageAsApp.');
    }
    $r = ['access_token' => $tok];
    $roles  = $claims['roles'] ?? [];
    if (!in_array('Exchange.ManageAsApp', $roles, true)) {
        throw new RuntimeException('Token aplikacji do Exchange Online nie zawiera uprawnienia Exchange.ManageAsApp'
            . ' (aud: ' . ($claims['aud'] ?? '?') . ', role: ' . ($roles ? implode(', ', $roles) : 'brak') . ').'
            . ' Entra ID → Rejestracje aplikacji → ta aplikacja → Uprawnienia API → Dodaj → Office 365 Exchange Online'
            . ' (zakładka „API używane przez moją organizację”) → Uprawnienia aplikacji → Exchange.ManageAsApp → Udziel zgody administratora.');
    }
    return $r['access_token'];
}

function stopkaExHttp(string $method, string $url, array $headers, string $body): array {
    $ch = curl_init($url);
    $hdrs = [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$hdrs) {
            if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $hdrs[strtolower(trim($k))] = trim($v); }
            return strlen($line);
        }]);
    $out = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($out === false) throw new RuntimeException('Błąd połączenia: ' . $err);
    $j = json_decode($out, true);
    if (!is_array($j)) {
        $hint = '';
        if ($hdrs['www-authenticate'] ?? '') $hint .= ' WWW-Authenticate: ' . $hdrs['www-authenticate'] . '.';
        if ($hdrs['x-ms-diagnostics'] ?? '') $hint .= ' x-ms-diagnostics: ' . $hdrs['x-ms-diagnostics'] . '.';
        if ($hdrs['request-id'] ?? '') $hint .= ' request-id: ' . $hdrs['request-id'] . '.';
        if ($code === 401 || $code === 403) {
            $hint .= ' Zwykle znaczy to brak roli administracyjnej aplikacji w Exchange (Entra ID → Role i administratorzy →'
                . ' „Exchange Administrator” → Dodaj przypisania → wybierz TĘ aplikację) lub brak zgody administratora na Exchange.ManageAsApp;'
                . ' po nadaniu roli odczekaj kilka–kilkanaście minut.';
        }
        $snippet = trim(mb_substr(strip_tags((string)$out), 0, 200));
        throw new RuntimeException("Exchange zwrócił HTTP $code bez JSON." . $hint . ($snippet !== '' ? " Treść: $snippet" : ''));
    }
    if ($code >= 400 && empty($j['error'])) $j['error'] = ['message' => "HTTP $code"];
    return $j;
}

/**
 * Domeny do nagłówka X-AnchorMailbox (APP:SystemMailbox{…}@<organizacja>), w kolejności prób.
 * Exchange Online (jak moduł EXO V3) kieruje żądania app-only po DOMENIE organizacji — najlepiej domenie
 * początkowej *.onmicrosoft.com; GUID dzierżawy w kotwicy daje 403 bez treści mimo poprawnych ról.
 * Kolejność: domena, która ostatnio zadziałała → domena początkowa z Graph → m365_domain z ustawień → GUID.
 */
function stopkaExAnchorDomains(string $tenant): array {
    $out = [];
    $ok = org_setting('stopka_ex_anchor_domain');
    if ($ok !== '') $out[] = $ok;
    static $initial = null;
    if ($initial === null) {
        $initial = '';
        try {
            [$gtok] = stopkaExAppToken('https://graph.microsoft.com');
            foreach (["https://graph.microsoft.com/v1.0/organization?\$select=verifiedDomains", "https://graph.microsoft.com/v1.0/domains?\$select=id,isInitial"] as $u) {
                $r = stopkaExHttp('GET', $u, ['Authorization: Bearer ' . $gtok, 'Accept: application/json'], '');
                foreach (($r['value'][0]['verifiedDomains'] ?? $r['value'] ?? []) as $d) {
                    if (!empty($d['isInitial'])) { $initial = (string)($d['name'] ?? $d['id'] ?? ''); break 2; }
                }
            }
        } catch (Throwable $e) { /* brak Organization.Read.All / Domain.Read.All — zostają zapasowe */ }
    }
    if ($initial !== '') $out[] = $initial;
    $dom = trim((string)m365_setting('m365_domain'));
    if ($dom !== '') $out[] = $dom;
    if ($tenant !== '') $out[] = $tenant;
    return array_values(array_unique(array_filter($out)));
}

/**
 * Diagnostyka tokenu przy 401/403 z Exchange: czy token jest z certyfikatu (appidacr=2) i czy zawiera rolę
 * katalogową Exchange Administrator (claim „wids”, szablon 29232cdf-…). Rola nadana aplikacji, ale nieaktywna
 * (np. PIM „Kwalifikujące się”, przypisanie innej aplikacji o tej samej nazwie, jeszcze niezreplikowana)
 * nie pojawia się w „wids” — wtedy Exchange odpowiada 403 bez treści mimo uprawnienia Exchange.ManageAsApp.
 */
function stopkaExTokenDiag(string $token): string {
    $c = M365Graph::decode_jwt($token);
    $wids = (array)($c['wids'] ?? []);
    $exAdmin = in_array('29232cdf-9323-42fd-ade2-1d097af3e4de', $wids, true) || in_array('62e90394-69f5-4237-9190-012177145e10', $wids, true);
    $acr = (string)($c['appidacr'] ?? $c['azpacr'] ?? '?');
    $out = ' Token: aplikacja ' . ($c['appid'] ?? $c['azp'] ?? '?') . ', uwierzytelnienie ' . ($acr === '2' ? 'certyfikatem' : ($acr === '1' ? 'SEKRETEM (Exchange wymaga certyfikatu)' : $acr))
         . ', role katalogowe w tokenie: ' . ($wids ? implode(', ', $wids) : 'brak') . '.';
    $out .= $exAdmin
        ? ' Rola Exchange Administrator (lub Global Administrator) JEST w tokenie — uprawnienia są dobre; 403 wynika z kierowania żądania (kotwica skrzynki) albo z blokady po stronie Exchange (np. zasady dostępu aplikacji / dostęp warunkowy).'
        : ' Roli Exchange Administrator NIE MA w tokenie — Entra nie widzi jej jako aktywnej dla tej aplikacji: sprawdź, że przypisanie jest „Aktywne” (nie „Kwalifikujące się” w PIM), dotyczy aplikacji o identyfikatorze ' . ($c['appid'] ?? $c['azp'] ?? '?') . ' (a nie innej o tej samej nazwie), zakres „Katalog”, i odczekaj do 1 h na replikację.';
    return $out;
}

/** Wywołuje cmdlet Exchange Online. Zwraca listę wyników; rzuca wyjątek z treścią błędu. */
function stopkaExInvoke(string $token, string $cmdlet, array $params = []): array {
    $tenant = m365_setting('m365_tenant_id') ?: (defined('MS_TENANT_ID') ? MS_TENANT_ID : '');
    $body = json_encode(['CmdletInput' => ['CmdletName' => $cmdlet, 'Parameters' => (object)$params]], JSON_UNESCAPED_UNICODE);
    $last = null;
    $doms = stopkaExAnchorDomains($tenant);
    // Prefiks kotwicy: moduł EXO V3 w trybie aplikacji używa „APP:”, część dzierżaw przyjmuje tylko „UPN:” — próbujemy obu
    $prefixes = array_values(array_unique(array_filter([org_setting('stopka_ex_anchor_prefix'), 'APP', 'UPN'])));
    foreach ($doms as $dom) {
        foreach ($prefixes as $pfx) {
            try {
                $r = stopkaExHttp('POST', "https://outlook.office365.com/adminapi/beta/$tenant/InvokeCommand", [
                    'Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json;odata.metadata=minimal',
                    'X-ResponseFormat: json', 'X-CmdletName: ' . $cmdlet, 'X-ClientApplication: ExoManagementModule',
                    'X-AnchorMailbox: ' . $pfx . ':SystemMailbox{bb558c35-97f1-4cb9-8ff7-d53741dc928c}@' . $dom,
                ], $body);
            } catch (RuntimeException $e) {
                // 401/403 bez treści przy tej kotwicy — spróbuj następnej; inne błędy przerywają
                if (!preg_match('/HTTP 40[13] bez JSON/', $e->getMessage())) throw $e;
                $last = $e;
                continue;
            }
            if (org_setting('stopka_ex_anchor_domain') !== $dom) org_setting_set('stopka_ex_anchor_domain', $dom);
            if (org_setting('stopka_ex_anchor_prefix') !== $pfx) org_setting_set('stopka_ex_anchor_prefix', $pfx);
            if (!empty($r['error'])) {
                $m = $r['error']['message'] ?? ($r['error']['code'] ?? 'błąd');
                throw new RuntimeException("$cmdlet: " . (is_string($m) ? $m : json_encode($m, JSON_UNESCAPED_UNICODE)));
            }
            return $r['value'] ?? [];
        }
    }
    if ($last) throw new RuntimeException($last->getMessage() . ' Sprawdzone kotwice skrzynki: ' . implode(', ', $doms)
        . ' (prefiksy ' . implode('/', $prefixes) . ').' . stopkaExTokenDiag($token));
    throw new RuntimeException('Brak identyfikatora dzierżawy Microsoft 365.');
}

// ── Kreator konfiguracji Exchange (graficzny, panel administratora) ──────────────────────

const STOPKA_EX_RESOURCE_APP = '00000002-0000-0ff1-ce00-000000000000';   // Office 365 Exchange Online
const STOPKA_EX_ROLE_ID      = 'dc50a0fb-09a3-484d-be87-e023b12c6440';   // Exchange.ManageAsApp (appRole)
const STOPKA_EX_ADMIN_ROLE   = '29232cdf-9323-42fd-ade2-1d097af3e4de';   // Entra: Exchange Administrator

/**
 * Stan kreatora: dane aplikacji, linki do Entra ID, skrypt PowerShell i status kroków
 * (app, perm, consent, cert, role) — każdy: status ok|fail|wait|info + komunikat.
 * \$probe=false: bez połączeń z Microsoft (pierwsze renderowanie strony, kroki „loading”).
 * Tylko odczyty; niczego nie zmienia w Entra ID ani w Exchange.
 */
function stopkaExWizardState(bool $probe = true): array {
    $tenant = m365_setting('m365_tenant_id') ?: (defined('MS_TENANT_ID') ? MS_TENANT_ID : '');
    $cid    = m365_setting('m365_graph_client_id');
    $hasSec = m365_setting('m365_graph_client_secret') !== '';
    $st = ['tenant' => $tenant, 'clientId' => $cid, 'checkedAt' => date('Y-m-d H:i:s'), 'links' => [
        'perms'   => $cid !== '' ? 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationMenuBlade/~/CallAnAPI/appId/' . rawurlencode($cid) . '/isMSAApp~/false' : '',
        'overview'=> $cid !== '' ? 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationMenuBlade/~/Overview/appId/' . rawurlencode($cid) . '/isMSAApp~/false' : '',
        'role'    => 'https://entra.microsoft.com/#view/Microsoft_AAD_IAM/RoleMenuBlade/~/RoleMembers/objectId/' . STOPKA_EX_ADMIN_ROLE . '/roleTemplateId/' . STOPKA_EX_ADMIN_ROLE,
        'certs'   => $cid !== '' ? 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationMenuBlade/~/Credentials/appId/' . rawurlencode($cid) . '/isMSAApp~/false' : '',
        'certDownload' => APP_URL . '/modules/stopka/index.php?download=exo_cert',
    ], 'steps' => []];
    $st['script'] = stopkaExWizardScript($tenant, $cid);
    $wait = ['status' => 'wait', 'msg' => 'Najpierw dokończ poprzedni krok.'];
    if (!$probe) {
        foreach (['app', 'perm', 'consent', 'cert', 'role'] as $k) $st['steps'][$k] = ['status' => 'loading', 'msg' => 'Sprawdzanie…'];
        return $st;
    }

    $appOk = $tenant !== '' && $cid !== '' && $hasSec;
    $st['steps']['app'] = $appOk
        ? ['status' => 'ok', 'msg' => 'Aplikacja ' . $cid . ' w dzierżawie ' . $tenant . '.']
        : ['status' => 'fail', 'msg' => 'Brakuje: ' . implode(', ', array_keys(array_filter(['identyfikatora dzierżawy' => $tenant === '', 'Client ID' => $cid === '', 'sekretu' => !$hasSec]))) . '. Uzupełnij je w ustawieniach Microsoft 365 SZO.'];
    if (!$appOk) {
        foreach (['perm', 'consent', 'cert', 'role'] as $k) $st['steps'][$k] = $wait;
        return $st;
    }

    // Czy uprawnienie jest zadeklarowane w rejestracji aplikacji (wymaga Application.Read.All — jeśli brak, „nie wiadomo”)
    $declared = null;
    try {
        [$gtok] = stopkaExAppToken('https://graph.microsoft.com');
        $app = stopkaExHttp('GET', "https://graph.microsoft.com/v1.0/applications(appId='" . rawurlencode($cid) . "')?\$select=requiredResourceAccess",
            ['Authorization: Bearer ' . $gtok, 'Accept: application/json'], '');
        if (empty($app['error'])) {
            $declared = false;
            foreach ($app['requiredResourceAccess'] ?? [] as $rra) {
                if (($rra['resourceAppId'] ?? '') !== STOPKA_EX_RESOURCE_APP) continue;
                foreach ($rra['resourceAccess'] ?? [] as $ra) {
                    if (strtolower($ra['id'] ?? '') === STOPKA_EX_ROLE_ID && ($ra['type'] ?? '') === 'Role') $declared = true;
                }
            }
        }
    } catch (Throwable $e) { /* brak Application.Read.All — zostaje null */ }

    // Zgoda = rola w tokenie Exchange
    $tok = null; $consented = false;
    try {
        [$tok, $claims] = stopkaExAppToken('https://outlook.office365.com');
        $consented = in_array('Exchange.ManageAsApp', $claims['roles'] ?? [], true);
    } catch (Throwable $e) {
        $st['steps']['perm']    = ['status' => 'fail', 'msg' => $e->getMessage()];
        $st['steps']['consent'] = $wait; $st['steps']['cert'] = $wait; $st['steps']['role'] = $wait;
        return $st;
    }
    if ($consented) {
        $st['steps']['perm']    = ['status' => 'ok', 'msg' => 'Uprawnienie Exchange.ManageAsApp jest przypisane aplikacji.'];
        $st['steps']['consent'] = ['status' => 'ok', 'msg' => 'Zgoda administratora udzielona — rola jest w tokenie.'];
        // Exchange przyjmuje tylko token z certyfikatu (appidacr=2) — bez tego krok 5 zawsze kończy się 403
        try {
            $cert = stopkaExCert();
            $st['cert'] = $cert ? ['thumb' => $cert['thumb'], 'validTo' => $cert['valid_to']] : null;
            [$ctok, $cclaims] = stopkaExAppToken('https://outlook.office365.com', true);
            $tok = $ctok;
            $st['steps']['cert'] = ['status' => 'ok', 'msg' => 'Certyfikat SZO (odcisk ' . ($cert['thumb'] ?? '?') . ') jest wgrany — token Exchange z certyfikatu (appidacr=' . ($cclaims['appidacr'] ?? '?') . ').'];
        } catch (Throwable $e) {
            $m = $e->getMessage();
            $st['steps']['cert'] = ['status' => 'fail', 'msg' => (preg_match('/AADSTS700027|AADSTS700024|AADSTS7000274|certificate|Key was not found/i', $m)
                ? 'Certyfikat SZO nie jest jeszcze wgrany do aplikacji w Entra ID'
                : 'Nie udało się uzyskać tokenu z certyfikatu: ' . mb_substr($m, 0, 220))
                . '. Exchange Online przyjmuje tylko tokeny z certyfikatu — sekret klienta daje 403 mimo poprawnych ról.'];
            $st['steps']['role'] = $wait;
            return $st;
        }
    } else {
        $st['steps']['perm'] = $declared === true
            ? ['status' => 'ok', 'msg' => 'Uprawnienie jest dodane w rejestracji aplikacji.']
            : ['status' => $declared === false ? 'fail' : 'info', 'msg' => $declared === false
                ? 'Rejestracja aplikacji nie zawiera Exchange.ManageAsApp.'
                : 'Nie da się odczytać rejestracji aplikacji (brak Application.Read.All) — sprawdź w portalu.'];
        $st['steps']['consent'] = ['status' => 'fail', 'msg' => 'Token Exchange nie zawiera roli Exchange.ManageAsApp — brak zgody administratora (role w tokenie: '
            . (($claims['roles'] ?? []) ? implode(', ', $claims['roles']) : 'brak') . ').'];
        $st['steps']['cert'] = $wait; $st['steps']['role'] = $wait;
        return $st;
    }

    try {
        $doms = stopkaExInvoke($tok, 'Get-AcceptedDomain');   // odczyt, którego używa też moduł aliasów
        $st['steps']['role'] = ['status' => 'ok', 'msg' => 'Aplikacja ma rolę administracyjną w Exchange (domen w organizacji: ' . count($doms) . ').'];
    } catch (Throwable $e) {
        $st['steps']['role'] = ['status' => 'fail', 'msg' => $e->getMessage()];
    }
    return $st;
}

/** Skrypt Microsoft Graph PowerShell, który robi kroki 2–4 za jednym razem (uruchamia administrator globalny). */
function stopkaExWizardScript(string $tenant, string $cid): string {
    $t = $tenant !== '' ? $tenant : '<TENANT_ID>';
    $c = $cid !== '' ? $cid : '<CLIENT_ID>';
    $exo = STOPKA_EX_RESOURCE_APP; $rid = STOPKA_EX_ROLE_ID; $adm = STOPKA_EX_ADMIN_ROLE;
    return <<<PS
# Microsoft Graph PowerShell (Install-Module Microsoft.Graph -Scope CurrentUser)
Connect-MgGraph -TenantId '$t' -Scopes 'Application.ReadWrite.All','AppRoleAssignment.ReadWrite.All','RoleManagement.ReadWrite.Directory'

\$reg = Get-MgApplication -Filter "appId eq '$c'"
\$sp  = Get-MgServicePrincipal -Filter "appId eq '$c'"
\$exo = Get-MgServicePrincipal -Filter "appId eq '$exo'"

# Krok 2: Exchange.ManageAsApp w rejestracji aplikacji (pozostałe uprawnienia zostają)
\$rra = @(\$reg.RequiredResourceAccess | ForEach-Object {
  @{ ResourceAppId = \$_.ResourceAppId; ResourceAccess = @(\$_.ResourceAccess | ForEach-Object { @{ Id = \$_.Id; Type = \$_.Type } }) } })
\$ex  = \$rra | Where-Object { \$_.ResourceAppId -eq '$exo' }
if (-not \$ex) { \$rra += @{ ResourceAppId = '$exo'; ResourceAccess = @(@{ Id = '$rid'; Type = 'Role' }) } } elseif (-not (\$ex.ResourceAccess | Where-Object { \$_.Id -eq '$rid' })) { \$ex.ResourceAccess += @{ Id = '$rid'; Type = 'Role' } }
Update-MgApplication -ApplicationId \$reg.Id -RequiredResourceAccess \$rra

# Krok 3: zgoda administratora (przypisanie roli aplikacji)
if (-not (Get-MgServicePrincipalAppRoleAssignment -ServicePrincipalId \$sp.Id | Where-Object { \$_.AppRoleId -eq '$rid' })) {
  New-MgServicePrincipalAppRoleAssignment -ServicePrincipalId \$sp.Id -PrincipalId \$sp.Id -ResourceId \$exo.Id -AppRoleId '$rid'
}

# Krok 4: rola Entra „Exchange Administrator” dla aplikacji
if (-not (Get-MgRoleManagementDirectoryRoleAssignment -Filter "principalId eq '\$(\$sp.Id)' and roleDefinitionId eq '$adm'")) {
  New-MgRoleManagementDirectoryRoleAssignment -PrincipalId \$sp.Id -RoleDefinitionId '$adm' -DirectoryScopeId '/'
}
PS;
}
