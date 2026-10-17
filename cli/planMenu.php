<?php
/**
 * cli/planMenu.php — menu CLI do obsługi publicznego planu zajęć TI: grupy („klasy”), prowadzący, portal, DK.
 *
 * Interaktywnie:   php cli/planMenu.php
 * Polecenia:       php cli/planMenu.php grupy [szukaj]                 lista grup z publikacją / katalogiem / nazwą publiczną
 *                  php cli/planMenu.php grupa <id> publikuj|wylacz|token|katalog on|off|nazwa "<tekst>"|link
 *                  php cli/planMenu.php grupy-wszystkie [--katalog]    opublikuj plany wszystkich aktywnych grup
 *                  php cli/planMenu.php prowadzacy [szukaj]            lista prowadzących
 *                  php cli/planMenu.php prowadzacy <uid> publikuj|wylacz|token|katalog on|off|link
 *                  php cli/planMenu.php prowadzacy-wszyscy [--katalog]
 *                  php cli/planMenu.php portal                         stan portalu (Sale, DK, hasło, adresy)
 *                  php cli/planMenu.php sale on|off  |  dk on|off  |  haslo  |  haslo-off
 *                  php cli/planMenu.php dyzury                         lista dyżurów DK
 * Logika: modules/ti_plan_public/logic/planPublicPortal.php, modules/ti_duty/logic/dutyManager.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Ten skrypt można uruchomić tylko z CLI.\n"); }

$base = dirname(__DIR__);
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? '';
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/karty30.php';
require_once $base . '/modules/ti_plan_public/logic/planPublicPortal.php';
require_once $base . '/modules/ti_duty/logic/dutyManager.php';
tiPlanPublicMigrate();
tiDutyMigrate();

const PM_BY = 0;   // operacje z CLI — bez użytkownika panelu

/** Ustawienie wprost z bazy — org_setting() cache'uje wartości na całe żądanie, a menu działa długo. */
function pmSetting(string $k): string { return (string)(db_one("SELECT value FROM settings WHERE key_=?", [$k])['value'] ?? ''); }
function pmOut(string $s = ''): void { fwrite(STDOUT, $s . "\n"); }
function pmErr(string $s): void { fwrite(STDERR, $s . "\n"); }
function pmAsk(string $q): string { fwrite(STDOUT, $q); $l = fgets(STDIN); return $l === false ? '' : trim($l); }
function pmYes(string $q): bool { return in_array(mb_strtolower(pmAsk($q . ' [t/N] ')), ['t', 'tak', 'y', 'yes'], true); }
function pmPad(string $s, int $w): string { $s = mb_strimwidth($s, 0, $w, '…'); return $s . str_repeat(' ', max(0, $w - mb_strwidth($s))); }
function pmAskHidden(string $q): string {
    fwrite(STDOUT, $q);
    $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) shell_exec('stty -echo');
    $l = fgets(STDIN);
    if ($tty) { shell_exec('stty echo'); fwrite(STDOUT, "\n"); }
    return $l === false ? '' : rtrim($l, "\r\n");
}

/* ───────────── Grupy ───────────── */

function pmGroups(string $q = ''): array {
    $rows = k30_ti_courses(false);
    $links = tiPlanPublicMap(array_column($rows, 'id'));
    $out = [];
    foreach ($rows as $c) {
        if ($q !== '' && mb_stripos((string)$c['name'] . ' ' . (string)($c['group_code'] ?? ''), $q) === false) continue;
        $out[] = ['c' => $c, 'l' => $links[(int)$c['id']] ?? null];
    }
    return $out;
}

function pmListGroups(string $q = ''): void {
    $rows = pmGroups($q);
    pmOut(pmPad('ID', 6) . pmPad('Nr', 10) . pmPad('Grupa', 42) . pmPad('Plan', 6) . pmPad('Kat.', 5) . 'Publicznie jako');
    pmOut(str_repeat('─', 100));
    foreach ($rows as $r) {
        $c = $r['c']; $l = $r['l']; $on = $l && (int)$l['is_enabled'] === 1;
        $pub = tiPlanPublicGroupLabel((int)$c['id'], (string)$c['name'], (string)($l['public_name'] ?? ''), (string)($c['subject_name'] ?? ''));
        pmOut(pmPad((string)$c['id'], 6) . pmPad((string)($c['group_code'] ?? ''), 10) . pmPad((string)$c['name'], 42)
            . pmPad($on ? 'TAK' : '—', 6) . pmPad($on && (int)$l['in_directory'] ? 'TAK' : '—', 5) . ($pub !== (string)$c['name'] ? $pub . '  (RODO)' : ''));
    }
    pmOut(count($rows) . ' grup.');
}

