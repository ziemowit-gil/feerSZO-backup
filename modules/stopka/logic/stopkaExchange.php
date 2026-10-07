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

/** Szablon HTML dla Exchange: placeholdery %%…%% zamiast danych osoby, style inline. */
function stopkaExBuildHtml(array $s): string {
    $e   = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $ph  = in_array($s['ex_phone_attr'] ?? '', ['MobilePhone', 'PhoneNumber'], true) ? $s['ex_phone_attr'] : 'MobilePhone';
    $row = fn(string $t, string $st) => $t === '' ? '' : '<div style="' . $st . '">' . $t . '</div>';
    $url = trim((string)($s['website'] ?? ''));
    $href = $url === '' ? '' : (preg_match('~^https?://~i', $url) ? $url : 'https://' . $url);

    $h  = '<div style="font-family:Arial,Helvetica,sans-serif;color:#222;font-size:13px;line-height:1.5;margin-top:12px;">';
    $h .= $row('%%DisplayName%%', 'font-size:16px;font-weight:bold;line-height:1.4;');
    $h .= $row('%%Title%%', 'font-size:13px;');
    $h .= $row('tel. %%' . $ph . '%% &nbsp;|&nbsp; <a href="mailto:%%Email%%" style="color:#1d4ed8;text-decoration:none;">%%Email%%</a>', 'font-size:13px;');
    $h .= $row($e($s['org_name'] ?? ''), 'margin-top:8px;font-size:13px;font-weight:bold;');
    $h .= $row($e($s['address'] ?? ''), 'font-size:12px;');
    if (trim((string)($s['nip'] ?? '')) !== '') $h .= $row('NIP: ' . $e($s['nip']), 'font-size:12px;');
    if (trim((string)($s['krs'] ?? '')) !== '') $h .= $row('KRS: ' . $e($s['krs']), 'font-size:12px;');
    if ($href !== '') $h .= $row('<a href="' . $e($href) . '" style="color:#1d4ed8;text-decoration:none;">' . $e($url) . '</a>', 'font-size:12px;');
    $promo = trim((string)($s['promo_html'] ?? ''));
    if ($promo !== '') {
        $h .= '<div style="margin-top:10px;padding-top:8px;border-top:1px solid #999;font-size:12px;line-height:1.5;color:#444;">' . $promo . '</div>';
    }
    return $h . '</div>';
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

function stopkaExToken(): string {
    $tenant = m365_setting('m365_tenant_id') ?: (defined('MS_TENANT_ID') ? MS_TENANT_ID : '');
    $cid    = m365_setting('m365_graph_client_id');
    $sec    = m365_setting('m365_graph_client_secret');
    if ($tenant === '' || $cid === '' || $sec === '') throw new RuntimeException('Microsoft 365 (Graph) nie jest skonfigurowany.');
    $r = stopkaExHttp('POST', "https://login.microsoftonline.com/$tenant/oauth2/v2.0/token", [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'grant_type' => 'client_credentials', 'client_id' => $cid, 'client_secret' => $sec,
        'scope' => 'https://outlook.office365.com/.default',
    ]));
    if (empty($r['access_token'])) {
        throw new RuntimeException('Token Exchange Online: ' . ($r['error_description'] ?? $r['error'] ?? 'brak odpowiedzi')
            . ' — dodaj aplikacji uprawnienie Exchange.ManageAsApp.');
    }
    return $r['access_token'];
}

function stopkaExHttp(string $method, string $url, array $headers, string $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body]);
    $out = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($out === false) throw new RuntimeException('Błąd połączenia: ' . $err);
    $j = json_decode($out, true);
    if (!is_array($j)) throw new RuntimeException("Exchange zwrócił HTTP $code bez JSON.");
    if ($code >= 400 && empty($j['error'])) $j['error'] = ['message' => "HTTP $code"];
    return $j;
}

/** Wywołuje cmdlet Exchange Online. Zwraca listę wyników; rzuca wyjątek z treścią błędu. */
function stopkaExInvoke(string $token, string $cmdlet, array $params = []): array {
    $tenant = m365_setting('m365_tenant_id') ?: (defined('MS_TENANT_ID') ? MS_TENANT_ID : '');
    $r = stopkaExHttp('POST', "https://outlook.office365.com/adminapi/beta/$tenant/InvokeCommand", [
        'Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json',
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
