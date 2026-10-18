<?php
/**
 * modules/stopka/logic/stopkaExchange.php — automatyczna stopka w Outlooku (klasyczny, web, telefony).
 *
 * Mechanizm: JEDNA reguła transportowa Exchange Online (HTML disclaimer, „Append"), która
 * dopisuje stopkę do każdej wiadomości wysłanej z organizacji — po stronie serwera, więc
 * działa w każdym kliencie, także w Outlooku w przeglądarce. Dane osoby Exchange podstawia
 * sam z Entra ID (%%DisplayName%%, %%Title%%, %%MobilePhone%% …); SZO utrzymuje tylko
 * wspólny szablon (dane organizacji + treść promocyjna) i wypycha go do reguły.
 *
 * Konsekwencja: stanowisko/telefon w stopce biorą się z Entra ID, nie z stopka_profile
 * (ten ręczny profil służy tylko do podglądu i ręcznego kopiowania).
 *
 * Wywołania idą REST-em „InvokeCommand" Exchange Online (to samo, czym mówi moduł
 * ExchangeOnlineManagement) z tokenem aplikacji (client credentials) zasobu
 * https://outlook.office365.com. Wymaga w Entra ID: uprawnienia aplikacji
 * Office 365 Exchange Online → Exchange.ManageAsApp (z zgodą admina) ORAZ roli
 * „Exchange Administrator" (lub „Transport Hygiene") przypisanej tej aplikacji.
 *
 * Reguła startuje w trybie Audit (nic nie zmienia w wiadomościach, tylko loguje) —
 * administrator przełącza na Enforce po obejrzeniu podglądu.
 */

const STOPKA_EX_RULE      = 'SZO - stopka e-mail';
const STOPKA_EX_MARK_HDR  = 'X-SZO-Stopka';
const STOPKA_EX_MAX_CHARS = 5000; // limit Exchange dla ApplyHtmlDisclaimerText

function stopkaExMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    stopkaMigrate();
    foreach ([
        'ex_enabled'    => "ALTER TABLE stopka_settings ADD COLUMN ex_enabled INTEGER NOT NULL DEFAULT 0",
        'ex_mode'       => "ALTER TABLE stopka_settings ADD COLUMN ex_mode TEXT NOT NULL DEFAULT 'Audit'",
        'ex_phone_attr' => "ALTER TABLE stopka_settings ADD COLUMN ex_phone_attr TEXT NOT NULL DEFAULT 'MobilePhone'",
        'ex_last_sync'  => "ALTER TABLE stopka_settings ADD COLUMN ex_last_sync TEXT NOT NULL DEFAULT ''",
        'ex_last_msg'   => "ALTER TABLE stopka_settings ADD COLUMN ex_last_msg TEXT NOT NULL DEFAULT ''",
    ] as $col => $sql) {
        try { db()->exec($sql); } catch (\Throwable $e) { /* kolumna już jest */ }
    }
}

function stopkaExSettings(): array {
    return db_one("SELECT * FROM stopka_settings WHERE id=1") ?? [];
}

/** Szablon HTML dla Exchange: placeholdery %%…%% zamiast danych osoby. Wygląd = konfigurator. */
function stopkaExBuildHtml(array $s): string {
    $ph = in_array($s['ex_phone_attr'] ?? '', ['MobilePhone', 'PhoneNumber'], true) ? $s['ex_phone_attr'] : 'MobilePhone';
    return stopkaRenderHtml(stopkaSettings(), [
        'name' => '%%DisplayName%%', 'title' => '%%Title%%', 'phone' => '%%' . $ph . '%%', 'email' => '%%Email%%',
    ]);
}

/** Podgląd szablonu z podstawionymi danymi osoby (placeholdery → wartości). */
function stopkaExPreviewHtml(array $s, array $me): string {
    $map = [
        '%%DisplayName%%' => trim($me['first_name'] . ' ' . $me['last_name']),
        '%%Title%%'       => $me['job_title'],
        '%%MobilePhone%%' => $me['phone'],
        '%%PhoneNumber%%' => $me['phone'],
        '%%Email%%'       => $me['email'],
    ];
    foreach ($map as $k => $v) $map[$k] = htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return strtr(stopkaExBuildHtml($s), $map);
}