function pmGroupAction(int $cid, string $act, string $arg = ''): bool {
    $c = db_one("SELECT id, name FROM k30_ti_courses WHERE id=?", [$cid]);
    if (!$c) { pmErr("Nie ma grupy #$cid."); return false; }
    $l = tiPlanPublicGet($cid);
    $opts = fn(array $l) => ['weeks' => (int)$l['weeks'], 'show_contacts' => (int)$l['show_contacts'], 'show_list' => (int)$l['show_list'],
                             'in_directory' => (int)($l['in_directory'] ?? 0), 'note' => (string)$l['note'], 'public_name' => (string)($l['public_name'] ?? '')];
    switch ($act) {
        case 'publikuj': $l = tiPlanPublicEnable($cid, PM_BY); pmOut('Opublikowano: ' . tiPlanPublicUrl($l)); return true;
        case 'wylacz':   tiPlanPublicDisable($cid); pmOut("Wyłączono plan grupy „{$c['name']}”."); return true;
        case 'token':    $l = tiPlanPublicRegenerate($cid, PM_BY); pmOut('Nowy adres: ' . tiPlanPublicUrl($l)); return true;
        case 'link':     pmOut($l ? tiPlanPublicUrl($l) . ((int)$l['is_enabled'] ? '' : '  (WYŁĄCZONY)') : 'Grupa nie ma linku — użyj „publikuj”.'); return true;
        case 'katalog':
            if (!in_array($arg, ['on', 'off'], true)) { pmErr('Użycie: katalog on|off'); return false; }
            if (!$l) $l = tiPlanPublicEnable($cid, PM_BY);
            tiPlanPublicUpdateOptions($cid, ['in_directory' => $arg === 'on' ? 1 : 0] + $opts($l));
            pmOut('W katalogu portalu: ' . ($arg === 'on' ? 'TAK' : 'NIE') . '.'); return true;
        case 'nazwa':
            if (!$l) $l = tiPlanPublicEnable($cid, PM_BY);
            tiPlanPublicUpdateOptions($cid, ['public_name' => $arg] + $opts($l));
            pmOut('Nazwa publiczna: ' . ($arg !== '' ? tiPlanPublicAnonymize($arg) : '(nazwa grupy)')); return true;
    }
    pmErr("Nieznana akcja „{$act}” (publikuj, wylacz, token, link, katalog on|off, nazwa \"…\")."); return false;
}

/* ───────────── Prowadzący ───────────── */

function pmListInstr(string $q = ''): void {
    $map = tiPlanPublicInstrMap();
    pmOut(pmPad('UID', 6) . pmPad('Prowadzący', 34) . pmPad('Grup', 6) . pmPad('Plan', 6) . 'Kat.');
    pmOut(str_repeat('─', 60));
    $n = 0;
    foreach (tiPlanPublicInstructors() as $u) {
        if ($q !== '' && mb_stripos((string)$u['name'], $q) === false) continue;
        $l = $map[(int)$u['id']] ?? null; $on = $l && (int)$l['is_enabled'] === 1;
        pmOut(pmPad((string)$u['id'], 6) . pmPad((string)$u['name'], 34) . pmPad((string)$u['n_courses'], 6) . pmPad($on ? 'TAK' : '—', 6) . ($on && (int)$l['in_directory'] ? 'TAK' : '—'));
        $n++;
    }
    pmOut("$n prowadzących.");
}

function pmInstrAction(int $uid, string $act, string $arg = ''): bool {
    $u = db_one("SELECT id, name FROM users WHERE id=?", [$uid]);
    if (!$u) { pmErr("Nie ma użytkownika #$uid."); return false; }
    $l = tiPlanPublicInstrGet($uid);
    switch ($act) {
        case 'publikuj': $l = tiPlanPublicInstrEnable($uid, PM_BY); pmOut('Opublikowano: ' . tiPlanPublicInstrUrl($l)); return true;
        case 'wylacz':   tiPlanPublicInstrDisable($uid); pmOut("Wyłączono plan prowadzącego {$u['name']}."); return true;
        case 'token':    $l = tiPlanPublicInstrRegenerate($uid, PM_BY); pmOut('Nowy adres: ' . tiPlanPublicInstrUrl($l)); return true;
        case 'link':     pmOut($l ? tiPlanPublicInstrUrl($l) . ((int)$l['is_enabled'] ? '' : '  (WYŁĄCZONY)') : 'Brak linku — użyj „publikuj”.'); return true;
        case 'katalog':
            if (!in_array($arg, ['on', 'off'], true)) { pmErr('Użycie: katalog on|off'); return false; }
            if (!$l) $l = tiPlanPublicInstrEnable($uid, PM_BY);
            tiPlanPublicInstrUpdateOptions($uid, ['weeks' => (int)$l['weeks'], 'note' => (string)$l['note'], 'in_directory' => $arg === 'on' ? 1 : 0]);
            pmOut('W katalogu portalu: ' . ($arg === 'on' ? 'TAK' : 'NIE') . '.'); return true;
    }
    pmErr("Nieznana akcja „{$act}” (publikuj, wylacz, token, link, katalog on|off)."); return false;
}

