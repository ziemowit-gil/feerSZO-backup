<?php
/**
 * modules/it_control/logic/itControlSettings.php — ustawienia modułów kontroli IT.
 *
 * Trzymane w tabeli settings z prefiksem „itc_” (np. itc_report_enabled).
 * Domyślnie: sprawdzenia WŁĄCZONE (zapis historii), wszystkie WYSYŁKI WYŁĄCZONE.
 */

const ITC_DEFAULTS = [
    // Włącz/wyłącz
    'logs_enabled'        => 1,     // agent it_control_logs wykonuje kontrolę dzienników
    'ops_enabled'         => 1,     // agent it_control_ops wykonuje kontrolę ogólną
    'report_enabled'      => 0,     // okresowy raport e-mail
    'alert_enabled'       => 0,     // natychmiastowy alert przy anomalii krytycznej
    'checks_disabled'     => '[]',  // JSON: lista id wyłączonych sprawdzeń
    // Odbiorcy i harmonogram
    'recipients'          => '',    // adresy rozdzielone przecinkiem/nową linią; pusto = aktywni administratorzy
    'report_frequency'    => 'daily', // daily | weekly
    'report_weekday'      => 1,     // 1 = poniedziałek … 7 = niedziela (dla weekly)
    'report_hour'         => 7,     // od tej godziny raport może wyjść (agent tyka co godzinę)
    'report_attach'       => 1,     // dołącz pełny raport HTML jako załącznik
    // Bezpiecznik alertów
    'alert_cooldown_min'  => 360,   // ten sam zestaw krytycznych znalezisk — nie częściej niż co N min
    'alert_min_gap_min'   => 15,    // minimalny odstęp między dowolnymi alertami
    'alert_max_per_day'   => 5,     // twardy limit alertów na dobę
    // Dzienniki
    'log_window_hours'    => 24,    // okno analizy
    'log_max_bytes'       => 5242880, // ile bajtów od końca czytać z każdego pliku (5 MB)
    'log_files'           => '',    // dodatkowe ścieżki plików (po jednej w linii)
    'php_errors_warn'     => 20,    // błędów PHP w oknie → ostrzeżenie
    'php_fatal_critical'  => 10,    // fatali w oknie → krytyczne
    'login_fail_warn'     => 30,    // nieudanych logowań w oknie → ostrzeżenie
    'bruteforce_ip'       => 20,    // nieudanych logowań z jednego IP w oknie → krytyczne
    'api_denied_warn'     => 20,    // odpowiedzi 401/403/429 API w oknie → ostrzeżenie
    'mail_failed_warn'    => 5,     // nieudanych e-maili w oknie → ostrzeżenie
    'spike_factor'        => 3.0,   // ostatnia godzina > średnia godzinowa × factor …
    'spike_min'           => 20,    // … i co najmniej tyle zdarzeń → anomalia krytyczna
    'spike_baseline_days' => 7,
    // Kontrola ogólna
    'disk_warn_pct'       => 85,
    'disk_fail_pct'       => 95,
    'mailq_pending_warn'  => 50,
    'mailq_stuck_min'     => 30,    // wiadomość „pending” starsza niż N min = zatkana kolejka
    'backup_fail_factor'  => 2,     // wiek kopii > próg RPO × factor → FAIL
    'cert_warn_days'      => 30,
    'cert_fail_days'      => 7,
    'cron_heartbeat_min'  => 10,    // dyspozytor nie tyknął od N min → FAIL
    'cron_stale_factor'   => 3,     // agent nieuruchamiany > N × interwał → ostrzeżenie
    'logs_stale_hours'    => 3,     // ostatnia kontrola dzienników starsza → ostrzeżenie
    'retention_days'      => 90,
];

/** Wszystkie ustawienia (wartości z bazy nałożone na domyślne). */
function itControlSettings(): array {
    $out = ITC_DEFAULTS;
    try {
        foreach (db_all("SELECT key_, value FROM settings WHERE key_ LIKE 'itc\\_%' ESCAPE '\\'") as $r) {
            $k = substr($r['key_'], 4);
            if (!array_key_exists($k, ITC_DEFAULTS)) continue;
            $out[$k] = is_int(ITC_DEFAULTS[$k]) ? (int)$r['value']
                     : (is_float(ITC_DEFAULTS[$k]) ? (float)str_replace(',', '.', $r['value']) : (string)$r['value']);
        }
    } catch (\Throwable $e) {}
    return $out;
}

/** Zapis jednego ustawienia (z walidacją typu). Nieznane klucze są ignorowane. */
function itControlSettingSet(string $key, mixed $value): void {
    if (!array_key_exists($key, ITC_DEFAULTS)) return;
    $def = ITC_DEFAULTS[$key];
    if (is_int($def))       $value = (string)max(0, (int)$value);
    elseif (is_float($def)) $value = (string)max(0.0, (float)str_replace(',', '.', (string)$value));
    else                    $value = mb_substr(trim((string)$value), 0, 4000);
    org_setting_set('itc_' . $key, $value);
}

/** Lista wyłączonych sprawdzeń. */
function itControlDisabledChecks(array $settings): array {
    $d = json_decode((string)($settings['checks_disabled'] ?? '[]'), true);
    return is_array($d) ? array_values(array_filter($d, 'is_string')) : [];
}

/**
 * Odbiorcy wysyłek: lista z ustawień, a gdy pusta — aktywni administratorzy.
 * @return array<int,array{email:string,name:string}>
 */
function itControlRecipients(array $settings): array {
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string)$settings['recipients']) as $e) {
        $e = trim($e);
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[strtolower($e)] = ['email' => $e, 'name' => ''];
    }
    if (!$out) {
        try {
            foreach (db_all("SELECT name, email FROM users WHERE role='admin' AND is_active=1 AND email<>''") as $u) {
                if (filter_var($u['email'], FILTER_VALIDATE_EMAIL)) $out[strtolower($u['email'])] = ['email' => $u['email'], 'name' => (string)$u['name']];
            }
        } catch (\Throwable $e) {}
    }
    return array_values($out);
}