/** Token aplikacji (client credentials) dla zasobu $resource. Zwraca [token, claims]; NIE sprawdza ról. */
function stopkaExAppToken(string $resource): array {
    $tenant = m365_setting('m365_tenant_id') ?: (defined('MS_TENANT_ID') ? MS_TENANT_ID : '');
    $cid    = m365_setting('m365_graph_client_id');
    $sec    = m365_setting('m365_graph_client_secret');
    if ($tenant === '' || $cid === '' || $sec === '') throw new RuntimeException('Microsoft 365 (Graph) nie jest skonfigurowany.');
    $r = stopkaExHttp('POST', "https://login.microsoftonline.com/$tenant/oauth2/v2.0/token", [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'grant_type' => 'client_credentials', 'client_id' => $cid, 'client_secret' => $sec,
        'scope' => $resource . '/.default',
    ]));
    if (empty($r['access_token'])) {
        throw new RuntimeException('Token ' . $resource . ': ' . ($r['error_description'] ?? $r['error'] ?? 'brak odpowiedzi'));
    }
    return [$r['access_token'], M365Graph::decode_jwt($r['access_token'])];
}

function stopkaExToken(): string {
    try {
        [$tok, $claims] = stopkaExAppToken('https://outlook.office365.com');
    } catch (RuntimeException $e) {
        throw new RuntimeException($e->getMessage() . ' — dodaj aplikacji uprawnienie Exchange.ManageAsApp.');
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

/** Wywołuje cmdlet Exchange Online. Zwraca listę wyników; rzuca wyjątek z treścią błędu. */
function stopkaExInvoke(string $token, string $cmdlet, array $params = []): array {
    $tenant = m365_setting('m365_tenant_id') ?: (defined('MS_TENANT_ID') ? MS_TENANT_ID : '');
    $r = stopkaExHttp('POST', "https://outlook.office365.com/adminapi/beta/$tenant/InvokeCommand", [
        'Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json;odata.metadata=minimal',
        'X-ResponseFormat: json', 'X-CmdletName: ' . $cmdlet,
        'X-AnchorMailbox: APP:SystemMailbox{bb558c35-97f1-4cb9-8ff7-d53741dc928c}@' . $tenant,
    ], json_encode(['CmdletInput' => ['CmdletName' => $cmdlet, 'Parameters' => (object)$params]], JSON_UNESCAPED_UNICODE));
    if (!empty($r['error'])) {
        $m = $r['error']['message'] ?? ($r['error']['code'] ?? 'błąd');
        throw new RuntimeException("$cmdlet: " . (is_string($m) ? $m : json_encode($m, JSON_UNESCAPED_UNICODE)));
    }
    return $r['value'] ?? [];
}

/** Tworzy/aktualizuje regułę. Zwraca [komunikat, czy_błąd]. Zapisuje wynik w ex_last_*. */
function stopkaExPush(): array {
    stopkaExMigrate();
    $s = stopkaExSettings();
    try {
        $html = stopkaExBuildHtml($s);
        if (mb_strlen($html) > STOPKA_EX_MAX_CHARS) {
            throw new RuntimeException('Szablon ma ' . mb_strlen($html) . ' znaków; Exchange dopuszcza ' . STOPKA_EX_MAX_CHARS . '. Skróć treść promocyjną.');
        }
        $mode = ($s['ex_mode'] ?? 'Audit') === 'Enforce' ? 'Enforce' : 'Audit';
        $tok  = stopkaExToken();
        $params = [
            'ApplyHtmlDisclaimerLocation'       => 'Append',
            'ApplyHtmlDisclaimerText'           => $html,
            'ApplyHtmlDisclaimerFallbackAction' => 'Wrap',
            'FromScope'                         => 'InOrganization',
            // znacznik + wyjątek: wiadomość już ostemplowana (odpowiedź w wątku) nie dostaje drugiej stopki
            'SetHeaderName'                     => STOPKA_EX_MARK_HDR,
            'SetHeaderValue'                    => '1',
            'ExceptIfHeaderMatchesMessageHeader' => STOPKA_EX_MARK_HDR,
            'ExceptIfHeaderMatchesPatterns'     => ['1'],
            'Mode'                              => $mode,
            'Comments'                          => 'Zarządzane przez SZO (modules/stopka). Nie edytuj ręcznie.',
        ];
        $exists = false;
        try { $exists = (bool)stopkaExInvoke($tok, 'Get-TransportRule', ['Identity' => STOPKA_EX_RULE]); } catch (RuntimeException $e) { $exists = false; }
        if ($exists) {
            stopkaExInvoke($tok, 'Set-TransportRule', ['Identity' => STOPKA_EX_RULE] + $params);
        } else {
            stopkaExInvoke($tok, 'New-TransportRule', ['Name' => STOPKA_EX_RULE, 'Enabled' => true] + $params);
        }
        $msg = 'Reguła „' . STOPKA_EX_RULE . '” ' . ($exists ? 'zaktualizowana' : 'utworzona') . ' (tryb: ' . $mode . ').';
        $err = false;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $err = true;
    }
    db_exec("UPDATE stopka_settings SET ex_last_sync=?, ex_last_msg=? WHERE id=1",
        [date('Y-m-d H:i:s'), ($err ? 'BŁĄD: ' : 'OK: ') . $msg]);
    return [$msg, $err];
}


/**
 * Diagnostyka uprawnień: lista [etykieta, ok(true|false|null=info), szczegóły/podpowiedź].
 * Niczego nie zmienia w Exchange (tylko odczyty).
 */
function stopkaExCheck(): array {
    $out = [];
    $tenant = m365_setting('m365_tenant_id') ?: (defined('MS_TENANT_ID') ? MS_TENANT_ID : '');
    $cid    = m365_setting('m365_graph_client_id');
    $cfgOk  = $tenant !== '' && $cid !== '' && m365_setting('m365_graph_client_secret') !== '';
    $out[] = ['Konfiguracja aplikacji Graph (tenant, client id, secret)', $cfgOk,
        $cfgOk ? 'Aplikacja: ' . $cid : 'Uzupełnij dane aplikacji w ustawieniach Microsoft 365 SZO.'];
    if (!$cfgOk) return $out;

    // Graph: User.Read.All (synchronizacja stanowiska/telefonu z Entra ID)
    try {
        $r = stopkaExHttp('POST', "https://login.microsoftonline.com/$tenant/oauth2/v2.0/token", ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type' => 'client_credentials', 'client_id' => $cid, 'client_secret' => m365_setting('m365_graph_client_secret'), 'scope' => 'https://graph.microsoft.com/.default']));
        $roles = M365Graph::decode_jwt($r['access_token'] ?? '')['roles'] ?? [];
        $ok = in_array('User.Read.All', $roles, true) || in_array('Directory.Read.All', $roles, true);
        $out[] = ['Graph: uprawnienie User.Read.All (odczyt profili)', $ok,
            $ok ? 'Jest.' : 'Brak. Entra ID → Rejestracje aplikacji → Uprawnienia API → Microsoft Graph → Uprawnienia aplikacji → User.Read.All + zgoda administratora. (Dotyczy tylko przycisku „Synchronizuj z M365”.)'];
    } catch (Throwable $e) {
        $out[] = ['Graph: token aplikacji', false, $e->getMessage()];
    }

    // Exchange: token i rola aplikacji
    try {
        $tok = stopkaExToken();
    } catch (Throwable $e) {
        $out[] = ['Exchange Online: token aplikacji', false, $e->getMessage()];
        return $out;
    }
    $roles = M365Graph::decode_jwt($tok)['roles'] ?? [];
    $hasRole = in_array('Exchange.ManageAsApp', $roles, true);
    $out[] = ['Exchange: uprawnienie aplikacji Exchange.ManageAsApp', $hasRole,
        $hasRole ? 'Jest w tokenie.' : 'Brak. Uprawnienia API → Dodaj uprawnienie → Office 365 Exchange Online → Uprawnienia aplikacji → Exchange.ManageAsApp, potem zgoda administratora.'];

    // Exchange: rola administracyjna (próba odczytu reguł)
    try {
        $rules = stopkaExInvoke($tok, 'Get-TransportRule');
        $out[] = ['Exchange: rola administracyjna aplikacji (odczyt reguł transportowych)', true, 'Odczyt działa (reguł w organizacji: ' . count($rules) . ').'];
        $mine = null;
        foreach ($rules as $r) { if (($r['Name'] ?? '') === STOPKA_EX_RULE) { $mine = $r; break; } }
        $out[] = ['Reguła „' . STOPKA_EX_RULE . '”', null, $mine
            ? 'Istnieje — stan: ' . ($mine['State'] ?? '?') . ', tryb: ' . ($mine['Mode'] ?? '?') . '.'
            : 'Jeszcze nie utworzona (powstanie przy pierwszym „Zapisz i wypchnij”).'];
    } catch (Throwable $e) {
        $out[] = ['Exchange: rola administracyjna aplikacji', false,
            $e->getMessage() . ' — przypisz aplikacji (Enterprise application o tej nazwie) rolę „Exchange Administrator” w Entra ID → Role i administratorzy.'];
    }
    return $out;
}

// ── Kreator konfiguracji Exchange (graficzny, panel administratora) ──────────────────────

const STOPKA_EX_RESOURCE_APP = '00000002-0000-0ff1-ce00-000000000000';   // Office 365 Exchange Online
const STOPKA_EX_ROLE_ID      = 'dc50a0fb-09a3-484d-be87-e023b12c6440';   // Exchange.ManageAsApp (appRole)
const STOPKA_EX_ADMIN_ROLE   = '29232cdf-9323-42fd-ade2-1d097af3e4de';   // Entra: Exchange Administrator

/**
 * Stan kreatora: dane aplikacji, linki do Entra ID, skrypt PowerShell i status kroków
 * (app, perm, consent, role, rule) — każdy: status ok|fail|wait|info + komunikat.
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
    ], 'steps' => []];
    $st['script'] = stopkaExWizardScript($tenant, $cid);
    $wait = ['status' => 'wait', 'msg' => 'Najpierw dokończ poprzedni krok.'];
    if (!$probe) {
        foreach (['app', 'perm', 'consent', 'role', 'rule'] as $k) $st['steps'][$k] = ['status' => 'loading', 'msg' => 'Sprawdzanie…'];
        return $st;
    }

    $appOk = $tenant !== '' && $cid !== '' && $hasSec;
    $st['steps']['app'] = $appOk
        ? ['status' => 'ok', 'msg' => 'Aplikacja ' . $cid . ' w dzierżawie ' . $tenant . '.']
        : ['status' => 'fail', 'msg' => 'Brakuje: ' . implode(', ', array_keys(array_filter(['identyfikatora dzierżawy' => $tenant === '', 'Client ID' => $cid === '', 'sekretu' => !$hasSec]))) . '. Uzupełnij je w ustawieniach Microsoft 365 SZO.'];
    if (!$appOk) {
        foreach (['perm', 'consent', 'role', 'rule'] as $k) $st['steps'][$k] = $wait;
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
        $st['steps']['consent'] = $wait; $st['steps']['role'] = $wait; $st['steps']['rule'] = $wait;
        return $st;
    }
    if ($consented) {
        $st['steps']['perm']    = ['status' => 'ok', 'msg' => 'Uprawnienie Exchange.ManageAsApp jest przypisane aplikacji.'];
        $st['steps']['consent'] = ['status' => 'ok', 'msg' => 'Zgoda administratora udzielona — rola jest w tokenie.'];
    } else {
        $st['steps']['perm'] = $declared === true
            ? ['status' => 'ok', 'msg' => 'Uprawnienie jest dodane w rejestracji aplikacji.']
            : ['status' => $declared === false ? 'fail' : 'info', 'msg' => $declared === false
                ? 'Rejestracja aplikacji nie zawiera Exchange.ManageAsApp.'
                : 'Nie da się odczytać rejestracji aplikacji (brak Application.Read.All) — sprawdź w portalu.'];
        $st['steps']['consent'] = ['status' => 'fail', 'msg' => 'Token Exchange nie zawiera roli Exchange.ManageAsApp — brak zgody administratora (role w tokenie: '
            . (($claims['roles'] ?? []) ? implode(', ', $claims['roles']) : 'brak') . ').'];
        $st['steps']['role'] = $wait; $st['steps']['rule'] = $wait;
        return $st;
    }

    try {
        $rules = stopkaExInvoke($tok, 'Get-TransportRule');
        $st['steps']['role'] = ['status' => 'ok', 'msg' => 'Aplikacja ma rolę administracyjną w Exchange (reguł transportowych: ' . count($rules) . ').'];
        $mine = null;
        foreach ($rules as $r) { if (($r['Name'] ?? '') === STOPKA_EX_RULE) { $mine = $r; break; } }
        $st['steps']['rule'] = $mine
            ? ['status' => 'ok', 'msg' => 'Reguła „' . STOPKA_EX_RULE . '” istnieje — stan: ' . ($mine['State'] ?? '?') . ', tryb: ' . ($mine['Mode'] ?? '?') . '.']
            : ['status' => 'info', 'msg' => 'Uprawnienia gotowe. Reguła powstanie po „Zapisz i wypchnij do Exchange”.'];
    } catch (Throwable $e) {
        $st['steps']['role'] = ['status' => 'fail', 'msg' => $e->getMessage()];
        $st['steps']['rule'] = $wait;
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
if (-not \$ex) { \$rra += @{ ResourceAppId = '$exo'; ResourceAccess = @(@{ Id = '$rid'; Type = 'Role' }) } }
elseif (-not (\$ex.ResourceAccess | Where-Object { \$_.Id -eq '$rid' })) { \$ex.ResourceAccess += @{ Id = '$rid'; Type = 'Role' } }
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