/* ───────────── Portal i DK ───────────── */

function pmPortal(): void {
    pmOut('Adres portalu:      ' . rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/karty30/ti/dydaktyk/planPubliczny.php   (krótko: https://<ti|szo|kursant>.…/plan/)');
    $g = count(tiPlanPublicDirectoryGroups()); $i = count(tiPlanPublicDirectoryInstructors());
    pmOut("Grupy w katalogu:   $g");
    pmOut("Prowadzący w kat.:  $i");
    pmOut('Zakładka Sale:      ' . (pmSetting('ti_plan_public_rooms') === '1' ? 'włączona' : 'wyłączona'));
    pmOut('Zakładka DK:        ' . (pmSetting('ti_duty_public') === '1' ? 'opublikowana' : 'ukryta'));
    pmOut('Widok pełny:        ' . (pmSetting('ti_plan_public_full_hash') !== '' ? 'włączony (hasło ustawione, logowanie 90 dni)' : 'wyłączony'));
}

function pmSetPassword(): bool {
    $p1 = pmAskHidden('Nowe hasło do pełnego planu (min. 10 znaków): ');
    if (mb_strlen($p1) < 10) { pmErr('Za krótkie.'); return false; }
    if (pmAskHidden('Powtórz: ') !== $p1) { pmErr('Hasła się różnią.'); return false; }
    tiPlanPublicFullSetPassword($p1);
    pmOut('Hasło ustawione — poprzednie zalogowania wygasły.'); return true;
}

function pmDuties(): void {
    $rows = tiDutyAll();
    if (!$rows) { pmOut('Brak dyżurów DK.'); return; }
    foreach ($rows as $r) {
        $when = $r['kind'] === 'weekly'
            ? 'co tydz. ' . (TI_DAYS_PL_FULL[(int)$r['dow']] ?? '') . (($r['valid_from'] || $r['valid_to']) ? ' (' . ($r['valid_from'] ?: '…') . ' – ' . ($r['valid_to'] ?: '…') . ')' : '')
            : (string)$r['duty_date'];
        pmOut(pmPad('#' . $r['id'], 6) . pmPad($when, 34) . ((int)$r['is_cancelled'] ? 'BRAK DYŻURU' : pmPad($r['time_from'] . '–' . $r['time_to'], 13) . $r['person_name'] . ($r['role_label'] ? ' — ' . $r['role_label'] : '') . ($r['place'] ? ', ' . $r['place'] : '')));
    }
}

function pmBulkGroups(bool $dir): void { [$n, $all] = tiPlanPublicEnableAll(PM_BY, $dir); pmOut("Opublikowano nowych: $n, aktywnych grup łącznie: $all" . ($dir ? ' — wszystkie w katalogu.' : '.')); }
function pmBulkInstr(bool $dir): void { [$n, $all] = tiPlanPublicInstrEnableAll(PM_BY, $dir); pmOut("Opublikowano nowych: $n, prowadzących łącznie: $all" . ($dir ? ' — wszyscy w katalogu.' : '.')); }

/* ───────────── Polecenia z argumentów ───────────── */

$a = array_slice($argv, 1);
if ($a) {
    $cmd = mb_strtolower((string)$a[0]);
    $ok = true;
    switch ($cmd) {
        case 'grupy':              pmListGroups((string)($a[1] ?? '')); break;
        case 'grupa':              $ok = pmGroupAction((int)($a[1] ?? 0), (string)($a[2] ?? ''), (string)($a[3] ?? '')); break;
        case 'grupy-wszystkie':    pmBulkGroups(in_array('--katalog', $a, true)); break;
        case 'prowadzacy':         if (isset($a[1]) && ctype_digit((string)$a[1])) $ok = pmInstrAction((int)$a[1], (string)($a[2] ?? ''), (string)($a[3] ?? '')); else pmListInstr((string)($a[1] ?? '')); break;
        case 'prowadzacy-wszyscy': pmBulkInstr(in_array('--katalog', $a, true)); break;
        case 'portal':             pmPortal(); break;
        case 'sale':               org_setting_set('ti_plan_public_rooms', ($a[1] ?? '') === 'on' ? '1' : '0'); pmOut('Zakładka Sale: ' . (($a[1] ?? '') === 'on' ? 'włączona' : 'wyłączona')); break;
        case 'dk':                 org_setting_set('ti_duty_public', ($a[1] ?? '') === 'on' ? '1' : '0'); pmOut('Zakładka DK: ' . (($a[1] ?? '') === 'on' ? 'opublikowana' : 'ukryta')); break;
        case 'haslo':              $ok = pmSetPassword(); break;
        case 'haslo-off':          tiPlanPublicFullSetPassword(''); pmOut('Widok pełny wyłączony.'); break;
        case 'dyzury':             pmDuties(); break;
        default: pmErr("Nieznane polecenie „{$cmd}”. Bez argumentów uruchamia się menu; lista poleceń w nagłówku pliku."); $ok = false;
    }
    exit($ok ? 0 : 1);
}

/* ───────────── Menu interaktywne ───────────── */

while (true) {
    pmOut();
    pmOut('══ Plan zajęć TI — menu ══');
    pmOut(' 1) Grupy: lista            2) Grupa: zarządzaj          3) Opublikuj wszystkie grupy');
    pmOut(' 4) Prowadzący: lista       5) Prowadzący: zarządzaj     6) Opublikuj wszystkich prowadzących');
    pmOut(' 7) Stan portalu            8) Sale: włącz/wyłącz        9) DK: opublikuj/ukryj');
    pmOut('10) Hasło pełnego planu    11) Dyżury DK: lista           0) Wyjście');
    $ch = pmAsk('Wybór: ');
    switch ($ch) {
        case '1': pmListGroups(pmAsk('Szukaj (Enter = wszystkie): ')); break;
        case '2':
            $cid = (int)pmAsk('ID grupy: ');
            pmOut('Akcje: publikuj | wylacz | token | link | katalog on | katalog off | nazwa <tekst>');
            $parts = explode(' ', pmAsk('Akcja: '), 2);
            $act = (string)$parts[0]; $arg = (string)($parts[1] ?? '');
            if (in_array($act, ['token', 'wylacz'], true) && !pmYes('Na pewno? Dotychczasowy link ' . ($act === 'token' ? 'przestanie działać' : 'zostanie wyłączony') . '.')) break;
            pmGroupAction($cid, $act, $arg);
            break;
        case '3': if (pmYes('Opublikować plany WSZYSTKICH aktywnych grup?')) pmBulkGroups(pmYes('Także w katalogu portalu (nazwy grup publicznie, z ochroną RODO)?')); break;
        case '4': pmListInstr(pmAsk('Szukaj (Enter = wszyscy): ')); break;
        case '5':
            $uid = (int)pmAsk('UID prowadzącego: ');
            pmOut('Akcje: publikuj | wylacz | token | link | katalog on | katalog off');
            $parts = explode(' ', pmAsk('Akcja: '), 2);
            if (in_array($parts[0], ['token', 'wylacz'], true) && !pmYes('Na pewno?')) break;
            pmInstrAction($uid, (string)$parts[0], (string)($parts[1] ?? ''));
            break;
        case '6': if (pmYes('Opublikować plany WSZYSTKICH prowadzących?')) pmBulkInstr(pmYes('Także w katalogu portalu?')); break;
        case '7': pmPortal(); break;
        case '8': $on = pmSetting('ti_plan_public_rooms') !== '1'; org_setting_set('ti_plan_public_rooms', $on ? '1' : '0'); pmOut('Zakładka Sale: ' . ($on ? 'włączona' : 'wyłączona')); break;
        case '9': $on = pmSetting('ti_duty_public') !== '1'; org_setting_set('ti_duty_public', $on ? '1' : '0'); pmOut('Zakładka DK: ' . ($on ? 'opublikowana' : 'ukryta')); break;
        case '10':
            if (pmSetting('ti_plan_public_full_hash') !== '' && pmYes('Wyłączyć widok pełny (zamiast zmiany hasła)?')) { tiPlanPublicFullSetPassword(''); pmOut('Wyłączono.'); break; }
            pmSetPassword();
            break;
        case '11': pmDuties(); break;
        case '0': case 'q': case '': exit(0);
        default: pmOut('Nieznana opcja.');
    }
}
